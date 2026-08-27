<?php

namespace Tests\Unit;

use App\Services\RedisSecretConfigurator;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class RedisSecretConfiguratorTest extends TestCase
{
    private ?string $temporarySecret = null;

    protected function tearDown(): void
    {
        if ($this->temporarySecret !== null && is_file($this->temporarySecret)) {
            unlink($this->temporarySecret);
        }

        parent::tearDown();
    }

    public function test_absolute_secret_file_hydrates_every_runtime_redis_client(): void
    {
        $password = str_repeat('s', 48);
        $this->temporarySecret = tempnam(sys_get_temp_dir(), 'luczor-redis-secret-');
        $this->assertIsString($this->temporarySecret);
        file_put_contents($this->temporarySecret, $password."\n");

        Config::set('database.redis.password_file', $this->temporarySecret);
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.cache.url', null);
        Config::set('database.redis.horizon.url', null);

        app(RedisSecretConfigurator::class)->apply();

        $this->assertSame($password, Config::get('database.redis.default.password'));
        $this->assertSame($password, Config::get('database.redis.cache.password'));
        $this->assertSame($password, Config::get('database.redis.horizon.password'));
        $this->assertSame($password, Config::get('reverb.servers.reverb.scaling.server.password'));
    }

    public function test_relative_secret_file_fails_closed(): void
    {
        Config::set('database.redis.password_file', 'docker/secrets/redis_password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an absolute path');

        app(RedisSecretConfigurator::class)->apply();
    }

    public function test_config_cache_build_validates_without_serializing_the_secret(): void
    {
        $password = str_repeat('c', 48);
        $this->temporarySecret = tempnam(sys_get_temp_dir(), 'luczor-redis-secret-');
        $this->assertIsString($this->temporarySecret);
        file_put_contents($this->temporarySecret, $password);

        Config::set('database.redis.password_file', $this->temporarySecret);
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.cache.url', null);
        Config::set('database.redis.default.password', null);
        Config::set('database.redis.cache.password', null);
        Config::set('database.redis.horizon.password', null);

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'config:cache'];

        try {
            app(RedisSecretConfigurator::class)->apply();
        } finally {
            if (is_array($originalArgv)) {
                $_SERVER['argv'] = $originalArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $this->assertNull(Config::get('database.redis.default.password'));
        $this->assertNull(Config::get('database.redis.cache.password'));
        $this->assertNull(Config::get('database.redis.horizon.password'));
        $this->assertNotSame($password, Config::get('reverb.servers.reverb.scaling.server.password'));
    }

    public function test_secret_file_cannot_be_combined_with_redis_url(): void
    {
        $this->temporarySecret = tempnam(sys_get_temp_dir(), 'luczor-redis-secret-');
        $this->assertIsString($this->temporarySecret);
        file_put_contents($this->temporarySecret, str_repeat('s', 48));

        Config::set('database.redis.password_file', $this->temporarySecret);
        Config::set('database.redis.default.url', 'redis://127.0.0.1:6379');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be combined with REDIS_URL');

        app(RedisSecretConfigurator::class)->apply();
    }

    public function test_multiline_or_short_secret_fails_closed(): void
    {
        $this->temporarySecret = tempnam(sys_get_temp_dir(), 'luczor-redis-secret-');
        $this->assertIsString($this->temporarySecret);
        file_put_contents($this->temporarySecret, "short\nsecond-line");

        Config::set('database.redis.password_file', $this->temporarySecret);
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.cache.url', null);

        $this->expectException(RuntimeException::class);

        app(RedisSecretConfigurator::class)->apply();
    }
}
