<?php

namespace App\Services;

use RuntimeException;

class RedisSecretConfigurator
{
    private const MAX_SECRET_BYTES = 4096;

    public function apply(): void
    {
        $path = trim((string) config('database.redis.password_file', ''));
        if ($path === '') {
            return;
        }

        if (! $this->isAbsolutePath($path)) {
            throw new RuntimeException('REDIS_PASSWORD_FILE must be an absolute path.');
        }

        foreach (['default', 'cache'] as $connection) {
            if ($this->filled(config("database.redis.$connection.url"))) {
                throw new RuntimeException('REDIS_PASSWORD_FILE cannot be combined with REDIS_URL.');
            }
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException('REDIS_PASSWORD_FILE is missing or unreadable.');
        }

        $size = filesize($resolvedPath);
        if (! is_int($size) || $size < 32 || $size > self::MAX_SECRET_BYTES) {
            throw new RuntimeException('REDIS_PASSWORD_FILE must contain between 32 and 4096 bytes.');
        }

        $contents = file_get_contents($resolvedPath);
        if (! is_string($contents)) {
            throw new RuntimeException('REDIS_PASSWORD_FILE could not be read.');
        }

        $password = preg_replace('/(?:\r\n|\n|\r)\z/', '', $contents, 1);
        if (! is_string($password)
            || strlen($password) < 32
            || str_contains($password, "\0")
            || str_contains($password, "\r")
            || str_contains($password, "\n")) {
            throw new RuntimeException('REDIS_PASSWORD_FILE must contain one non-empty line of at least 32 bytes.');
        }

        // ConfigCacheCommand boots a fresh application before serializing all
        // configuration. Validate the file in that process, but only hydrate
        // the secret during normal application/worker command bootstraps.
        if ($this->isBuildingConfigurationCache()) {
            return;
        }

        config([
            'database.redis.default.password' => $password,
            'database.redis.cache.password' => $password,
            'reverb.servers.reverb.scaling.server.password' => $password,
        ]);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isBuildingConfigurationCache(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? null;

        return is_string($command) && in_array($command, ['config:cache', 'optimize'], true);
    }
}
