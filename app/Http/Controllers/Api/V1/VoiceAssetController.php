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

        $manifestPath = $this->resolveConfiguredPath((string) config('luczor.voice.manifest_file', ''));
        abort_unless($manifestPath !== '' && File::isFile($manifestPath), 404);
        $envelope = json_decode(File::get($manifestPath), true) ?: [];
        $manifest = isset($envelope['payload_json']) ? json_decode((string) $envelope['payload_json'], true) : $envelope;
        abort_unless(is_array($manifest) && ($manifest['version'] ?? null) === $version, 404);
        abort_unless(collect($manifest['assets'] ?? [])->contains(fn ($asset) => is_array($asset) && ($asset['file_name'] ?? null) === $file), 404);

        $releaseRoot = (string) config('luczor.voice.release_root', '');
        abort_unless($releaseRoot !== '', 404);
        $path = rtrim($releaseRoot, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$version.DIRECTORY_SEPARATOR.$file;
        abort_unless(File::isFile($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve a configured path that may be relative. Under PHP-FPM the CWD is
     * not the project root, so a relative LUCZOR_VOICE_MANIFEST_FILE (as written
     * by bootstrap-voice-release.ps1) must be anchored to base_path(); absolute
     * Windows-drive/UNC/Unix paths are kept as-is. Mirrors VoiceManifestController.
     */
    private function resolveConfiguredPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1
            ? $path
            : base_path($path);
    }
}
