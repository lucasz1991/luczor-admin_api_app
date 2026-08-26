<?php

namespace Tests\Unit;

use App\Services\OperationalAcceptanceService;
use PHPUnit\Framework\TestCase;

class OperationalAcceptanceServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'luczor-acceptance-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700, true);
        $this->createValidWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function test_local_gate_passes_without_claiming_external_readiness(): void
    {
        $report = $this->service()->evaluate($this->workspace);

        $this->assertTrue($report['local_ready']);
        $this->assertFalse($report['external_ready']);
        $this->assertFalse($report['evidence_provided']);
        $this->assertTrue($report['evidence_valid']);
        $this->assertFalse($report['ready']);
        $this->assertSame('read_only_no_network_probes', $report['mode']);
        $this->assertContains('blocked', array_column($report['external_checks'], 'status'));
    }

    public function test_complete_revision_bound_evidence_can_pass_the_gate(): void
    {
        $evidencePath = $this->writeEvidence($this->completeEvidence());

        $report = $this->service()->evaluate($this->workspace, $evidencePath);

        $this->assertTrue($report['local_ready']);
        $this->assertTrue($report['external_ready']);
        $this->assertTrue($report['evidence_valid']);
        $this->assertTrue($report['ready']);
        $this->assertSame(
            ['passed'],
            array_values(array_unique(array_column($report['external_checks'], 'status'))),
        );
    }

    public function test_unknown_credential_fields_are_rejected_without_echoing_values(): void
    {
        $evidence = $this->completeEvidence();
        $evidence['environment']['apiToken'] = 'must-never-appear-in-output';
        $evidencePath = $this->writeEvidence($evidence);

        $report = $this->service()->evaluate($this->workspace, $evidencePath);
        $errors = implode(' ', $report['evidence_errors']);

        $this->assertFalse($report['evidence_valid']);
        $this->assertFalse($report['ready']);
        $this->assertStringContainsString('environment.apiToken', $errors);
        $this->assertStringNotContainsString('must-never-appear-in-output', $errors);
        $this->assertContains('invalid', array_column($report['external_checks'], 'status'));
    }

    public function test_unsafe_or_unverified_external_values_remain_blocked(): void
    {
        $evidence = $this->completeEvidence();
        $evidence['environment']['backendUrl'] = 'http://localhost';
        $evidence['environment']['backendRevision'] = str_repeat('d', 40);
        $evidence['environment']['verifiedAt'] = '2020-01-01T00:00:00+00:00';
        $evidence['runtime']['allowedOrigins'] = ['*'];
        $evidence['desktop']['artifactSha256'] = str_repeat('f', 64);
        $evidence['desktop']['keychainRoundTripPassed'] = false;
        $evidence['signing']['releaseVersion'] = '9.9.9';
        $evidencePath = $this->writeEvidence($evidence);

        $report = $this->service()->evaluate($this->workspace, $evidencePath);
        $missing = collect($report['external_checks'])->flatMap(
            fn (array $check) => $check['missing']
        )->all();

        $this->assertTrue($report['evidence_valid']);
        $this->assertFalse($report['external_ready']);
        $this->assertFalse($report['ready']);
        $this->assertContains('environment.backendUrl', $missing);
        $this->assertContains('environment.backendRevision', $missing);
        $this->assertContains('environment.verifiedAt', $missing);
        $this->assertContains('runtime.allowedOrigins', $missing);
        $this->assertContains('desktop.artifactSha256', $missing);
        $this->assertContains('desktop.keychainRoundTripPassed', $missing);
        $this->assertContains('signing.releaseVersion', $missing);
    }

    public function test_a_failed_local_toolchain_probe_blocks_even_complete_evidence(): void
    {
        $evidencePath = $this->writeEvidence($this->completeEvidence());

        $report = $this->service(false)->evaluate($this->workspace, $evidencePath);
        $toolchain = collect($report['local_checks'])->firstWhere('id', 'local.runtime_toolchain');

        $this->assertFalse($report['local_ready']);
        $this->assertFalse($report['ready']);
        $this->assertSame('failed', $toolchain['status']);
        $this->assertSame(['fixture toolchain mismatch'], $toolchain['missing']);
    }

    private function service(bool $toolchainPassed = true): OperationalAcceptanceService
    {
        return new OperationalAcceptanceService(
            static fn (string $root) => [
                'passed' => $toolchainPassed,
                'detail' => $toolchainPassed ? '' : 'fixture toolchain mismatch',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function completeEvidence(): array
    {
        return [
            'schemaVersion' => 1,
            'environment' => [
                'name' => 'production-eu',
                'backendUrl' => 'https://luczor.example.test',
                'backendRevision' => str_repeat('b', 40),
                'desktopRevision' => str_repeat('c', 40),
                'verifiedAt' => (new \DateTimeImmutable)->format(\DateTimeImmutable::ATOM),
                'verifiedBy' => 'release-operator',
            ],
            'migration' => [
                'databaseTargetReference' => 'inventory-db-primary',
                'backupReference' => 'backup-job-123',
                'rollbackReference' => 'runbook-migration-rollback',
                'targetConfirmed' => true,
                'backupRestoreTested' => true,
                'migrationStatusCurrent' => true,
                'deploymentCheckPassed' => true,
            ],
            'runtime' => [
                'redisReference' => 'inventory-redis-primary',
                'horizonSupervisorReference' => 'supervisor-luczor-horizon',
                'schedulerReference' => 'cron-luczor-schedule',
                'reverbPublicUrl' => 'wss://luczor.example.test/app',
                'allowedOrigins' => ['luczor.example.test', 'tauri.localhost'],
                'redisPingPassed' => true,
                'horizonRunning' => true,
                'schedulerHeartbeatFresh' => true,
                'reverbSocketPassed' => true,
                'privateChannelPassed' => true,
                'offlineCatchupPassed' => true,
            ],
            'desktop' => [
                'artifactReference' => 'luczor-windows-2.9.3',
                'artifactPath' => 'artifacts/luczor.exe',
                'artifactSha256' => hash('sha256', 'signed-artifact'),
                'hostOs' => 'Windows 11 24H2',
                'webviewVersion' => 'WebView2 140',
                'guiPassed' => true,
                'keychainRoundTripPassed' => true,
                'microphoneInputPassed' => true,
                'audioOutputPassed' => true,
                'notificationPermissionPassed' => true,
                'notificationClickPassed' => true,
                'filesystemApprovalPassed' => true,
                'computerApprovalPassed' => true,
            ],
            'signing' => [
                'targets' => ['windows'],
                'releaseVersion' => '2.9.3',
                'updaterHttpsUrl' => 'https://updates.example.test/luczor.json',
                'updaterPublicKeyFingerprint' => 'SHA256:public-fingerprint',
                'updaterSignatureVerified' => true,
                'updateInstallPassed' => true,
                'rollbackPassed' => true,
                'windows' => [
                    'signingIdentity' => 'Luczor release certificate fingerprint',
                    'signatureVerified' => true,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function writeEvidence(array $evidence): string
    {
        $path = $this->workspace.DIRECTORY_SEPARATOR.'acceptance-evidence.json';
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function createValidWorkspace(): void
    {
        $files = [
            'admin_api_app/artisan' => '',
            'admin_api_app/.git/HEAD' => str_repeat('b', 40),
            'admin_api_app/app/Console/Commands/DeploymentCheck.php' => '--production --configuration-only',
            'admin_api_app/app/Services/DeploymentHealthService.php' => implode(' ', [
                'databaseAvailable',
                'migrationsCurrent',
                'redisAvailable',
                'horizonRunning',
                'schedulerRunning',
                'reverbServerReachable',
            ]),
            'admin_api_app/database/migrations/2026_01_01_000000_fixture.php' => '<?php',
            'admin_api_app/composer.json' => '{"require":{"laravel/horizon":"*","laravel/reverb":"*"}}',
            'admin_api_app/app/Console/Kernel.php' => 'SCHEDULER_HEARTBEAT_KEY horizon:snapshot',
            'admin_api_app/routes/api.php' => '/reverb/auth /notifications /devices/jobs/next',
            'admin_api_app/app/Services/AppNotificationService.php' => '',
            'admin_api_app/app/Services/DeviceJobSigner.php' => '',
            'admin_api_app/app/Http/Controllers/Api/V1/AppNotificationController.php' => '',
            'app/package.json' => '{"version":"2.9.3","engines":{"node":">=22.12.0 <23"},'.
                '"dependencies":{"@tauri-apps/plugin-notification":"2"}}',
            'app/.git/HEAD' => str_repeat('c', 40),
            'app/src-tauri/tauri.conf.json' => '{"version":"2.9.3","app":{"windows":[{"label": "main"}]}}',
            'app/src-tauri/Cargo.toml' => implode("\n", [
                'keyring = "4"',
                'tauri-plugin-notification = "2"',
                'notify-rust = "4"',
            ]),
            'app/src-tauri/src/commands/device_key.rs' => '',
            'app/src-tauri/src/commands/voice.rs' => '',
            'app/src/services/voice/voiceEngine.ts' => '',
            'app/src/services/voice/speak.ts' => '',
            'app/src-tauri/capabilities/default.json' => implode(' ', [
                'notification:allow-is-permission-granted',
                'notification:allow-request-permission',
            ]),
            'app/src-tauri/src/commands/notifications.rs' => '',
            'app/src/services/notifications.ts' => '',
            'app/src/services/appRuntimeLifecycle.ts' => '',
            'app/src/services/tools/filesystem.ts' => "requiresApproval: true dataHandling: 'ephemeral'",
            'app/src/services/tools/os.ts' => 'requiresApproval: true',
            'app/.github/workflows/release-readiness.yml' => implode(' ', [
                'scripts/release-readiness.cjs --mode production',
                'TAURI_SIGNING_PRIVATE_KEY',
                'WINDOWS_CERTIFICATE',
                'APPLE_CERTIFICATE',
            ]),
            'app/scripts/release-readiness.cjs' => implode(' ', [
                'createUpdaterArtifacts',
                'tauri-plugin-updater',
                'tauri_plugin_updater',
                'production updater requirement missing',
            ]),
            'app/scripts/with-pinned-node.ps1' => 'Use-LuczorPinnedNode',
            'app/scripts/PinnedNode.psm1' => "Get-LuczorPinnedNode Do not run 'nvm use'",
            'app/check-version.cjs' => '',
            'artifacts/luczor.exe' => 'signed-artifact',
        ];

        foreach ($files as $relativePath => $contents) {
            $path = $this->workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($path, $contents);
        }
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
