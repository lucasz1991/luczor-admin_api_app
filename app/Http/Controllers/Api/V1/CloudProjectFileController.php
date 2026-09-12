<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectCloudFile;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\CloudProjectFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CloudProjectFileController extends Controller
{
    public function index(Request $request, Project $project, ApiActor $actor, CloudProjectFiles $files): JsonResponse
    {
        $this->authorizeProject($request, $project, $actor);

        return response()->json(['data' => ProjectCloudFile::query()->where('project_id', $project->id)
            ->where('deleted', false)->orderBy('path')->get()->map(fn ($file) => $files->metadata($file))]);
    }

    public function show(Request $request, Project $project, ApiActor $actor, CloudProjectFiles $files): JsonResponse
    {
        $this->authorizeProject($request, $project, $actor);
        $data = $request->validate(['path' => ['required', 'string', 'max:240']]);
        $path = $files->path($data['path']);

        // Serialize the read against replacement so the old blob cannot disappear mid-read.
        return DB::transaction(function () use ($project, $path, $files) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $file = ProjectCloudFile::query()->where('project_id', $project->id)
                ->where('path_key', $files->key($path))->where('deleted', false)->firstOrFail();
            $content = Crypt::decryptString(Storage::disk('cloud-projects')->get($file->storage_key));

            return response()->json(['data' => $files->metadata($file) + ['content' => $content]])
                ->header('Cache-Control', 'private, no-store');
        });
    }

    public function update(Request $request, Project $project, ApiActor $actor, CloudProjectFiles $files, AuditLogger $audit): JsonResponse
    {
        return $this->mutate($request, $project, $actor, $files, $audit, false);
    }

    public function destroy(Request $request, Project $project, ApiActor $actor, CloudProjectFiles $files, AuditLogger $audit): JsonResponse
    {
        return $this->mutate($request, $project, $actor, $files, $audit, true);
    }

    private function mutate(Request $request, Project $project, ApiActor $actor, CloudProjectFiles $files, AuditLogger $audit, bool $delete): JsonResponse
    {
        $this->authorizeProject($request, $project, $actor);
        if (strlen($request->getContent()) > 6 * CloudProjectFiles::MAX_FILE_BYTES + 4096) {
            throw ValidationException::withMessages(['content' => 'A project file may not exceed 1 MiB.']);
        }
        // Read original JSON to preserve whitespace and empty files across TrimStrings middleware.
        $body = json_decode($request->getContent(), true, 8);
        $data = Validator::make(is_array($body) ? $body : [], [
            'path' => ['required', 'string', 'max:240'],
            'expected_revision' => ['required', 'integer', 'min:0', 'max:9007199254740990'],
            'content' => $delete ? ['prohibited'] : ['present', 'string'],
        ])->validate();
        $path = $files->path($data['path']);
        $content = $delete ? '' : $data['content'];
        if (strlen($content) > CloudProjectFiles::MAX_FILE_BYTES || str_contains($content, "\0")) {
            throw ValidationException::withMessages(['content' => 'A project file must be UTF-8 text of at most 1 MiB.']);
        }
        $device = $actor->deviceId($request);
        $disk = Storage::disk('cloud-projects');
        $newKey = null;
        $oldKey = null;

        try {
            // Lock the project as well as the file: concurrent files cannot exceed the shared quota.
            $response = DB::transaction(function () use ($request, $project, $actor, $files, $audit, $delete, $data, $path, $content, $device, $disk, &$newKey, &$oldKey) {
                $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $this->authorizeProject($request, $locked, $actor);
                $query = ProjectCloudFile::query()->where('project_id', $project->id);
                $file = (clone $query)->where('path_key', $files->key($path))->first();
                if ((int) ($file?->revision ?? 0) !== (int) $data['expected_revision']) {
                    return response()->json([
                        'code' => 'project_file_revision_conflict',
                        'message' => 'This file has changed on another device. Retrieve its current version before saving.',
                        'data' => $file ? $files->metadata($file) : null,
                    ], 409);
                }
                if ($delete) {
                    abort_unless($file && ! $file->deleted, 404);
                } else {
                    $active = (clone $query)->where('deleted', false);
                    $adding = ! $file || $file->deleted;
                    if (! $file && (clone $query)->count() >= CloudProjectFiles::MAX_PATHS) {
                        throw ValidationException::withMessages(['path' => 'This project has reached its limit of 2,000 versioned file paths.']);
                    }
                    if (($adding && (clone $active)->count() >= CloudProjectFiles::MAX_FILES)
                        || (int) (clone $active)->sum('bytes') - ($file && ! $file->deleted ? $file->bytes : 0) + strlen($content) > CloudProjectFiles::MAX_PROJECT_BYTES) {
                        throw ValidationException::withMessages(['content' => 'Cloud project storage is limited to 200 files and 20 MiB.']);
                    }
                    $newKey = $project->user_id.'/'.$project->id.'/'.Str::uuid().'.enc';
                    if (! $disk->put($newKey, Crypt::encryptString($content))) {
                        throw new RuntimeException('The private project file could not be stored.');
                    }
                }
                $oldKey = $file?->storage_key;
                $file ??= new ProjectCloudFile(['project_id' => $project->id, 'path_key' => $files->key($path), 'revision' => 0]);
                $file->fill([
                    'path' => $path, 'storage_key' => $newKey, 'revision' => $file->revision + 1,
                    'bytes' => $delete ? 0 : strlen($content), 'sha256' => $delete ? null : hash('sha256', $content),
                    'deleted' => $delete, 'updated_by_device' => $device,
                ])->save();
                $locked->touch();
                $audit->record([
                    'actor_user_id' => $actor->userId($request), 'project_id' => $project->id,
                    'device_id' => $device === null ? null : Device::query()->where('user_id', $actor->userId($request))->where('device_id', $device)->value('id'),
                    'event_type' => $delete ? 'project.cloud.file.deleted' : 'project.cloud.file.saved', 'outcome' => 'completed',
                    'payload' => ['file_id' => $file->id, 'revision' => $file->revision, 'bytes' => $file->bytes],
                ]);

                return response()->json(['data' => $files->metadata($file)]);
            });
        } catch (Throwable $error) {
            if ($newKey !== null) {
                $disk->delete($newKey);
            }
            throw $error;
        }

        if ($oldKey !== null) {
            // The database points to the replacement before the superseded blob is removed.
            try {
                $disk->delete($oldKey);
            } catch (Throwable $error) {
                // A cleanup issue must not disguise a successfully committed revision as a failed save.
                report($error);
            }
        }

        return $response;
    }

    private function authorizeProject(Request $request, Project $project, ApiActor $actor): void
    {
        abort_unless((int) $project->user_id === $actor->userId($request) && $project->cloud_enabled, 404);
    }
}
