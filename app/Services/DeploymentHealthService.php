<?php

namespace App\Services;

use App\Jobs\ProcessMemoryProjection;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

class DeploymentHealthService
{
    public const SCHEDULER_HEARTBEAT_KEY = 'luczor:operations:scheduler-heartbeat';

    public function __construct(
        private readonly RedisHostKernelInspector $redisKernel,
    ) {}

    /** @return array<string, bool> */
    public function checks(
        bool $enforceProduction = false,
        bool $includeRuntime = true,
        bool $probeReverbServer = false,
    ): array {
        $strict = $enforceProduction || app()->environment('production');
        $checks = [];

        if ($strict) {
            $checks = [
                'debug_disabled' => ! (bool) config('app.debug'),
                'https_app_url' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https',
                'secure_session_cookie' => (bool) config('session.secure'),
                'cors_origins_restricted' => $this->originsAreRestricted((array) config('cors.allowed_origins', [])),
                'cache_uses_redis' => config('cache.default') === 'redis',
                'queue_uses_redis' => config('queue.default') === 'redis',
                'redis_auth_configured' => $this->redisAuthenticationIsConfigured(),
                'redis_endpoint_private' => $this->redisEndpointIsPrivate(),
                'horizon_handles_notification_queue' => $this->horizonHandlesNotificationQueue(),
                'reverb_configured' => $this->reverbConfigured(),
                'memory_namespace_key_configured' => $this->memoryNamespaceKeyIsSafe(),
                'memory_ledger_key_configured' => $this->memoryLedgerKeyIsSafe(),
                'cognee_projection_timeout_budget' => $this->cogneeProjectionTimeoutBudgetIsSafe(),
            ];
        }

        if ($includeRuntime) {
            $checks['database'] = $this->databaseAvailable();

            if ($strict) {
                $checks['memory_ledger_identities_hardened'] = $this->memoryLedgerIdentitiesAreHardened();
                $checks['migrations_current'] = $this->migrationsCurrent();
                $checks['redis'] = $this->redisAvailable();
                $checks['redis_host_overcommit_memory'] = $this->redisKernel->overcommitMemoryEnabled();
                $checks['horizon'] = $this->horizonRunning();
                $checks['scheduler'] = $this->schedulerRunning();

                if ($probeReverbServer) {
                    $checks['reverb_server'] = $this->reverbServerReachable();
                }
            }
        }

        return $checks;
    }

    private function originsAreRestricted(array $origins): bool
    {
        return $origins !== []
            && collect($origins)->every(fn ($origin) => is_string($origin) && trim($origin) !== '' && trim($origin) !== '*');
    }

    private function horizonHandlesNotificationQueue(): bool
    {
        $queues = (array) config('horizon.defaults.supervisor-1.queue', []);
        $notificationQueue = (string) config('luczor.notifications.queue', 'default');

        return in_array($notificationQueue, $queues, true);
    }

    private function reverbConfigured(): bool
    {
        $connection = (array) config('broadcasting.connections.reverb', []);
        $app = (array) config('reverb.apps.apps.0', []);
        $options = (array) ($connection['options'] ?? []);

        $host = $options['host'] ?? null;
        $port = (int) ($options['port'] ?? 0);
        $scheme = strtolower((string) ($options['scheme'] ?? ''));
        $internalHost = (string) config('luczor.realtime.internal_host', '');
        $transportIsAccepted = $scheme === 'https'
            || ($scheme === 'http'
                && (bool) config('luczor.realtime.allow_internal_http')
                && $internalHost !== ''
                && hash_equals($internalHost, (string) $host));

        return config('broadcasting.default') === 'reverb'
            && $this->credentialIsConfigured($connection['key'] ?? null)
            && $this->credentialIsConfigured($connection['secret'] ?? null)
            && $this->filled($connection['app_id'] ?? null)
            && $this->filled($host)
            && ! in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1'], true)
            && $port >= 1
            && $port <= 65_535
            && $transportIsAccepted
            && $this->originsAreRestricted((array) ($app['allowed_origins'] ?? []));
    }

