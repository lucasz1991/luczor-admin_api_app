<?php

namespace App\Services;

use Closure;
use DateTimeImmutable;
use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

final class OperationalAcceptanceService
{
    public const SCHEMA_VERSION = 1;

    private const MAX_LOCAL_FILE_BYTES = 2_000_000;

    private const MAX_EVIDENCE_BYTES = 262_144;

    /**
     * @param  null|Closure(string): array{passed: bool, detail: string}  $toolchainProbe
     */
    public function __construct(private readonly ?Closure $toolchainProbe = null) {}

    /**
     * Evidence is deliberately an attestation manifest, not a secret store.
     * Unknown fields are rejected so credentials cannot silently become part of it.
     */
    private const EVIDENCE_SCHEMA = [
        'schemaVersion' => true,
        'environment' => [
            'name' => true,
            'backendUrl' => true,
            'backendRevision' => true,
            'desktopRevision' => true,
            'verifiedAt' => true,
            'verifiedBy' => true,
        ],
        'migration' => [
            'databaseTargetReference' => true,
            'backupReference' => true,
            'rollbackReference' => true,
            'targetConfirmed' => true,
            'backupRestoreTested' => true,
            'migrationStatusCurrent' => true,
            'deploymentCheckPassed' => true,
        ],
        'runtime' => [
            'redisReference' => true,
            'horizonSupervisorReference' => true,
            'schedulerReference' => true,
            'reverbPublicUrl' => true,
            'allowedOrigins' => true,
            'redisPingPassed' => true,
            'horizonRunning' => true,
            'schedulerHeartbeatFresh' => true,
            'reverbSocketPassed' => true,
            'privateChannelPassed' => true,
            'offlineCatchupPassed' => true,
        ],
        'desktop' => [
            'artifactReference' => true,
            'artifactPath' => true,
            'artifactSha256' => true,
            'hostOs' => true,
            'webviewVersion' => true,
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
            'targets' => true,
            'releaseVersion' => true,
            'updaterHttpsUrl' => true,
            'updaterPublicKeyFingerprint' => true,
            'updaterSignatureVerified' => true,
            'updateInstallPassed' => true,
            'rollbackPassed' => true,
            'windows' => [
                'signingIdentity' => true,
                'signatureVerified' => true,
            ],
            'macos' => [
                'signingIdentity' => true,
                'teamId' => true,
                'notarizationVerified' => true,
            ],
        ],
    ];

