<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class BootstrapLocalModelTest extends Command
{
    private const API_KEY_NAME = 'Luczor Local Model Smoke';

    private const DATABASE_MARKER_TABLE = 'luczor_local_model_test_marker';

    private const DEVICE_ID = 'luczor-local-model-smoke';

    private const DEVICE_NAME = 'Luczor Local Model Smoke Desktop';

    private const LOCK_FILE_NAME = '.luczor-local-model-test.lock';

    private const MANAGED_BY = 'luczor:local-model-test:bootstrap';

    private const MARKER_FILE_NAME = '.luczor-local-model-test.json';

    private const MARKER_SCHEMA_VERSION = 1;

    private const PURPOSE = 'local_model_smoke';

    private const USER_EMAIL = 'local-model-smoke@luczor.invalid';

    private const USER_NAME = 'Luczor Local Model Smoke';

    protected $signature = 'luczor:local-model-test:bootstrap
        {--test-root= : Required absolute dedicated directory outside this checkout}
        {--database-file= : Required absolute on-disk SQLite database inside the test root}
        {--token-file= : Required absolute output file inside the test root}';

    protected $description = 'Create a minimal, device-bound API key for an isolated local-model smoke test';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Refusing to bootstrap a local-model test outside the local or testing environment.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'sqlite') {
            $this->components->error('Refusing to bootstrap a local-model test unless the default database is SQLite.');

            return self::FAILURE;
        }

        $lockFile = null;
        $tokenFile = null;
        $tokenPath = null;
        $tokenCreated = false;
        $tokenWritten = false;
        $previousToken = null;
        $succeeded = false;

        try {
            $paths = $this->validatedPaths(
                $this->option('test-root'),
                $this->option('database-file'),
                $this->option('token-file'),
            );
            $tokenPath = $paths['token'];

            $this->assertRootContents($paths);
            $connection = $this->validatedSqliteConnection($paths['database']);
            $this->assertCommandOwnedOrPristine($paths, $connection);

            $this->securePathPermissions($paths['root'], true);
            $this->securePathPermissions($paths['database'], false);
            $this->secureSqliteSidecars($paths['database']);
            $this->assertClosedRegularFile($paths['database']);

            $lockFile = $this->openLockFile($paths['lock']);
            if (! flock($lockFile, LOCK_EX)) {
                throw new RuntimeException('The local-model test lock could not be acquired.');
            }

            $this->assertOpenFileIdentity($lockFile, $paths['lock']);
            $this->assertRootContents($paths);

            $connection = $this->validatedSqliteConnection($paths['database']);
            $this->assertCommandOwnedOrPristine($paths, $connection);
            $this->secureSqliteSidecars($paths['database']);
            $marker = $this->loadOrCreateMarker($paths, $connection);

            [$tokenFile, $tokenCreated, $previousToken] = $this->openTokenFile(
                $paths['token'],
                $marker,
            );

            $connection->transaction(function () use ($connection, $marker, $tokenFile, &$tokenWritten): void {
                $user = $this->dedicatedUser();
                $this->revokeOwnedKeys($user, $marker);

                $minted = ApiKey::mint([
                    'user_id' => $user->id,
                    'name' => self::API_KEY_NAME,
                    'abilities' => ['settings.read'],
                    'active' => true,
                    'expires_at' => now()->addHours(8),
                    'device_id' => self::DEVICE_ID,
                    'device_name' => self::DEVICE_NAME,
                    'meta' => [
                        'purpose' => self::PURPOSE,
                        'managed_by' => self::MANAGED_BY,
                        'instance_id' => $marker['instance_id'],
                    ],
                ]);

                $tokenWritten = true;
                $this->replaceFileContents($tokenFile, $minted['plain']);
                $this->assertOpenFileIdentity($tokenFile, stream_get_meta_data($tokenFile)['uri']);

                if ($connection->transactionLevel() < 1) {
                    throw new RuntimeException('The API key was not created inside the expected transaction.');
                }
            }, 3);

            $succeeded = true;
        } catch (Throwable) {
            if (is_resource($tokenFile) && $tokenWritten) {
                try {
                    $this->replaceFileContents($tokenFile, $previousToken ?? '');
                } catch (Throwable) {
                    $this->clearFile($tokenFile);
                }
            }
        } finally {
            if (is_resource($tokenFile)) {
                flock($tokenFile, LOCK_UN);
                fclose($tokenFile);
            }

            if (! $succeeded && $tokenCreated && is_string($tokenPath)) {
                @unlink($tokenPath);
            }

            if (is_resource($lockFile)) {
                flock($lockFile, LOCK_UN);
                fclose($lockFile);
            }
        }

        if (! $succeeded) {
            $this->components->error('The isolated local-model test bootstrap failed closed. No token was disclosed.');

            return self::FAILURE;
        }

        $this->components->info('Local-model test bootstrap completed. The token was written only to the protected test file and expires in eight hours.');

        return self::SUCCESS;
    }

    private function dedicatedUser(): User
    {
        $user = User::query()->where('email', self::USER_EMAIL)->lockForUpdate()->first();

        if ($user !== null && ! $this->isDedicatedUser($user)) {
            throw new RuntimeException('The dedicated test identity is not owned by this command.');
        }

        $attributes = [
            'tenant_id' => null,
            'name' => self::USER_NAME,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(64)),
            'role' => 'user',
            'status' => true,
            'remember_token' => null,
        ];

        if ($user === null) {
            $user = new User;
            $user->forceFill(array_merge(['email' => self::USER_EMAIL], $attributes))->save();

            return $user;
        }

        $user->forceFill($attributes)->save();

        return $user;
    }

    /** @param array{instance_id: string} $marker */
    private function revokeOwnedKeys(User $user, array $marker): void
    {
        $keys = ApiKey::query()
            ->where('user_id', $user->id)
            ->where('device_id', self::DEVICE_ID)
            ->where('name', self::API_KEY_NAME)
            ->lockForUpdate()
            ->get();

        foreach ($keys as $key) {
            if (! $this->isOwnedKey($key, $marker['instance_id'])) {
                continue;
            }

            $key->forceFill(['active' => false, 'expires_at' => now()])->save();
        }
    }

    private function isDedicatedUser(User $user): bool
    {
        return $user->name === self::USER_NAME
            && $user->email === self::USER_EMAIL
            && $user->role === 'user'
            && $user->tenant_id === null;
    }

    private function isOwnedKey(ApiKey $key, string $instanceId): bool
    {
        $meta = $key->meta;

        return is_array($meta)
            && ($meta['purpose'] ?? null) === self::PURPOSE
            && ($meta['managed_by'] ?? null) === self::MANAGED_BY
            && ($meta['instance_id'] ?? null) === $instanceId;
    }

    /** @return array{root: string, database: string, token: string, marker: string, lock: string} */
    private function validatedPaths(mixed $rootValue, mixed $databaseValue, mixed $tokenValue): array
    {
        $checkout = realpath(base_path());
        if ($checkout === false) {
            throw new RuntimeException('The checkout cannot be resolved.');
        }

        $root = $this->validatedRoot($rootValue, $checkout);
        $database = $this->validatedExistingFile($databaseValue, $checkout);
        if (! $this->samePath(dirname($database), $root)) {
            throw new RuntimeException('The database must be a direct child of the test root.');
        }

        $configuredDatabase = config('database.connections.sqlite.database');
        if (! is_string($configuredDatabase) || trim($configuredDatabase) === '' || trim($configuredDatabase) === ':memory:') {
            throw new RuntimeException('The configured SQLite database must be an on-disk file.');
        }

        $configuredDatabase = $this->validatedExistingFile($configuredDatabase, $checkout);
        if (! $this->samePath($configuredDatabase, $database)) {
            throw new RuntimeException('The explicit database does not match the configured SQLite database.');
        }

        $token = $this->validatedTokenCandidate($tokenValue, $root, $checkout);
        $marker = $root.DIRECTORY_SEPARATOR.self::MARKER_FILE_NAME;
        $lock = $root.DIRECTORY_SEPARATOR.self::LOCK_FILE_NAME;

        $paths = [$database, $token, $marker, $lock];
        $normalizedPaths = array_map(fn (string $path): string => $this->normalizedPath($path), $paths);
        if (count(array_unique($normalizedPaths)) !== count($normalizedPaths)) {
            throw new RuntimeException('Managed local-model test paths must be distinct.');
        }

        return compact('root', 'database', 'token', 'marker', 'lock');
    }

    private function validatedRoot(mixed $value, string $checkout): string
    {
        $path = $this->validatedAbsolutePathOption($value);
        if (is_link($path) || ! is_dir($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('The dedicated test root is unavailable.');
        }

        $root = realpath($path);
        if ($root === false || $this->insideCheckout($root, $checkout)) {
            throw new RuntimeException('The dedicated test root must be outside the checkout.');
        }

        return $root;
    }

    private function validatedExistingFile(mixed $value, string $checkout): string
    {
        $path = $this->validatedAbsolutePathOption($value);
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('The SQLite file is unavailable.');
        }

        $resolved = realpath($path);
        if ($resolved === false || $this->insideCheckout($resolved, $checkout)) {
            throw new RuntimeException('The SQLite file must be outside the checkout.');
        }

        $this->assertClosedRegularFile($resolved);

        return $resolved;
    }

    private function validatedTokenCandidate(mixed $value, string $root, string $checkout): string
    {
        $path = $this->validatedAbsolutePathOption($value);
        $filename = basename($path);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException('The token file name is invalid.');
        }

        if (PHP_OS_FAMILY === 'Windows' && (str_contains($filename, ':') || preg_match('/[. ]\z/', $filename) === 1)) {
            throw new RuntimeException('The token file name is unsafe on Windows.');
        }

        $directory = realpath(dirname($path));
        if ($directory === false || ! $this->samePath($directory, $root)) {
            throw new RuntimeException('The token must be a direct child of the dedicated test root.');
        }

        $candidate = $directory.DIRECTORY_SEPARATOR.$filename;
        if ($this->insideCheckout($candidate, $checkout)) {
            throw new RuntimeException('The token file cannot be inside the checkout.');
        }

        if (file_exists($candidate) || is_link($candidate)) {
            $this->assertClosedRegularFile($candidate);
        }

        return $candidate;
    }

    private function validatedAbsolutePathOption(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('A required path is missing.');
        }

        $path = trim($value);
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_starts_with($normalized, '//')
            || ! $this->isAbsolutePath($path)) {
            throw new RuntimeException('The path is unsafe.');
        }

        return $path;
    }

    /** @param array{root: string, database: string, token: string, marker: string, lock: string} $paths */
    private function assertRootContents(array $paths): void
    {
        $databaseName = basename($paths['database']);
        $sqliteSidecarNames = [
            $databaseName.'-journal',
            $databaseName.'-shm',
            $databaseName.'-wal',
        ];
        $allowedNames = [
            $databaseName,
            ...$sqliteSidecarNames,
            basename($paths['token']),
            self::MARKER_FILE_NAME,
            self::LOCK_FILE_NAME,
        ];
        $marked = file_exists($paths['marker']) || is_link($paths['marker']);

        $entries = scandir($paths['root']);
        if (! is_array($entries)) {
            throw new RuntimeException('The dedicated test root cannot be inspected.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! in_array($entry, $allowedNames, true)) {
                throw new RuntimeException('The dedicated test root contains an unmanaged entry.');
            }

            if (! $marked && in_array($entry, $sqliteSidecarNames, true)) {
                throw new RuntimeException('An unmarked root cannot contain SQLite sidecars.');
            }

            $entryPath = $paths['root'].DIRECTORY_SEPARATOR.$entry;
            $this->assertClosedRegularFile($entryPath);
        }

        if (! file_exists($paths['marker']) && file_exists($paths['token'])) {
            throw new RuntimeException('An unmarked root cannot contain a token file.');
        }
    }

    /** @param array{root: string, database: string, token: string, marker: string, lock: string} $paths */
    private function assertCommandOwnedOrPristine(array $paths, ConnectionInterface $connection): void
    {
        $hasMarkerFile = file_exists($paths['marker']) || is_link($paths['marker']);
        $hasDatabaseMarker = $this->databaseMarkerExists($connection);

        if (! $hasMarkerFile) {
            if ($hasDatabaseMarker) {
                throw new RuntimeException('The database marker has no matching test-root marker.');
            }

            $this->assertPristineDatabase($connection);

            return;
        }

        if (! $hasDatabaseMarker) {
            throw new RuntimeException('The test-root marker has no matching database marker.');
        }

        $marker = $this->readMarkerFile($paths['marker']);
        $this->assertMarkerMatchesPaths($marker, $paths);
        $this->assertDatabaseMarkerMatches($connection, $marker);
    }

    private function secureSqliteSidecars(string $databasePath): void
    {
        foreach (['-journal', '-shm', '-wal'] as $suffix) {
            $path = $databasePath.$suffix;
            if (! file_exists($path) && ! is_link($path)) {
                continue;
            }

            $this->assertClosedRegularFile($path);
            $this->securePathPermissions($path, false);
            $this->assertClosedRegularFile($path);
        }
    }

    private function validatedSqliteConnection(string $databasePath): ConnectionInterface
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unexpected database driver.');
        }

        $mainDatabase = null;
        foreach ($connection->select('PRAGMA database_list') as $database) {
            if (($database->name ?? null) === 'main' && is_string($database->file ?? null)) {
                $mainDatabase = realpath($database->file);
                break;
            }
        }

        if ($mainDatabase === false || ! is_string($mainDatabase) || ! $this->samePath($mainDatabase, $databasePath)) {
            throw new RuntimeException('The active SQLite connection is not the explicitly selected database.');
        }

        $this->assertClosedRegularFile($mainDatabase);

        return $connection;
    }

    /**
     * @param  array{root: string, database: string, token: string, marker: string, lock: string}  $paths
     * @return array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string}
     */
    private function loadOrCreateMarker(array $paths, ConnectionInterface $connection): array
    {
        $created = false;

        if (file_exists($paths['marker']) || is_link($paths['marker'])) {
            $marker = $this->readMarkerFile($paths['marker']);
        } else {
            if (file_exists($paths['token']) || is_link($paths['token'])) {
                throw new RuntimeException('The unmarked test root contains a token.');
            }

            $marker = [
                'schema_version' => self::MARKER_SCHEMA_VERSION,
                'purpose' => self::PURPOSE,
                'instance_id' => Str::uuid()->toString(),
                'database_file' => basename($paths['database']),
                'database_path_sha256' => $this->databasePathHash($paths['database']),
                'token_file' => basename($paths['token']),
            ];
            $this->writeNewMarkerFile($paths['marker'], $marker);
            $created = true;
        }

        try {
            $this->assertMarkerMatchesPaths($marker, $paths);
            $this->initializeOrVerifyDatabaseMarker($connection, $marker);
            $this->securePathPermissions($paths['marker'], false);
            $this->assertClosedRegularFile($paths['marker']);
        } catch (Throwable $exception) {
            if ($created) {
                @unlink($paths['marker']);
            }

            throw $exception;
        }

        return $marker;
    }

    /** @return array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string} */
    private function readMarkerFile(string $path): array
    {
        $this->assertClosedRegularFile($path);
        $file = @fopen($path, 'rb');
        if (! is_resource($file)) {
            throw new RuntimeException('The test-root marker cannot be opened.');
        }

        try {
            if (! flock($file, LOCK_SH)) {
                throw new RuntimeException('The test-root marker cannot be locked.');
            }
            $this->assertOpenFileIdentity($file, $path);
            $contents = $this->readBoundedFile($file, 4096);
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('The test-root marker is invalid.');
            }

        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }

        $expectedKeys = ['database_file', 'database_path_sha256', 'instance_id', 'purpose', 'schema_version', 'token_file'];
        $actualKeys = array_keys($decoded);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || $decoded['schema_version'] !== self::MARKER_SCHEMA_VERSION
            || $decoded['purpose'] !== self::PURPOSE
            || ! is_string($decoded['instance_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $decoded['instance_id']) !== 1
            || ! is_string($decoded['database_file'])
            || ! is_string($decoded['database_path_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $decoded['database_path_sha256']) !== 1
            || ! is_string($decoded['token_file'])) {
            throw new RuntimeException('The test-root marker does not have the command-owned shape.');
        }

        /** @var array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string} $decoded */
        return $decoded;
    }

    /** @param array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string} $marker */
    private function writeNewMarkerFile(string $path, array $marker): void
    {
        $file = @fopen($path, 'x+b');
        if (! is_resource($file)) {
            throw new RuntimeException('The test-root marker cannot be created exclusively.');
        }

        try {
            $this->assertOpenFileIdentity($file, $path);
            $this->securePathPermissions($path, false);
            $this->assertOpenFileIdentity($file, $path);
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('The test-root marker cannot be locked.');
            }

            $json = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $this->replaceFileContents($file, $json);
        } catch (Throwable $exception) {
            fclose($file);
            @unlink($path);

            throw $exception;
        }

        flock($file, LOCK_UN);
        fclose($file);
    }

    /**
     * @param  array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string}  $marker
     * @param  array{root: string, database: string, token: string, marker: string, lock: string}  $paths
     */
    private function assertMarkerMatchesPaths(array $marker, array $paths): void
    {
        if ($marker['schema_version'] !== self::MARKER_SCHEMA_VERSION
            || $marker['purpose'] !== self::PURPOSE
            || $marker['database_file'] !== basename($paths['database'])
            || ! hash_equals($marker['database_path_sha256'], $this->databasePathHash($paths['database']))
            || $marker['token_file'] !== basename($paths['token'])) {
            throw new RuntimeException('The marker is not bound to the selected test paths.');
        }
    }

    /** @param array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string} $marker */
    private function initializeOrVerifyDatabaseMarker(ConnectionInterface $connection, array $marker): void
    {
        if (! $this->databaseMarkerExists($connection)) {
            $connection->transaction(function () use ($connection, $marker): void {
                $this->assertPristineDatabase($connection);
                $connection->statement(<<<'SQL'
CREATE TABLE "luczor_local_model_test_marker" (
    "singleton" INTEGER PRIMARY KEY CHECK ("singleton" = 1),
    "schema_version" INTEGER NOT NULL,
    "purpose" VARCHAR(64) NOT NULL,
    "instance_id" VARCHAR(36) NOT NULL,
    "database_path_sha256" VARCHAR(64) NOT NULL,
    "token_file" VARCHAR(255) NOT NULL
)
SQL);
                $connection->insert(
                    'INSERT INTO "luczor_local_model_test_marker" ("singleton", "schema_version", "purpose", "instance_id", "database_path_sha256", "token_file") VALUES (1, ?, ?, ?, ?, ?)',
                    [$marker['schema_version'], $marker['purpose'], $marker['instance_id'], $marker['database_path_sha256'], $marker['token_file']],
                );
            }, 3);
        }

        $this->assertDatabaseMarkerMatches($connection, $marker);
    }

    private function databaseMarkerExists(ConnectionInterface $connection): bool
    {
        return $connection->selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [self::DATABASE_MARKER_TABLE],
        ) !== null;
    }

    /** @param array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string} $marker */
    private function assertDatabaseMarkerMatches(ConnectionInterface $connection, array $marker): void
    {
        $row = $connection->selectOne('SELECT * FROM "luczor_local_model_test_marker" WHERE "singleton" = 1');
        $count = $connection->scalar('SELECT COUNT(*) FROM "luczor_local_model_test_marker"');
        if ($row === null
            || (int) $count !== 1
            || (int) ($row->schema_version ?? 0) !== $marker['schema_version']
            || ($row->purpose ?? null) !== $marker['purpose']
            || ($row->instance_id ?? null) !== $marker['instance_id']
            || ($row->database_path_sha256 ?? null) !== $marker['database_path_sha256']
            || ($row->token_file ?? null) !== $marker['token_file']) {
            throw new RuntimeException('The SQLite database is not owned by this test-root marker.');
        }
    }

    private function assertPristineDatabase(ConnectionInterface $connection): void
    {
        $tables = $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            $name = $table->name ?? null;
            if (! is_string($name) || $name === 'migrations') {
                continue;
            }

            $quotedName = '"'.str_replace('"', '""', $name).'"';
            if ((int) $connection->scalar("SELECT COUNT(*) FROM {$quotedName}") > 0) {
                throw new RuntimeException('A non-empty SQLite database cannot be claimed by this command.');
            }
        }
    }

    /**
     * @param  array{schema_version: int, purpose: string, instance_id: string, database_file: string, database_path_sha256: string, token_file: string}  $marker
     * @return array{0: resource, 1: bool, 2: string|null}
     */
    private function openTokenFile(string $path, array $marker): array
    {
        $exists = file_exists($path) || is_link($path);
        $created = ! $exists;
        $file = null;

        try {
            if ($exists) {
                $this->assertClosedRegularFile($path);
                $file = @fopen($path, 'r+b');
            } else {
                $file = @fopen($path, 'x+b');
            }

            if (! is_resource($file)) {
                throw new RuntimeException('The token file cannot be opened safely.');
            }

            $this->assertOpenFileIdentity($file, $path);
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('The token file cannot be locked.');
            }
            $this->assertOpenFileIdentity($file, $path);

            $previousToken = null;
            if ($exists) {
                $previousToken = $this->readBoundedFile($file, 64);
                if (preg_match('/\A[A-Za-z0-9]{64}\z/', $previousToken) !== 1) {
                    throw new RuntimeException('The existing token file is not command-owned.');
                }
                $this->assertExistingTokenOwned($previousToken, $marker['instance_id']);
            }

            $this->securePathPermissions($path, false);
            $this->assertOpenFileIdentity($file, $path);

            return [$file, $created, $previousToken];
        } catch (Throwable $exception) {
            if (is_resource($file)) {
                flock($file, LOCK_UN);
                fclose($file);
            }
            if ($created) {
                @unlink($path);
            }

            throw $exception;
        }
    }

    private function assertExistingTokenOwned(string $token, string $instanceId): void
    {
        $user = User::query()->where('email', self::USER_EMAIL)->first();
        if ($user === null || ! $this->isDedicatedUser($user)) {
            throw new RuntimeException('The token owner is not the dedicated command identity.');
        }

        $key = ApiKey::query()
            ->where('token_hash', ApiKey::hashToken($token))
            ->where('user_id', $user->id)
            ->where('device_id', self::DEVICE_ID)
            ->where('name', self::API_KEY_NAME)
            ->first();

        if ($key === null || ! $this->isOwnedKey($key, $instanceId)) {
            throw new RuntimeException('The token file is not bound to this command instance.');
        }
    }

    /** @return resource */
    private function openLockFile(string $path)
    {
        $exists = file_exists($path) || is_link($path);
        if ($exists) {
            $this->assertClosedRegularFile($path);
            $file = @fopen($path, 'r+b');
        } else {
            $file = @fopen($path, 'x+b');
            if (! is_resource($file)) {
                $file = $this->openExistingLockAfterCreateRace($path);
            }
        }

        if (! is_resource($file)) {
            throw new RuntimeException('The dedicated command lock cannot be opened.');
        }

        try {
            $this->assertOpenFileIdentity($file, $path);
            $stat = fstat($file);
            if (! is_array($stat) || (int) $stat['size'] !== 0) {
                throw new RuntimeException('The dedicated command lock is not command-owned.');
            }

            $this->securePathPermissions($path, false);
            $this->assertOpenFileIdentity($file, $path);
        } catch (Throwable $exception) {
            fclose($file);

            throw $exception;
        }

        return $file;
    }

    /** @return resource */
    private function openExistingLockAfterCreateRace(string $path)
    {
        clearstatcache(true, $path);
        $this->assertClosedRegularFile($path);
        $file = @fopen($path, 'r+b');
        if (! is_resource($file)) {
            throw new RuntimeException('The concurrently created command lock cannot be opened.');
        }

        return $file;
    }

    private function assertClosedRegularFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('The managed file is unavailable or unsafe.');
        }

        $stat = lstat($path);
        if (! is_array($stat) || (int) $stat['nlink'] !== 1 || (((int) $stat['mode']) & 0170000) !== 0100000) {
            throw new RuntimeException('The managed file is not a singly linked regular file.');
        }
    }

    /** @param resource $file */
    private function assertOpenFileIdentity($file, string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Symbolic links are not accepted for managed files.');
        }

        $openStat = fstat($file);
        $pathStat = lstat($path);
        if (! is_array($openStat)
            || ! is_array($pathStat)
            || (int) $openStat['nlink'] !== 1
            || (int) $pathStat['nlink'] !== 1
            || (((int) $openStat['mode']) & 0170000) !== 0100000
            || (((int) $pathStat['mode']) & 0170000) !== 0100000
            || (string) $openStat['dev'] !== (string) $pathStat['dev']
            || (string) $openStat['ino'] !== (string) $pathStat['ino']) {
            throw new RuntimeException('The opened file no longer matches the validated filesystem identity.');
        }
    }

    private function securePathPermissions(string $path, bool $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->secureWindowsAcl($path, $directory);

            return;
        }

        $mode = $directory ? 0700 : 0600;
        if (! chmod($path, $mode)) {
            throw new RuntimeException('Managed path permissions could not be restricted.');
        }

        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if (! is_int($permissions) || ($permissions & 0777) !== $mode) {
            throw new RuntimeException('Managed path permissions are unsafe.');
        }
    }

    private function secureWindowsAcl(string $path, bool $directory): void
    {
        $systemRoot = getenv('SystemRoot');
        if (! is_string($systemRoot) || $systemRoot === '') {
            throw new RuntimeException('The Windows system root is unavailable.');
        }

        $powershell = realpath($systemRoot.'\System32\WindowsPowerShell\v1.0\powershell.exe');
        if ($powershell === false || ! is_file($powershell)) {
            throw new RuntimeException('Windows PowerShell is unavailable for ACL enforcement.');
        }

        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$path = $env:LUCZOR_ACL_PATH
$kind = $env:LUCZOR_ACL_KIND
if ([string]::IsNullOrWhiteSpace($path) -or ($kind -ne 'directory' -and $kind -ne 'file')) { throw 'invalid input' }
$current = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$system = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18')
if ($kind -eq 'directory') {
    $acl = [System.Security.AccessControl.DirectorySecurity]::new()
    $inheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($current, [System.Security.AccessControl.FileSystemRights]::FullControl, $inheritance, [System.Security.AccessControl.PropagationFlags]::None, [System.Security.AccessControl.AccessControlType]::Allow))
    $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($system, [System.Security.AccessControl.FileSystemRights]::FullControl, $inheritance, [System.Security.AccessControl.PropagationFlags]::None, [System.Security.AccessControl.AccessControlType]::Allow))
    $acl.SetOwner($current)
    $acl.SetAccessRuleProtection($true, $false)
    [System.IO.Directory]::SetAccessControl($path, $acl)
    $actual = [System.IO.Directory]::GetAccessControl($path)
} else {
    $acl = [System.Security.AccessControl.FileSecurity]::new()
    $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($current, [System.Security.AccessControl.FileSystemRights]::FullControl, [System.Security.AccessControl.AccessControlType]::Allow))
    $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($system, [System.Security.AccessControl.FileSystemRights]::FullControl, [System.Security.AccessControl.AccessControlType]::Allow))
    $acl.SetOwner($current)
    $acl.SetAccessRuleProtection($true, $false)
    [System.IO.File]::SetAccessControl($path, $acl)
    $actual = [System.IO.File]::GetAccessControl($path)
}
$owner = $actual.Owner
try { $owner = ([System.Security.Principal.NTAccount]$owner).Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { }
if ($owner -ne $current.Value -or -not $actual.AreAccessRulesProtected) { throw 'owner or inheritance mismatch' }
$allowed = @($current.Value, $system.Value)
$seen = @{}
$rules = @($actual.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]))
if ($rules.Count -ne 2) { throw 'unexpected access rule count' }
foreach ($rule in $rules) {
    $sid = $rule.IdentityReference.Value
    if ($rule.IsInherited -or $rule.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow -or $allowed -notcontains $sid) { throw 'unexpected access rule' }
    if (($rule.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::FullControl) -ne [System.Security.AccessControl.FileSystemRights]::FullControl) { throw 'insufficient access rule' }
    $seen[$sid] = $true
}
if (-not $seen.ContainsKey($current.Value) -or -not $seen.ContainsKey($system.Value)) { throw 'missing access rule' }
[Console]::Out.Write('LUCZOR_ACL_OK')
POWERSHELL;

        $process = new Process([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], null, [
            'LUCZOR_ACL_PATH' => $path,
            'LUCZOR_ACL_KIND' => $directory ? 'directory' : 'file',
        ], null, 20);
        $process->run();

        if (! $process->isSuccessful() || trim($process->getOutput()) !== 'LUCZOR_ACL_OK') {
            throw new RuntimeException('Windows ACL enforcement or verification failed.');
        }
    }

    /** @param resource $file */
    private function readBoundedFile($file, int $maximumBytes): string
    {
        if (! rewind($file)) {
            throw new RuntimeException('The managed file cannot be read.');
        }

        $contents = stream_get_contents($file, $maximumBytes + 1);
        if (! is_string($contents) || strlen($contents) > $maximumBytes || ! feof($file)) {
            throw new RuntimeException('The managed file exceeds its bounded format.');
        }

        return $contents;
    }

    /** @param resource $file */
    private function replaceFileContents($file, string $contents): void
    {
        if (! rewind($file) || ! ftruncate($file, 0)) {
            throw new RuntimeException('The managed file cannot be reset.');
        }

        $remaining = $contents;
        while ($remaining !== '') {
            $written = fwrite($file, $remaining);
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('The managed file cannot be written.');
            }

            $remaining = substr($remaining, $written);
        }

        if (! fflush($file) || (function_exists('fsync') && ! fsync($file))) {
            throw new RuntimeException('The managed file cannot be synchronized.');
        }
    }

    /** @param resource $file */
    private function clearFile($file): void
    {
        @rewind($file);
        @ftruncate($file, 0);
        @fflush($file);
    }

    private function databasePathHash(string $path): string
    {
        return hash('sha256', $this->normalizedPath($path));
    }

    private function isAbsolutePath(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return preg_match('~\A[A-Za-z]:/~', str_replace('\\', '/', $path)) === 1;
        }

        return str_starts_with($path, '/');
    }

    private function insideCheckout(string $path, string $checkout): bool
    {
        $candidate = rtrim($this->normalizedPath($path), '/');
        $base = rtrim($this->normalizedPath($checkout), '/');

        return $candidate === $base || str_starts_with($candidate, $base.'/');
    }

    private function samePath(string $left, string $right): bool
    {
        return $this->normalizedPath($left) === $this->normalizedPath($right);
    }

    private function normalizedPath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
