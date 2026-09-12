<?php

namespace App\Services;

use App\Models\ProjectCloudFile;
use Illuminate\Validation\ValidationException;

class CloudProjectFiles
{
    public const MAX_FILE_BYTES = 1_048_576;

    public const MAX_PROJECT_BYTES = 20_971_520;

    public const MAX_FILES = 200;

    public const MAX_PATHS = 2000;

    public function path(string $path): string
    {
        $segments = explode('/', $path);
        $invalid = $path === '' || strlen($path) > 240 || count($segments) > 16
            || preg_match('/[\\\\:\x00-\x1f\x7f<>"|?*]/u', $path);

        foreach ($segments as $segment) {
            // Portable relative names, including on Windows. No credentials or VCS internals.
            $invalid = $invalid || $segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[. ]$/u', $segment)
                || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $segment)
                || preg_match('/^(\.env(?:\..*)?|\.git|\.ssh|\.aws|\.azure|\.npmrc|\.pypirc|credentials(?:\..*)?|id_rsa|id_ed25519)$/i', $segment);
        }

        if ($invalid) {
            throw ValidationException::withMessages(['path' => 'Choose a portable relative file name without credentials, device paths or repository internals.']);
        }

        return $path;
    }

    public function key(string $path): string
    {
        // Windows and Linux devices cannot create ambiguous case-only variants.
        return hash('sha256', mb_strtolower($path, 'UTF-8'));
    }

    public function metadata(ProjectCloudFile $file): array
    {
        return [
            'path' => $file->path,
            'revision' => $file->revision,
            'bytes' => $file->bytes,
            'sha256' => $file->sha256,
            'deleted' => $file->deleted,
            'updated_at' => $file->updated_at?->toISOString(),
            'updated_by_device' => $file->updated_by_device,
        ];
    }
}
