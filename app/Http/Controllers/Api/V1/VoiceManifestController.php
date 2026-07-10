<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceJobSigner;

class VoiceManifestController extends Controller
{
    public function __invoke(DeviceJobSigner $signer)
    {
        $manifest = config('luczor.voice.manifest', []);
        abort_unless(is_array($manifest) && ! empty($manifest['version']) && ! empty($manifest['assets']), 503, 'No signed voice release manifest is published.');
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->json([
            'algorithm' => 'RSA-SHA256',
            'payload_json' => $payload,
            'signature' => $signer->signMessage($payload),
        ]);
    }
}