    /** @return array<string, mixed> */
    public function evaluate(string $workspaceRoot, ?string $evidencePath = null): array
    {
        $resolvedRoot = realpath($workspaceRoot);
        $rootIsValid = is_string($resolvedRoot) && is_dir($resolvedRoot);
        $localChecks = $rootIsValid
            ? $this->localChecks($resolvedRoot)
            : [[
                'id' => 'workspace.layout',
                'status' => 'failed',
                'missing' => ['workspace root'],
            ]];

        $releaseVersion = $rootIsValid ? $this->releaseVersion($resolvedRoot) : null;
        $backendRevision = $rootIsValid
            ? $this->gitRevision($this->join($resolvedRoot, 'admin_api_app'))
            : null;
        $desktopRevision = $rootIsValid
            ? $this->gitRevision($this->join($resolvedRoot, 'app'))
            : null;
        $evidence = $this->readEvidence($evidencePath);
        $artifactHash = $rootIsValid && is_array($evidence['data'])
            ? $this->artifactHash($resolvedRoot, $evidence['data'])
            : null;
        $externalChecks = $this->externalChecks(
            $evidence['data'],
            $evidence['provided'],
            $evidence['valid'],
            $releaseVersion,
            $backendRevision,
            $desktopRevision,
            $artifactHash,
        );

        $localReady = $this->allPassed($localChecks);
        $externalReady = $this->allPassed($externalChecks);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'read_only_no_network_probes',
            'local_ready' => $localReady,
            'external_ready' => $externalReady,
            'evidence_provided' => $evidence['provided'],
            'evidence_valid' => $evidence['valid'],
            'ready' => $localReady && $externalReady && $evidence['valid'],
            'local_checks' => $localChecks,
            'external_checks' => $externalChecks,
            'evidence_errors' => $evidence['errors'],
        ];
    }

    /** @return list<array{id: string, status: string, missing: list<string>}> */
    private function localChecks(string $root): array
    {
        $definitions = [
            'backend.migration_gate' => [
                ['admin_api_app/artisan', []],
                ['admin_api_app/app/Console/Commands/DeploymentCheck.php', ['--production', '--configuration-only']],
                ['admin_api_app/app/Services/DeploymentHealthService.php', ['databaseAvailable', 'migrationsCurrent']],
                ['admin_api_app/database/migrations', ['*.php']],
            ],
            'backend.runtime_gate' => [
                ['admin_api_app/composer.json', ['laravel/horizon', 'laravel/reverb']],
                ['admin_api_app/app/Console/Kernel.php', ['SCHEDULER_HEARTBEAT_KEY', 'horizon:snapshot']],
                ['admin_api_app/app/Services/DeploymentHealthService.php', [
                    'redisAvailable',
                    'horizonRunning',
                    'schedulerRunning',
                    'reverbServerReachable',
                ]],
            ],
            'backend.realtime_recovery' => [
                ['admin_api_app/routes/api.php', ['/reverb/auth', '/notifications', '/devices/jobs/next']],
                ['admin_api_app/app/Services/AppNotificationService.php', []],
                ['admin_api_app/app/Services/DeviceJobSigner.php', []],
                ['admin_api_app/app/Http/Controllers/Api/V1/AppNotificationController.php', []],
            ],
            'desktop.gui_keychain_audio' => [
                ['app/package.json', ['>=22.12.0 <23', '@tauri-apps/plugin-notification']],
                ['app/src-tauri/tauri.conf.json', ['"label": "main"']],
                ['app/src-tauri/Cargo.toml', ['keyring =', 'tauri-plugin-notification =', 'notify-rust =']],
                ['app/src-tauri/src/commands/device_key.rs', []],
                ['app/src-tauri/src/commands/voice.rs', []],
                ['app/src/services/voice/voiceEngine.ts', []],
                ['app/src/services/voice/speak.ts', []],
            ],
            'desktop.notifications_and_approvals' => [
                ['app/src-tauri/capabilities/default.json', [
                    'notification:allow-is-permission-granted',
                    'notification:allow-request-permission',
                ]],
                ['app/src-tauri/src/commands/notifications.rs', []],
                ['app/src/services/notifications.ts', []],
                ['app/src/services/appRuntimeLifecycle.ts', []],
                ['app/src/services/tools/filesystem.ts', ['requiresApproval: true', "dataHandling: 'ephemeral'"]],
                ['app/src/services/tools/os.ts', ['requiresApproval: true']],
            ],
            'release.fail_closed_gate' => [
                ['app/.github/workflows/release-readiness.yml', [
                    'scripts/release-readiness.cjs --mode production',
                    'TAURI_SIGNING_PRIVATE_KEY',
                    'WINDOWS_CERTIFICATE',
                    'APPLE_CERTIFICATE',
                ]],
                ['app/scripts/release-readiness.cjs', [
                    'createUpdaterArtifacts',
                    'tauri-plugin-updater',
                    'tauri_plugin_updater',
                    'production updater requirement missing',
                ]],
                ['app/scripts/with-pinned-node.ps1', ['Use-LuczorPinnedNode']],
                ['app/scripts/PinnedNode.psm1', ['Get-LuczorPinnedNode', 'Do not run \'nvm use\'']],
                ['app/check-version.cjs', []],
                ['app/src-tauri/tauri.conf.json', ['"version"']],
            ],
        ];

        $checks = [];
        foreach ($definitions as $id => $requirements) {
            $missing = [];
            foreach ($requirements as [$relativePath, $needles]) {
                $fullPath = $this->join($root, $relativePath);
                if (is_dir($fullPath)) {
                    $pattern = $needles[0] ?? '*';
                    $matches = glob($fullPath.DIRECTORY_SEPARATOR.$pattern);
                    if (! is_array($matches) || $matches === []) {
                        $missing[] = $relativePath.'/'.$pattern;
                    }

                    continue;
                }

                if (! is_file($fullPath)) {
                    $missing[] = $relativePath;

                    continue;
                }

                if ($needles === []) {
                    continue;
                }

                $size = filesize($fullPath);
                if (! is_int($size) || $size > self::MAX_LOCAL_FILE_BYTES) {
                    $missing[] = $relativePath.' (unreadable or too large)';

                    continue;
                }

                $contents = file_get_contents($fullPath);
                if (! is_string($contents)) {
                    $missing[] = $relativePath.' (unreadable)';

                    continue;
                }

                foreach ($needles as $needle) {
                    if (! str_contains($contents, $needle)) {
                        $missing[] = $relativePath.' :: '.$needle;
                    }
                }
            }

            $checks[] = [
                'id' => $id,
                'status' => $missing === [] ? 'passed' : 'failed',
                'missing' => $missing,
            ];
        }

        $missingRevisions = [];
        if ($this->gitRevision($this->join($root, 'admin_api_app')) === null) {
            $missingRevisions[] = 'admin_api_app Git HEAD';
        }
        if ($this->gitRevision($this->join($root, 'app')) === null) {
            $missingRevisions[] = 'app Git HEAD';
        }
        $checks[] = [
            'id' => 'source.git_revisions',
            'status' => $missingRevisions === [] ? 'passed' : 'failed',
            'missing' => $missingRevisions,
        ];

        $toolchain = $this->probeLocalToolchain($root);
        $checks[] = [
            'id' => 'local.runtime_toolchain',
            'status' => $toolchain['passed'] ? 'passed' : 'failed',
            'missing' => $toolchain['passed'] ? [] : [$toolchain['detail']],
        ];

        return $checks;
    }

    /**
     * @return array{provided: bool, valid: bool, data: ?array<string, mixed>, errors: list<string>}
     */
    private function readEvidence(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return ['provided' => false, 'valid' => true, 'data' => null, 'errors' => []];
        }

        $resolved = realpath($path);
        if (! is_string($resolved) || ! is_file($resolved)) {
            return [
                'provided' => true,
                'valid' => false,
                'data' => null,
                'errors' => ['Evidence file does not exist.'],
            ];
        }

        $size = filesize($resolved);
        if (! is_int($size) || $size > self::MAX_EVIDENCE_BYTES) {
            return [
                'provided' => true,
                'valid' => false,
                'data' => null,
                'errors' => ['Evidence file exceeds the 256 KiB limit.'],
            ];
        }

        $contents = file_get_contents($resolved);
        if (! is_string($contents)) {
            return [
                'provided' => true,
                'valid' => false,
                'data' => null,
                'errors' => ['Evidence file could not be read.'],
            ];
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'provided' => true,
                'valid' => false,
                'data' => null,
                'errors' => ['Evidence file is not valid JSON.'],
            ];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [
                'provided' => true,
                'valid' => false,
                'data' => null,
                'errors' => ['Evidence root must be a JSON object.'],
            ];
        }

        $errors = $this->unsupportedFields($decoded, self::EVIDENCE_SCHEMA);

        return [
            'provided' => true,
            'valid' => $errors === [],
            'data' => $errors === [] ? $decoded : null,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function unsupportedFields(array $actual, array $schema, string $prefix = ''): array
    {
        $errors = [];
        foreach ($actual as $key => $value) {
            $key = (string) $key;
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            if (! array_key_exists($key, $schema)) {
                $errors[] = 'Unsupported evidence field: '.$path;

                continue;
            }

            $nestedSchema = $schema[$key];
            if (is_array($nestedSchema) && is_array($value) && ! array_is_list($value)) {
                $errors = array_merge($errors, $this->unsupportedFields($value, $nestedSchema, $path));
            }
        }

        return $errors;
    }

    /**
     * @param  ?array<string, mixed>  $evidence
     * @return list<array{id: string, status: string, missing: list<string>}>
     */
    private function externalChecks(
        ?array $evidence,
        bool $provided,
        bool $valid,
        ?string $releaseVersion,
        ?string $backendRevision,
        ?string $desktopRevision,
        ?string $artifactHash,
    ): array {
        $requirements = $this->externalRequirements(
            $evidence,
            $releaseVersion,
            $backendRevision,
            $desktopRevision,
            $artifactHash,
        );
        $checks = [];

        foreach ($requirements as $id => $fields) {
            $missing = [];
            if ($provided && $valid && $evidence !== null) {
                foreach ($fields as $field) {
                    $value = $this->valueAt($evidence, $field['path']);
                    if (! $this->valueSatisfies($value, $field['rule'], $field['expected'] ?? null)) {
                        $missing[] = $field['path'];
                    }
                }
            } else {
                $missing = array_column($fields, 'path');
            }

            $status = 'blocked';
            if ($provided && ! $valid) {
                $status = 'invalid';
            } elseif ($provided && $missing === []) {
                $status = 'passed';
            }

            $checks[] = [
                'id' => $id,
                'status' => $status,
                'missing' => array_values(array_unique($missing)),
            ];
        }

        return $checks;
    }

    /**
     * @param  ?array<string, mixed>  $evidence
     * @return array<string, list<array{path: string, rule: string, expected?: mixed}>>
     */
    private function externalRequirements(
        ?array $evidence,
        ?string $releaseVersion,
        ?string $backendRevision,
        ?string $desktopRevision,
        ?string $artifactHash,
    ): array {
        $signing = [
            ['path' => 'signing.targets', 'rule' => 'targets'],
            ['path' => 'signing.releaseVersion', 'rule' => 'equals', 'expected' => $releaseVersion],
            ['path' => 'signing.updaterHttpsUrl', 'rule' => 'https_url'],
            ['path' => 'signing.updaterPublicKeyFingerprint', 'rule' => 'non_empty'],
            ['path' => 'signing.updaterSignatureVerified', 'rule' => 'true'],
            ['path' => 'signing.updateInstallPassed', 'rule' => 'true'],
            ['path' => 'signing.rollbackPassed', 'rule' => 'true'],
        ];

        $targets = $this->valueAt($evidence ?? [], 'signing.targets');
        if (! is_array($targets) || in_array('windows', $targets, true)) {
            $signing[] = ['path' => 'signing.windows.signingIdentity', 'rule' => 'non_empty'];
            $signing[] = ['path' => 'signing.windows.signatureVerified', 'rule' => 'true'];
        }
        if (is_array($targets) && in_array('macos', $targets, true)) {
            $signing[] = ['path' => 'signing.macos.signingIdentity', 'rule' => 'non_empty'];
            $signing[] = ['path' => 'signing.macos.teamId', 'rule' => 'non_empty'];
            $signing[] = ['path' => 'signing.macos.notarizationVerified', 'rule' => 'true'];
        }

        return [
            'external.environment' => [
                ['path' => 'schemaVersion', 'rule' => 'schema'],
                ['path' => 'environment.name', 'rule' => 'non_empty'],
                ['path' => 'environment.backendUrl', 'rule' => 'https_url'],
                ['path' => 'environment.backendRevision', 'rule' => 'equals', 'expected' => $backendRevision],
                ['path' => 'environment.desktopRevision', 'rule' => 'equals', 'expected' => $desktopRevision],
                ['path' => 'environment.verifiedAt', 'rule' => 'recent_iso8601'],
                ['path' => 'environment.verifiedBy', 'rule' => 'non_empty'],
            ],
            'external.migration' => [
                ['path' => 'migration.databaseTargetReference', 'rule' => 'non_empty'],
                ['path' => 'migration.backupReference', 'rule' => 'non_empty'],
                ['path' => 'migration.rollbackReference', 'rule' => 'non_empty'],
                ['path' => 'migration.targetConfirmed', 'rule' => 'true'],
                ['path' => 'migration.backupRestoreTested', 'rule' => 'true'],
                ['path' => 'migration.migrationStatusCurrent', 'rule' => 'true'],
                ['path' => 'migration.deploymentCheckPassed', 'rule' => 'true'],
            ],
            'external.runtime_services' => [
                ['path' => 'runtime.redisReference', 'rule' => 'non_empty'],
                ['path' => 'runtime.horizonSupervisorReference', 'rule' => 'non_empty'],
                ['path' => 'runtime.schedulerReference', 'rule' => 'non_empty'],
                ['path' => 'runtime.reverbPublicUrl', 'rule' => 'secure_realtime_url'],
                ['path' => 'runtime.allowedOrigins', 'rule' => 'origins'],
                ['path' => 'runtime.redisPingPassed', 'rule' => 'true'],
                ['path' => 'runtime.horizonRunning', 'rule' => 'true'],
                ['path' => 'runtime.schedulerHeartbeatFresh', 'rule' => 'true'],
                ['path' => 'runtime.reverbSocketPassed', 'rule' => 'true'],
                ['path' => 'runtime.privateChannelPassed', 'rule' => 'true'],
                ['path' => 'runtime.offlineCatchupPassed', 'rule' => 'true'],
            ],
            'external.desktop_e2e' => [
                ['path' => 'desktop.artifactReference', 'rule' => 'non_empty'],
                ['path' => 'desktop.artifactPath', 'rule' => 'non_empty'],
                ['path' => 'desktop.artifactSha256', 'rule' => 'equals_sha256', 'expected' => $artifactHash],
                ['path' => 'desktop.hostOs', 'rule' => 'non_empty'],
                ['path' => 'desktop.webviewVersion', 'rule' => 'non_empty'],
                ['path' => 'desktop.guiPassed', 'rule' => 'true'],
                ['path' => 'desktop.keychainRoundTripPassed', 'rule' => 'true'],
                ['path' => 'desktop.microphoneInputPassed', 'rule' => 'true'],
                ['path' => 'desktop.audioOutputPassed', 'rule' => 'true'],
                ['path' => 'desktop.notificationPermissionPassed', 'rule' => 'true'],
                ['path' => 'desktop.notificationClickPassed', 'rule' => 'true'],
                ['path' => 'desktop.filesystemApprovalPassed', 'rule' => 'true'],
                ['path' => 'desktop.computerApprovalPassed', 'rule' => 'true'],
            ],
            'external.signing_update_restore' => $signing,
        ];
    }

    private function valueAt(array $values, string $path): mixed
    {
        $value = $values;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function valueSatisfies(mixed $value, string $rule, mixed $expected): bool
    {
        return match ($rule) {
            'schema' => $value === self::SCHEMA_VERSION,
            'non_empty' => is_string($value) && trim($value) !== '',
            'true' => $value === true,
            'recent_iso8601' => $this->isRecentIso8601($value),
            'https_url' => $this->isSecureUrl($value, ['https']),
            'secure_realtime_url' => $this->isSecureUrl($value, ['https', 'wss']),
            'origins' => $this->originsAreConcrete($value),
            'targets' => $this->targetsAreSupported($value),
            'equals' => is_string($expected) && $expected !== '' && $value === $expected,
            'equals_sha256' => is_string($expected)
                && preg_match('/\A[a-f0-9]{64}\z/i', $expected) === 1
                && is_string($value)
                && hash_equals(strtolower($expected), strtolower($value)),
            default => false,
        };
    }

    private function isRecentIso8601(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $value);

        if ($parsed === false || $parsed->format(DateTimeImmutable::ATOM) !== $value) {
            return false;
        }

        $now = time();
        $timestamp = $parsed->getTimestamp();

        return $timestamp >= $now - (72 * 60 * 60) && $timestamp <= $now + (5 * 60);
    }

    /** @param list<string> $schemes */
    private function isSecureUrl(mixed $value, array $schemes): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), $schemes, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function originsAreConcrete(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && $value !== []
            && collect($value)->every(
                fn ($origin) => is_string($origin) && trim($origin) !== '' && ! str_contains($origin, '*')
            );
    }

    private function targetsAreSupported(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && $value !== []
            && count(array_unique($value)) === count($value)
            && collect($value)->every(fn ($target) => in_array($target, ['windows', 'macos', 'linux'], true));
    }

    /** @param list<array{id: string, status: string, missing: list<string>}> $checks */
    private function allPassed(array $checks): bool
    {
        return $checks !== [] && collect($checks)->every(fn (array $check) => $check['status'] === 'passed');
    }

    private function releaseVersion(string $root): ?string
    {
        $path = $this->join($root, 'app/package.json');
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }

        try {
            $package = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $version = is_array($package) ? ($package['version'] ?? null) : null;

        return is_string($version) && trim($version) !== '' ? $version : null;
    }

    private function artifactHash(string $root, array $evidence): ?string
    {
        $relativePath = $this->valueAt($evidence, 'desktop.artifactPath');
        if (! is_string($relativePath) || ! $this->isSafeRelativePath($relativePath)) {
            return null;
        }

        $resolved = realpath($this->join($root, $relativePath));
        if (! is_string($resolved) || ! is_file($resolved) || ! $this->pathIsWithin($resolved, $root)) {
            return null;
        }

        $hash = hash_file('sha256', $resolved);

        return is_string($hash) ? $hash : null;
    }

    /** @return array{passed: bool, detail: string} */
    private function probeLocalToolchain(string $root): array
    {
        if ($this->toolchainProbe instanceof Closure) {
            return ($this->toolchainProbe)($root);
        }

        $script = $this->join($root, 'app/scripts/release-readiness.cjs');
        if (! is_file($script)) {
            return ['passed' => false, 'detail' => 'app local release preflight'];
        }

        $command = ['node', $script, '--mode', 'local-test'];
        if (PHP_OS_FAMILY === 'Windows') {
            $wrapper = $this->join($root, 'app/scripts/with-pinned-node.ps1');
            if (! is_file($wrapper)) {
                return ['passed' => false, 'detail' => 'project-local pinned Node wrapper'];
            }

            $command = [
                'powershell.exe',
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $wrapper,
                'node.exe',
                $script,
                '--mode',
                'local-test',
            ];
        }

        $process = new Process($command, $this->join($root, 'app'));
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (Throwable) {
            return ['passed' => false, 'detail' => 'Node runtime for app local release preflight'];
        }

        if ($process->isSuccessful()) {
            return ['passed' => true, 'detail' => ''];
        }

        $output = trim($process->getErrorOutput()."\n".$process->getOutput());
        $detail = 'app local release preflight (run the dedicated script for details)';
        if (preg_match(
            '/ERROR:\s*runtime Node (v?[0-9]+\.[0-9]+\.[0-9]+) does not match the project pin (v?[0-9]+\.[0-9]+\.[0-9]+)/',
            $output,
            $matches,
        ) === 1) {
            $detail = 'Node runtime '.$matches[1].' does not match project pin '.$matches[2];
        } elseif (preg_match('/Node ([0-9]+\.[0-9]+\.[0-9]+) is not installed/', $output, $matches) === 1) {
            $detail = 'project-pinned Node '.$matches[1].' is not installed';
        }

        return ['passed' => false, 'detail' => $detail];
    }

    private function gitRevision(string $repositoryRoot): ?string
    {
        $gitPath = $this->join($repositoryRoot, '.git');
        $gitDirectory = $gitPath;
        if (is_file($gitPath)) {
            $pointer = file_get_contents($gitPath);
            if (! is_string($pointer) || preg_match('/\Agitdir:\s*(.+)\s*\z/i', $pointer, $matches) !== 1) {
                return null;
            }

            $candidate = trim($matches[1]);
            $gitDirectory = $this->isAbsolutePath($candidate)
                ? $candidate
                : $this->join($repositoryRoot, $candidate);
        }

        $gitDirectory = realpath($gitDirectory);
        if (! is_string($gitDirectory) || ! is_dir($gitDirectory)) {
            return null;
        }

        $commonDirectory = $gitDirectory;
        $commonPointer = $this->readSmallFile($this->join($gitDirectory, 'commondir'));
        if (is_string($commonPointer) && trim($commonPointer) !== '') {
            $candidate = trim($commonPointer);
            $candidate = $this->isAbsolutePath($candidate)
                ? $candidate
                : $this->join($gitDirectory, $candidate);
            $resolvedCommon = realpath($candidate);
            if (is_string($resolvedCommon) && is_dir($resolvedCommon)) {
                $commonDirectory = $resolvedCommon;
            }
        }

        $head = $this->readSmallFile($this->join($gitDirectory, 'HEAD'));
        if ($head === null) {
            return null;
        }

        $head = trim($head);
        if ($this->isGitObjectId($head)) {
            return strtolower($head);
        }

        if (preg_match('/\Aref:\s*(refs\/[A-Za-z0-9._\/-]+)\z/', $head, $matches) !== 1
            || str_contains($matches[1], '..')) {
            return null;
        }

        $reference = $matches[1];
        foreach (array_unique([$gitDirectory, $commonDirectory]) as $referenceRoot) {
            $loose = $this->readSmallFile($this->join($referenceRoot, $reference));
            if (is_string($loose) && $this->isGitObjectId(trim($loose))) {
                return strtolower(trim($loose));
            }
        }

        $packed = $this->readSmallFile($this->join($commonDirectory, 'packed-refs'), 1_000_000);
        if (! is_string($packed)) {
            return null;
        }

        foreach (preg_split('/\R/', $packed) ?: [] as $line) {
            if (preg_match('/\A([a-f0-9]{40}|[a-f0-9]{64})\s+(.+)\z/i', $line, $packedMatch) === 1
                && hash_equals($reference, $packedMatch[2])) {
                return strtolower($packedMatch[1]);
            }
        }

        return null;
    }

    private function readSmallFile(string $path, int $maxBytes = 4096): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);
        if (! is_int($size) || $size > $maxBytes) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    private function isGitObjectId(string $value): bool
    {
        return preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/i', $value) === 1;
    }

    private function isSafeRelativePath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || $this->isAbsolutePath($path)) {
            return false;
        }

        $segments = preg_split('/[\\\\\/]+/', $path);

        return is_array($segments)
            && collect($segments)->every(fn (string $segment) => $segment !== '' && $segment !== '.' && $segment !== '..');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $normalizedPath = strtolower(rtrim($path, '\\/'));
        $normalizedRoot = strtolower(rtrim((string) realpath($root), '\\/'));

        return $normalizedRoot !== ''
            && ($normalizedPath === $normalizedRoot
                || str_starts_with($normalizedPath, $normalizedRoot.DIRECTORY_SEPARATOR));
    }

    private function join(string $root, string $relativePath): string
    {
        return rtrim($root, '\\/').DIRECTORY_SEPARATOR.str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
    }
}
