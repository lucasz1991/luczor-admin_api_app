<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceJobSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class VoiceManifestController extends Controller
{
    public function __invoke(Request $request, DeviceJobSigner $signer)
    {
        $manifest = config('luczor.voice.manifest', []);
        $path = (string) config('luczor.voice.manifest_file', '');
        if (empty($manifest) && $path !== '' && File::isFile($path)) {
            $stored = json_decode(File::get($path), true);
            $manifest = is_array($stored) ? $stored : [];
            if (isset($manifest['payload_json']) && is_string($manifest['payload_json'])) {
                $manifest = json_decode($manifest['payload_json'], true) ?: [];
            }
        }
        abort_unless(is_array($manifest) && ! empty($manifest['version']) && ! empty($manifest['assets']), 503, 'No signed voice release manifest is published.');
        // Bind assets to the same trusted origin that served the signed manifest.
        // This makes localhost development and the production domain use one release file.
        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        foreach ($manifest['assets'] as &$asset) {
            if (is_array($asset) && isset($asset['file_name'])) {
                $asset['url'] = $origin.'/api/v1/voice/releases/'.rawurlencode((string) $manifest['version']).'/'.rawurlencode((string) $asset['file_name']);
            }
        }
        unset($asset);
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->json([
            'algorithm' => 'RSA-SHA256',
            'payload_json' => $payload,
            'signature' => $signer->signMessage($payload),
        ]);
    }
}
