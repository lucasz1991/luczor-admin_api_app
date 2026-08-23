<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

class DeploymentHealthService
{
    public const SCHEDULER_HEARTBEAT_KEY = 'luczor:operations:scheduler-heartbeat';

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
                'horizon_handles_notification_queue' => $this->horizonHandlesNotificationQueue(),
                'reverb_configured' => $this->reverbConfigured(),
            ];
        }

        if ($includeRuntime) {
            $checks['database'] = $this->databaseAvailable();

            if ($strict) {
                $checks['migrations_current'] = $this->migrationsCurrent();
                $checks['redis'] = $this->redisAvailable();
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
