<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerSecretDeploymentTest extends TestCase
{
    public function test_runtime_secret_directory_is_excluded_at_the_repository_root(): void
    {
        $root = dirname(__DIR__, 2);
        $ignore = file_get_contents($root.'/.gitignore');
        $cacheIgnore = file_get_contents($root.'/storage/framework/cache/.gitignore');

        $this->assertIsString($ignore);
        $this->assertIsString($cacheIgnore);
        $this->assertStringContainsString('/docker/secrets/', $ignore);
        $this->assertFileDoesNotExist($root.'/docker/secrets/.gitignore');
        $this->assertSame("*\n!.gitignore\n", str_replace("\r\n", "\n", $cacheIgnore));
    }

    public function test_every_plesk_compose_secret_uses_the_configurable_external_root(): void
    {
        $compose = $this->readProjectFile('docker-compose.plesk-memory.yml');

        $matches = preg_match_all(
            '/file:\s+"\$\{LUCZOR_DOCKER_SECRETS_DIR:-\.\/docker\/secrets\}\/[a-z_]+"/',
            $compose,
        );

        $this->assertSame(7, $matches);
        $this->assertStringNotContainsString('file: ./docker/secrets/', $compose);
    }

    public function test_generators_and_provisioners_share_the_same_secret_path_contract(): void
    {
        $shellInitializer = $this->readProjectFile('docker/init-secrets.sh');
        $powershellInitializer = $this->readProjectFile('docker/init-secrets.ps1');
        $shellProvisioner = $this->readProjectFile('docker/provision-cognee.sh');
        $powershellProvisioner = $this->readProjectFile('docker/provision-cognee.ps1');

        foreach ([$shellInitializer, $powershellInitializer, $shellProvisioner, $powershellProvisioner] as $script) {
            $this->assertStringContainsString('LUCZOR_DOCKER_SECRETS_DIR', $script);
        }

        $this->assertStringContainsString('umask 077', $shellInitializer);
        $this->assertStringContainsString('chmod 700 "$dir"', $shellInitializer);
        $this->assertStringContainsString('chmod 600', $shellInitializer);
        $this->assertStringContainsString('export LUCZOR_DOCKER_SECRETS_DIR="$secret_directory"', $shellProvisioner);
        $this->assertStringContainsString('SetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR"', $powershellProvisioner);
    }

    public function test_secret_initializers_create_a_dedicated_atomic_rsa_3072_local_model_signing_key(): void
    {
        $shell = $this->readProjectFile('docker/init-secrets.sh');
        $powershell = $this->readProjectFile('docker/init-secrets.ps1');

        $this->assertStringContainsString(
            'local_model_signing_key="$dir/luczor_local_model_signing_private_key"',
            $shell,
        );
        $this->assertStringContainsString(
            'mktemp "$dir/.luczor_local_model_signing_private_key.XXXXXX"',
            $shell,
        );
        $this->assertStringContainsString('openssl genrsa -out "$temporary_file" 3072', $shell);
        $this->assertStringContainsString(
            'install_new_secret luczor_local_model_signing_private_key "$temporary_file"',
            $shell,
        );
        $this->assertStringContainsString('[ "$local_model_key_bits" -lt 3072 ]', $shell);
        $this->assertStringContainsString('LUCZOR_LOCAL_MODEL_EXPECTED_PUBLIC_KEY_SHA256=', $shell);

        $this->assertStringContainsString(
            'Join-Path $SecretDirectory "luczor_local_model_signing_private_key"',
            $powershell,
        );
        $this->assertStringContainsString(
            'Assert-SafeSecretFile $localModelSigningKey',
            $powershell,
        );
        $this->assertStringContainsString("''private_key_bits'' => 3072", $powershell);
        $this->assertStringContainsString(
            '[IO.File]::Move($temporaryLocalModelSigningKey, $localModelSigningKey)',
            $powershell,
        );
        $this->assertStringContainsString("(\$details[''bits''] ?? 0) < 3072", $powershell);
        $this->assertStringContainsString('LUCZOR_LOCAL_MODEL_EXPECTED_PUBLIC_KEY_SHA256=', $powershell);
    }

    public function test_examples_and_runbooks_require_an_external_production_directory(): void
    {
        $example = $this->readProjectFile('.env.example');
        $deployment = $this->readProjectFile('docs/cognee-deployment.md');
        $localCognee = $this->readProjectFile('docs/cognee-local-ollama.md');
        $redis = $this->readProjectFile('docs/redis-plesk.md');

        $this->assertStringContainsString('LUCZOR_DOCKER_SECRETS_DIR=', $example);
        foreach ([$deployment, $localCognee, $redis] as $runbook) {
            $this->assertStringContainsString('/var/lib/luczor/secrets', $runbook);
        }
    }

    public function test_plesk_permission_repair_is_manifest_scoped_and_fails_closed(): void
    {
        $script = $this->readProjectFile('docker/repair-plesk-git-permissions.sh');
        $runbook = $this->readProjectFile('docs/plesk-git-deployment.md');

        $this->assertStringContainsString('git --git-dir="$bare_repository" ls-tree -r -z', $script);
        $this->assertStringContainsString('docker/secrets|docker/secrets/*', $script);
        $this->assertStringContainsString('storage/*)', $script);
        $this->assertStringNotContainsString('chown -R', $script);
        $this->assertStringContainsString('getfacl -h -p', $script);
        $this->assertStringContainsString('setfacl --restore=', $runbook);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents);

        return $contents;
    }
}
