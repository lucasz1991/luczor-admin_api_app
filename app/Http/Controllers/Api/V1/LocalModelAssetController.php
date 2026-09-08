<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LocalModelManifestService;

final class LocalModelAssetController extends Controller
{
    public function __invoke(string $hash, LocalModelManifestService $manifest)
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1, 404);
        $allowed = [];
        foreach ($manifest->payload()['models'] as $model) {
            if (! $model['enabled'] || ! is_array($model['runtime'])) {
                continue;
            }
            $allowed[] = $model['runtime']['sha256'];
            foreach ($model['runtime']['files'] ?? [] as $file) {
                $allowed[] = $file['sha256'];
            }
        }
        abort_unless(in_array($hash, $allowed, true), 404);
        $root = realpath((string) config('local_models.asset_directory'));
        abort_unless($root !== false, 404);
        $path = $root.DIRECTORY_SEPARATOR.$hash;
        abort_unless(! is_link($path) && is_file($path) && is_readable($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
