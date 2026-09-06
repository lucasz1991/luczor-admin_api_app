<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\ModelProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BootstrapLocalModelTestCommandTest extends TestCase
{
    private string $databasePath;

    private string $testRoot;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'luczor-local-model-test-'.bin2hex(random_bytes(8));
        if (! mkdir($this->testRoot, 0700) && ! is_dir($this->testRoot)) {
            throw new RuntimeException('Could not create the isolated test root.');
        }

        $this->databasePath = $this->testRoot.DIRECTORY_SEPARATOR.'control-plane.sqlite';
        $this->tokenPath = $this->testRoot.DIRECTORY_SEPARATOR.'desktop.token';
        if (! touch($this->databasePath)) {
            throw new RuntimeException('Could not create the isolated SQLite file.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        DB::purge('sqlite');

        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]);
        if ($exitCode !== 0) {
            throw new RuntimeException('Could not migrate the isolated SQLite database.');
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        $this->removeTestRoot();

        parent::tearDown();
    }

    public function test_it_bootstraps_only_a_marked_on_disk_sqlite_instance_without_disclosing_the_token(): void
    {
        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $token = file_get_contents($this->tokenPath);
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $token);
        $this->assertStringNotContainsString($token, $output);
        $this->assertStringNotContainsString($this->tokenPath, $output);
        $this->assertStringNotContainsString($this->databasePath, $output);

        $markerPath = $this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.json';
        $this->assertFileExists($markerPath);
        $marker = json_decode((string) file_get_contents($markerPath), true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($marker);
        $this->assertSame(1, $marker['schema_version']);
        $this->assertSame('local_model_smoke', $marker['purpose']);
        $this->assertSame(basename($this->databasePath), $marker['database_file']);
        $this->assertSame(basename($this->tokenPath), $marker['token_file']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/i', $marker['instance_id']);

        $databaseMarker = DB::table('luczor_local_model_test_marker')->sole();
        $this->assertSame($marker['instance_id'], $databaseMarker->instance_id);
        $this->assertSame($marker['database_path_sha256'], $databaseMarker->database_path_sha256);
        $this->assertSame($marker['token_file'], $databaseMarker->token_file);

        $user = User::query()->sole();
        $this->assertSame('Luczor Local Model Smoke', $user->name);
        $this->assertSame('local-model-smoke@luczor.invalid', $user->email);
        $this->assertSame('user', $user->role);
        $this->assertTrue($user->isActive());
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->tenant_id);

        $apiKey = ApiKey::query()->sole();
        $this->assertSame($user->id, $apiKey->user_id);
        $this->assertSame('Luczor Local Model Smoke', $apiKey->name);
        $this->assertSame('luczor-local-model-smoke', $apiKey->device_id);
        $this->assertSame('Luczor Local Model Smoke Desktop', $apiKey->device_name);
        $this->assertSame(['settings.read'], $apiKey->abilities);
        $this->assertSame('luczor:local-model-test:bootstrap', $apiKey->meta['managed_by']);
        $this->assertSame($marker['instance_id'], $apiKey->meta['instance_id']);
        $this->assertTrue($apiKey->active);
        $this->assertTrue($apiKey->expires_at->isFuture());
        $this->assertTrue(hash_equals($apiKey->token_hash, ApiKey::hashToken($token)));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('device.id', 'luczor-local-model-smoke')
            ->assertJsonPath('device.name', 'Luczor Local Model Smoke Desktop')
            ->assertJsonPath('device.abilities', ['settings.read'])
            ->assertJsonPath('user.email', 'local-model-smoke@luczor.invalid');

        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, ModelProfile::query()->count());
        $this->assertFileExists($this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.lock');

        if (PHP_OS_FAMILY !== 'Windows') {
            $rootPermissions = fileperms($this->testRoot);
            $tokenPermissions = fileperms($this->tokenPath);
            $this->assertIsInt($rootPermissions);
            $this->assertIsInt($tokenPermissions);
            $this->assertSame(0700, $rootPermissions & 0777);
            $this->assertSame(0600, $tokenPermissions & 0777);
        }
    }

    public function test_it_renews_only_keys_owned_by_the_exact_user_device_and_command_instance(): void
    {
        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $firstToken = (string) file_get_contents($this->tokenPath);
        $ownedUser = User::query()->sole();
        $firstKey = ApiKey::query()->sole();
        $marker = json_decode((string) file_get_contents($this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.json'), true, 8, JSON_THROW_ON_ERROR);

        $foreignUser = User::factory()->create(['email' => 'foreign-local-model-smoke@luczor.invalid']);
        $foreignUserKey = ApiKey::mint($this->keyAttributes($foreignUser, 'luczor-local-model-smoke', 'luczor:local-model-test:bootstrap', $marker['instance_id']))['model'];
        $foreignDeviceKey = ApiKey::mint($this->keyAttributes($ownedUser, 'another-device', 'luczor:local-model-test:bootstrap', $marker['instance_id']))['model'];
        $foreignManagerKey = ApiKey::mint($this->keyAttributes($ownedUser, 'luczor-local-model-smoke', 'another-command', $marker['instance_id']))['model'];
        $foreignInstanceKey = ApiKey::mint($this->keyAttributes($ownedUser, 'luczor-local-model-smoke', 'luczor:local-model-test:bootstrap', '00000000-0000-4000-8000-000000000000'))['model'];

        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $secondToken = (string) file_get_contents($this->tokenPath);

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertFalse($firstKey->fresh()->active);
        $this->assertTrue($firstKey->fresh()->expires_at->lessThanOrEqualTo(now()));
        $this->assertTrue($foreignUserKey->fresh()->active);
        $this->assertTrue($foreignDeviceKey->fresh()->active);
        $this->assertTrue($foreignManagerKey->fresh()->active);
        $this->assertTrue($foreignInstanceKey->fresh()->active);
        $this->assertSame(1, ApiKey::query()
            ->where('user_id', $ownedUser->id)
            ->where('device_id', 'luczor-local-model-smoke')
            ->where('active', true)
            ->get()
            ->filter(fn (ApiKey $key): bool => ($key->meta['managed_by'] ?? null) === 'luczor:local-model-test:bootstrap'
                && ($key->meta['instance_id'] ?? null) === $marker['instance_id'])
            ->count());
    }

    public function test_it_refuses_to_run_outside_local_or_testing(): void
    {
        $previousEnvironment = $this->app->environment();
        $this->app['env'] = 'production';

        try {
            $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());
        } finally {
            $this->app['env'] = $previousEnvironment;
        }

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($this->tokenPath);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_it_refuses_a_non_sqlite_default_connection_before_touching_the_database(): void
    {
        config(['database.default' => 'mysql']);

        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($this->tokenPath);
    }

    public function test_it_requires_all_explicit_paths_and_rejects_memory_or_mismatched_databases(): void
    {
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', [
            '--token-file' => $this->tokenPath,
        ]));

        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));

        config(['database.connections.sqlite.database' => $this->databasePath]);
        $otherDatabase = $this->testRoot.DIRECTORY_SEPARATOR.'other.sqlite';
        touch($otherDatabase);
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', array_merge(
            $this->bootstrapArguments(),
            ['--database-file' => $otherDatabase],
        )));

        $this->assertFileDoesNotExist($this->tokenPath);
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_checkout_paths_and_unmanaged_root_entries(): void
    {
        $insideCheckout = storage_path('app/local-model-smoke.token');
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', array_merge(
            $this->bootstrapArguments(),
            ['--token-file' => $insideCheckout],
        )));

        $foreignPath = $this->testRoot.DIRECTORY_SEPARATOR.'notes.txt';
        file_put_contents($foreignPath, 'not owned by the command');
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $this->assertSame('not owned by the command', file_get_contents($foreignPath));
        $this->assertFileDoesNotExist($this->tokenPath);
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_foreign_or_empty_existing_token_files_without_overwriting_them(): void
    {
        touch($this->tokenPath);
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $this->assertSame('', file_get_contents($this->tokenPath));

        file_put_contents($this->tokenPath, str_repeat('A', 64));
        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $this->assertSame(str_repeat('A', 64), file_get_contents($this->tokenPath));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, ApiKey::query()->count());
        $this->assertFileDoesNotExist($this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.json');
    }

    public function test_it_refuses_to_claim_an_unmarked_database_that_contains_application_data(): void
    {
        User::factory()->create();
        $rootPermissions = $this->permissionFingerprint($this->testRoot, true);
        $databasePermissions = $this->permissionFingerprint($this->databasePath, false);

        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());

        $this->assertSame(1, $exitCode);
        $this->assertSame($rootPermissions, $this->permissionFingerprint($this->testRoot, true));
        $this->assertSame($databasePermissions, $this->permissionFingerprint($this->databasePath, false));
        $this->assertFileDoesNotExist($this->tokenPath);
        $this->assertFileDoesNotExist($this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.json');
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('luczor_local_model_test_marker'));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_it_rejects_unmarked_and_hardlinked_sqlite_sidecars_without_touching_owned_state(): void
    {
        $unmarkedSidecar = $this->databasePath.'-journal';
        file_put_contents($unmarkedSidecar, 'foreign journal');

        $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $this->assertSame('foreign journal', file_get_contents($unmarkedSidecar));
        $this->assertFileDoesNotExist($this->testRoot.DIRECTORY_SEPARATOR.'.luczor-local-model-test.json');
        unlink($unmarkedSidecar);

        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $token = (string) file_get_contents($this->tokenPath);
        $key = ApiKey::query()->sole();
        $source = sys_get_temp_dir().DIRECTORY_SEPARATOR.'luczor-sidecar-source-'.bin2hex(random_bytes(8));
        file_put_contents($source, 'foreign wal');
        $hardlinkedSidecar = $this->databasePath.'-wal';

        if (! @link($source, $hardlinkedSidecar)) {
            @unlink($source);
            $this->markTestSkipped('The filesystem does not permit a hardlink regression test.');
        }

        try {
            $this->assertSame(1, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
            $this->assertSame($token, file_get_contents($this->tokenPath));
            $this->assertSame('foreign wal', file_get_contents($source));
            $this->assertTrue($key->fresh()->active);
            $this->assertSame(1, ApiKey::query()->count());
        } finally {
            @unlink($hardlinkedSidecar);
            @unlink($source);
        }
    }

    public function test_it_fails_closed_if_the_reserved_identity_is_changed_after_initialization(): void
    {
        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $token = (string) file_get_contents($this->tokenPath);
        User::query()->sole()->forceFill(['name' => 'Existing Administrator', 'role' => 'admin'])->save();

        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());

        $this->assertSame(1, $exitCode);
        $this->assertSame($token, file_get_contents($this->tokenPath));
        $this->assertSame('Existing Administrator', User::query()->sole()->name);
        $this->assertSame('admin', User::query()->sole()->role);
        $this->assertSame(1, ApiKey::query()->count());
        $this->assertTrue(ApiKey::query()->sole()->active);
    }

    public function test_it_rejects_a_tampered_database_marker_and_preserves_the_existing_token(): void
    {
        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $token = (string) file_get_contents($this->tokenPath);
        DB::table('luczor_local_model_test_marker')->update([
            'instance_id' => '00000000-0000-4000-8000-000000000000',
        ]);

        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());

        $this->assertSame(1, $exitCode);
        $this->assertSame($token, file_get_contents($this->tokenPath));
        $this->assertSame(1, ApiKey::query()->count());
        $this->assertTrue(ApiKey::query()->sole()->active);
    }

    public function test_it_rejects_an_empty_previously_owned_token_file_without_revoking_its_key(): void
    {
        $this->assertSame(0, Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments()));
        $key = ApiKey::query()->sole();
        file_put_contents($this->tokenPath, '');

        $exitCode = Artisan::call('luczor:local-model-test:bootstrap', $this->bootstrapArguments());

        $this->assertSame(1, $exitCode);
        $this->assertSame('', file_get_contents($this->tokenPath));
        $this->assertTrue($key->fresh()->active);
        $this->assertSame(1, ApiKey::query()->count());
    }

    /** @return array{--test-root: string, --database-file: string, --token-file: string} */
    private function bootstrapArguments(): array
    {
        return [
            '--test-root' => $this->testRoot,
            '--database-file' => $this->databasePath,
            '--token-file' => $this->tokenPath,
        ];
    }

    /** @return array<string, mixed> */
    private function keyAttributes(User $user, string $deviceId, string $managedBy, string $instanceId): array
    {
        return [
            'user_id' => $user->id,
            'name' => 'Luczor Local Model Smoke',
            'abilities' => ['settings.read'],
            'active' => true,
            'expires_at' => now()->addHour(),
            'device_id' => $deviceId,
            'device_name' => 'Foreign test key',
            'meta' => [
                'purpose' => 'local_model_smoke',
                'managed_by' => $managedBy,
                'instance_id' => $instanceId,
            ],
        ];
    }

    private function permissionFingerprint(string $path, bool $directory): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($path);
            if (! is_int($permissions)) {
                throw new RuntimeException('Could not inspect POSIX permissions.');
            }

            return sprintf('%04o', $permissions & 0777);
        }

        $systemRoot = getenv('SystemRoot');
        if (! is_string($systemRoot) || $systemRoot === '') {
            throw new RuntimeException('The Windows system root is unavailable.');
        }

        $powershell = realpath($systemRoot.'\System32\WindowsPowerShell\v1.0\powershell.exe');
        if ($powershell === false) {
            throw new RuntimeException('Windows PowerShell is unavailable.');
        }

        $script = <<<'POWERSHELL'
