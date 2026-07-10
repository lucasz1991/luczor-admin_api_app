<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

/** Serves only manifest-listed, hash-pinned public voice runtime artifacts. */
class VoiceAssetController extends Controller
{
    public function __invoke(string $version, string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $version) === 1, 404);
        abort_unless(basename($file) === $file && preg_match('/^[A-Za-z0-9._-]+$/', $file) === 1, 404);

        $manifestPath = (string) config('luczor.voice.manifest_file', '');
        abort_unless($manifestPath !== '' && File::isFile($manifestPath), 404);
        $envelope = json_decode(File::get($manifestPath), true) ?: [];
        $manifest = isset($envelope['payload_json']) ? json_decode((string) $envelope['payload_json'], true) : $envelope;
        abort_unless(is_array($manifest) && ($manifest['version'] ?? null) === $version, 404);
        abort_unless(collect($manifest['assets'] ?? [])->contains(fn ($asset) => is_array($asset) && ($asset['file_name'] ?? null) === $file), 404);

        $path = rtrim((string) config('luczor.voice.release_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$version.DIRECTORY_SEPARATOR.$file;
        abort_unless(File::isFile($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
