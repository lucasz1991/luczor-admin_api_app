<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class WindowsAbsoluteCachePathBootstrapTest extends TestCase
{
    public function test_bootstrap_keeps_drive_absolute_cache_path_replaceable(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows drive-prefix behavior is Windows-specific.');
        }

        $environmentName = 'APP_PACKAGES_CACHE';
        $hadEnvValue = array_key_exists($environmentName, $_ENV);
        $previousEnvValue = $_ENV[$environmentName] ?? null;
        $hadServerValue = array_key_exists($environmentName, $_SERVER);
        $previousServerValue = $_SERVER[$environmentName] ?? null;
        $previousProcessValue = getenv($environmentName);
        $temporaryRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'luczor-cache-prefix-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryRoot, 0700));
        $cachePath = str_replace('\\', '/', $temporaryRoot.DIRECTORY_SEPARATOR.'packages.php');

        putenv($environmentName.'='.$cachePath);
        $_ENV[$environmentName] = $cachePath;
        $_SERVER[$environmentName] = $cachePath;

        try {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

            $this->assertSame($cachePath, $app->getCachedPackagesPath());

            (new Filesystem)->replace($app->getCachedPackagesPath(), '<?php return [];');

            $this->assertSame('<?php return [];', file_get_contents($cachePath));
        } finally {
            if ($previousProcessValue === false) {
                putenv($environmentName);
            } else {
                putenv($environmentName.'='.$previousProcessValue);
            }

            if ($hadEnvValue) {
                $_ENV[$environmentName] = $previousEnvValue;
            } else {
                unset($_ENV[$environmentName]);
            }

            if ($hadServerValue) {
                $_SERVER[$environmentName] = $previousServerValue;
            } else {
                unset($_SERVER[$environmentName]);
            }

            if (is_file($cachePath)) {
                unlink($cachePath);
            }
            if (is_dir($temporaryRoot)) {
                rmdir($temporaryRoot);
            }
        }
    }
}