$path = $env:LUCZOR_ACL_PATH
if ($env:LUCZOR_ACL_KIND -eq 'directory') {
    $acl = [System.IO.Directory]::GetAccessControl($path)
} else {
    $acl = [System.IO.File]::GetAccessControl($path)
}
[Console]::Out.Write($acl.Sddl)
POWERSHELL;
        $process = new Process([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            $script,
        ], null, [
            'LUCZOR_ACL_PATH' => $path,
            'LUCZOR_ACL_KIND' => $directory ? 'directory' : 'file',
        ], null, 20);
        $process->run();
        $fingerprint = trim($process->getOutput());
        if (! $process->isSuccessful() || $fingerprint === '') {
            throw new RuntimeException('Could not inspect the Windows ACL.');
        }

        return $fingerprint;
    }

    private function removeTestRoot(): void
    {
        if (! isset($this->testRoot) || ! is_dir($this->testRoot)) {
            return;
        }

        $resolvedRoot = realpath($this->testRoot);
        $resolvedTemp = realpath(sys_get_temp_dir());
        if ($resolvedRoot === false
            || $resolvedTemp === false
            || dirname($resolvedRoot) !== $resolvedTemp
            || ! str_starts_with(basename($resolvedRoot), 'luczor-local-model-test-')) {
            throw new RuntimeException('Refusing to remove an unexpected test directory.');
        }

        $entries = scandir($resolvedRoot);
        if (! is_array($entries)) {
            throw new RuntimeException('Could not inspect the isolated test root during cleanup.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $resolvedRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        @rmdir($resolvedRoot);
    }
}
