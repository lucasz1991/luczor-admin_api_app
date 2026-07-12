<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceJobSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class VoiceManifestController extends Controller
{
    public function __invoke(DeviceJobSigner $signer)
    {
        $manifest = config('luczor.voice.manifest', []);
        $path = (string) config('luczor.voice.manifest_file', '');
        $resolvedManifestPath = $this->resolveConfiguredPath($path);
        if (empty($manifest) && $resolvedManifestPath !== '' && File::isFile($resolvedManifestPath)) {
            $stored = json_decode(File::get($resolvedManifestPath), true);
            $manifest = is_array($stored) ? $stored : [];
            if (isset($manifest['payload_json']) && is_string($manifest['payload_json'])) {
                $manifest = json_decode($manifest['payload_json'], true) ?: [];
            }
        }
        abort_unless(is_array($manifest) && ! empty($manifest['version']) && ! empty($manifest['assets']), 503, 'No signed voice release manifest is published.');
        // Voice releases are production-only and always originate from the configured HTTPS domain.
        $origin = rtrim((string) config('app.url'), '/');
        abort_unless(str_starts_with($origin, 'https://'), 503, 'Voice release origin must use HTTPS.');
        $hasOnlyStableAssetUrls = true;
        foreach ($manifest['assets'] as &$asset) {
            if (is_array($asset) && is_string($asset['file_name'] ?? null) && $asset['file_name'] !== '') {
                $asset['url'] = $origin.'/api/v1/voice/releases/'.rawurlencode((string) $manifest['version']).'/'.rawurlencode((string) $asset['file_name']);
            } else {
                // Do not cache a manifest that may contain externally signed,
                // time-limited URLs. Published Luczor releases always have a
                // file_name and therefore use the stable route above.
                $hasOnlyStableAssetUrls = false;
            }
        }
        unset($asset);
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $envelope = $hasOnlyStableAssetUrls
            ? Cache::rememberForever($this->cacheKey((string) $manifest['version'], $payload, $signer), fn () => $this->signedEnvelope($payload, $signer))
            : $this->signedEnvelope($payload, $signer);

        return response()->json($envelope);
    }

    /**
     * Release assets are served from immutable versioned paths. The cache key
     * changes for the release, canonical manifest payload/origin, and signing
     * key, so neither a changed release nor a rotated signing key can reuse an
     * older envelope.
     */
    private function cacheKey(string $version, string $payload, DeviceJobSigner $signer): string
    {
        $signingKey = $signer->publicKey() ?: '';

        return 'luczor:voice-manifest:'.hash('sha256', $version."\0".$payload."\0".hash('sha256', $signingKey));
    }

    /** @return array{algorithm: string, payload_json: string, signature: string} */
    private function signedEnvelope(string $payload, DeviceJobSigner $signer): array
    {
        return [
            'algorithm' => 'RSA-SHA256',
            'payload_json' => $payload,
            'signature' => $signer->signMessage($payload),
        ];
    }

    /** Resolve Laravel-relative configuration independently of PHP-FPM's CWD. */
    private function resolveConfiguredPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1 ? $path : base_path($path);
    }
}