    private function cogneeProjectionTimeoutBudgetIsSafe(): bool
    {
        $dataTimeout = (int) config('luczor.cognee.timeout', 45);
        $controlTimeout = (int) config('luczor.cognee.control_timeout', 8);
        $ackTimeout = (int) config('luczor.cognee.ack_timeout', 3);
        $contentLockSeconds = (int) config('luczor.cognee.content_lock_seconds', 120);
        $jobTimeout = (new ProcessMemoryProjection(0))->timeout;
        $retryAfter = (int) config('queue.connections.redis.retry_after', 90);

        // Worst Add recovery: exact lookup + Add + runtime probe + Cognify,
        // with up to three durable Ack calls. Five seconds are reserved for
        // SQL/queue/PHP overhead rather than treating socket timeouts as the
        // whole job budget.
        $overheadReserve = 5;

        return min($dataTimeout, $controlTimeout, $ackTimeout) > 0
            && ($dataTimeout + (3 * $controlTimeout) + (3 * $ackTimeout) + $overheadReserve) < $jobTimeout
            && $contentLockSeconds > $jobTimeout
            && $jobTimeout < $retryAfter;
    }

    private function redisAuthenticationIsConfigured(): bool
    {
        foreach (['default', 'cache', 'horizon'] as $name) {
            $connection = (array) config("database.redis.$name", []);
            $url = $connection['url'] ?? null;

            if ($this->filled($url)) {
                $parts = parse_url((string) $url);
                $password = is_array($parts) ? ($parts['pass'] ?? null) : null;
            } else {
                $password = $connection['password'] ?? null;
            }

            if (! is_string($password)
                || strlen($password) < 32
                || ! $this->credentialIsConfigured($password)) {
                return false;
            }
        }

        return true;
    }

    private function redisEndpointIsPrivate(): bool
    {
        foreach (['default', 'cache', 'horizon'] as $name) {
            $connection = (array) config("database.redis.$name", []);
            $url = $connection['url'] ?? null;

            if ($this->filled($url)) {
                $parts = parse_url((string) $url);
                $host = is_array($parts) ? ($parts['host'] ?? null) : null;
            } else {
                $host = $connection['host'] ?? null;
            }

            if (! is_string($host)
                || ! in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true)) {
                return false;
            }
        }

        return true;
    }

    private function memoryNamespaceKeyIsSafe(): bool
    {
        $key = trim((string) config('luczor.memory.namespace_key', ''));

        return strlen($key) >= 32 && $this->credentialIsConfigured($key);
    }

    private function memoryLedgerKeyIsSafe(): bool
    {
        $key = trim((string) config('luczor.memory.ledger_key', ''));
        $namespaceKey = trim((string) config('luczor.memory.namespace_key', ''));

        return strlen($key) >= 32
            && $this->credentialIsConfigured($key)
            && ($namespaceKey === '' || ! hash_equals($namespaceKey, $key));
    }

    private function memoryLedgerIdentitiesAreHardened(): bool
    {
        try {
            if (! Schema::hasColumn('memory_links', 'ledger_identity_version')
                || ! Schema::hasColumn('memory_write_events', 'ledger_identity_version')
                || DB::table('memory_links')->where('ledger_identity_version', '!=', 2)->exists()
                || DB::table('memory_write_events')->whereNotIn('ledger_identity_version', [2, 3])->exists()) {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function databaseAvailable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function migrationsCurrent(): bool
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return false;
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();

            return array_diff(array_keys($files), $ran) === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function redisAvailable(): bool
    {
        try {
            $reply = Redis::connection()->command('ping');

            return $reply === true || strtoupper((string) $reply) === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    private function horizonRunning(): bool
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            return $masters !== []
                && collect($masters)->every(fn ($master) => $master->status === 'running');
        } catch (Throwable) {
            return false;
        }
    }

    private function schedulerRunning(): bool
    {
        try {
            $lastSeen = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

            return is_string($lastSeen)
                && Carbon::parse($lastSeen)->greaterThanOrEqualTo(now()->subMinutes(3));
        } catch (Throwable) {
            return false;
        }
    }

    private function reverbServerReachable(): bool
    {
        $host = (string) config('reverb.servers.reverb.host');
        $port = (int) config('reverb.servers.reverb.port');
        if ($host === '' || $port < 1 || $port > 65_535) {
            return false;
        }

        if (in_array($host, ['0.0.0.0', '::', '[::]'], true)) {
            $host = '127.0.0.1';
        }

        set_error_handler(static fn () => true);
        try {
            $socket = stream_socket_client(
                'tcp://'.$host.':'.$port,
                $errorCode,
                $errorMessage,
                1.0,
                STREAM_CLIENT_CONNECT
            );
        } finally {
            restore_error_handler();
        }

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function credentialIsConfigured(mixed $value): bool
    {
        if (! $this->filled($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return ! str_starts_with($normalized, 'replace-with')
            && ! str_starts_with($normalized, 'change-me')
            && $normalized !== 'changeme';
    }
}
