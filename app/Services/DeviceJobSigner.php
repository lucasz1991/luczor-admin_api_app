<?php

namespace App\Services;

use App\Models\DeviceJob;

/** RSA signatures let a device reject forged server/job traffic before execution. */
class DeviceJobSigner
{
    public function sign(DeviceJob $job): string
    {
        return $this->signMessage($this->canonical($job));
    }

    public function signMessage(string $message): string
    {
        $privateKey = $this->privateKey();
        abort_unless($privateKey, 503, 'Device job signing is not configured.');

        $ok = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        abort_unless($ok, 500, 'Unable to sign device job.');

        return base64_encode($signature);
    }

    public function publicKey(): ?string
    {
        $privateKey = $this->privateKey();
        if (! $privateKey || ! ($resource = openssl_pkey_get_private($privateKey))) {
            return null;
        }
        $details = openssl_pkey_get_details($resource);

        return $details['key'] ?? null;
    }

    public function canonical(DeviceJob $job): string
    {
        return json_encode([
            'id' => $job->public_id,
            'tool_profile' => $job->tool_profile,
            'payload_hash' => $job->payload_hash,
            'expires_at' => $job->expires_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function privateKey(): string|false
    {
        $path = (string) config('luczor.device_jobs.private_key_file');
        if ($path !== '') {
            // PHP-FPM does not guarantee the application's working directory.
            // Treat configured relative paths as Laravel project paths so the
            // same .env value works for Artisan, FPM, and Windows services.
            $resolvedPath = $this->isAbsolutePath($path) ? $path : base_path($path);
            if (is_readable($resolvedPath)) {
                $privateKey = file_get_contents($resolvedPath);
                if ($privateKey !== false) {
                    return $privateKey;
                }
            }
        }

        return (string) config('luczor.device_jobs.private_key');
    }

    private function isAbsolutePath(string $path): bool
    {
        // Unix root paths, Windows drive paths, and UNC/extended Windows paths.
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1;
    }
}
