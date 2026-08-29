<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RedisPleskDeploymentTest extends TestCase
{
    public function test_deployment_shell_scripts_are_persistently_normalized_to_lf(): void
    {
        $root = dirname(__DIR__, 2);
        $attributes = file_get_contents($root.'/.gitattributes');

        $this->assertIsString($attributes);
        $this->assertStringContainsString('*.sh text eol=lf', $attributes);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        $shellScripts = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'sh') {
                $shellScripts[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($shellScripts);
        foreach ($shellScripts as $script) {
            $contents = file_get_contents($script);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString("\r", $contents, $script.' must use LF line endings.');
        }
    }

    public function test_compose_keeps_authenticated_redis_private_and_persistent(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.plesk-memory.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('image: redis:7.4.11-alpine', $compose);
        $this->assertStringContainsString('127.0.0.1:${REDIS_HOST_PORT:-6379}:6379', $compose);
        $this->assertMatchesRegularExpression(
            '/redis:\R\s+#.*?profiles:\R\s+- redis-cutover/s',
            $compose,
        );
        $this->assertStringContainsString('${LUCZOR_REDIS_DATA_DIR:-/var/lib/luczor/redis}:/data', $compose);
        $this->assertStringContainsString('/run/secrets/redis_password', $compose);
        $this->assertStringContainsString('/tmp:size=1m,mode=1777', $compose);
        $this->assertStringNotContainsString('/run/luczor-redis:size=', $compose);
        $this->assertStringContainsString('read_only: true', $compose);
        $this->assertStringNotContainsString('- "0.0.0.0:', $compose);
        $this->assertStringNotContainsString('--requirepass', $compose);
    }

    public function test_entrypoint_never_passes_the_password_as_a_process_argument(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/redis/entrypoint.sh');

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("grep -Eq '^[A-Za-z0-9+/_=.-]{32,}$'", $entrypoint);
        $this->assertStringContainsString('runtime_directory=/tmp/luczor-redis', $entrypoint);
        $this->assertStringContainsString("printf 'requirepass %s\\n' \"\$password\"", $entrypoint);
        $this->assertStringContainsString('exec gosu redis redis-server "$config_file"', $entrypoint);
        $this->assertStringNotContainsString('redis-server --requirepass', $entrypoint);
        $this->assertStringNotContainsString('echo "$password"', $entrypoint);
    }

    public function test_runbook_makes_kernel_and_secret_cutover_explicit(): void
    {
        $runbook = file_get_contents(dirname(__DIR__, 2).'/docs/redis-plesk.md');

        $this->assertIsString($runbook);
        $this->assertStringContainsString('vm.overcommit_memory = 1', $runbook);
        $this->assertStringContainsString('LUCZOR_DOCKER_SECRETS_DIR=/var/lib/luczor/secrets', $runbook);
        $this->assertStringContainsString('REDIS_PASSWORD_FILE=', $runbook);
        $this->assertStringContainsString('luczor:deployment-check --production', $runbook);
        $this->assertStringContainsString('NOAUTH Authentication required.', $runbook);
        $this->assertStringNotContainsString('REDIS_PASSWORD=replace-with', $runbook);
    }

    public function test_file_backed_redis_secret_is_loaded_before_other_providers_boot(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 2).'/app/Providers/AppServiceProvider.php');

        $this->assertIsString($provider);
        $register = strpos($provider, 'public function register(): void');
        $apply = strpos($provider, 'RedisSecretConfigurator::class)->apply()');
        $boot = strpos($provider, 'public function boot(): void');

        $this->assertIsInt($register);
        $this->assertIsInt($apply);
        $this->assertIsInt($boot);
        $this->assertGreaterThan($register, $apply);
        $this->assertLessThan($boot, $apply);
    }
}
