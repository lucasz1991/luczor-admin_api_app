<?php

namespace App\Services;

use App\Exceptions\LocalModelManifestConfigurationException;

final class ManagedLocalModelKey
{
    public function path(): string
    {
        $directory = rtrim((string) config('local_models.signing.managed_directory'), '/');
        $lock = null;
        $temporary = null;
        try {
            if (! str_starts_with($directory, '/') || is_link($directory)) {
                throw new \RuntimeException;
            }
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException;
            }
            $real = realpath($directory);
            $checkout = realpath(base_path());
            if ($real === false || $checkout === false || $real === $checkout || str_starts_with($real, $checkout.'/')) {
                throw new \RuntimeException;
            }
            if ((fileperms($real) & 0077) !== 0) {
                throw new \RuntimeException;
            }
            $path = $real.'/local-model-private.pem';
            $lockPath = $real.'/local-model.lock';
            if (is_link($lockPath) || is_link($path)) {
                throw new \RuntimeException;
            }
            $lock = fopen($lockPath, 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException;
            }
            // Never replace an existing key, including a corrupt or unreadable one.
            if (! file_exists($path)) {
                $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                if ($key === false || ! openssl_pkey_export($key, $pem)) {
                    throw new \RuntimeException;
                }
                $temporary = tempnam($real, '.local-model-');
                if ($temporary === false || ! chmod($temporary, 0600)
                    || file_put_contents($temporary, $pem) !== strlen($pem)
                    || ! rename($temporary, $path)) {
                    throw new \RuntimeException;
                }
                $temporary = null;
            }
            if (! is_file($path) || is_link($path) || ! is_readable($path) || ! chmod($path, 0600)) {
                throw new \RuntimeException;
            }

            return $path;
        } catch (\Throwable) {
            throw new LocalModelManifestConfigurationException('local_model_managed_key_unavailable');
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
