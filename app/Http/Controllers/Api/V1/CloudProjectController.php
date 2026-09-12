<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Project;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\CloudProjectSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudProjectController extends Controller
{
    public function show(Request $request, Project $project, ApiActor $actor): JsonResponse
    {
        $this->authorizeOwner($request, $project, $actor);
        abort_unless($project->cloud_enabled, 404);

        return response()->json(['data' => $this->document($project)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Project $project, ApiActor $actor, CloudProjectSnapshot $snapshot, AuditLogger $audit): JsonResponse
    {
        $this->authorizeOwner($request, $project, $actor);
        $data = $snapshot->validate($request);
        $device = $actor->deviceId($request);

        return DB::transaction(function () use ($project, $data, $device, $request, $actor, $audit) {
            $current = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($request, $current, $actor);
            if ($current->cloud_revision !== (int) $data['expected_revision']) {
                return response()->json([
                    'code' => 'project_revision_conflict',
                    'message' => 'Another device has saved this project. Retrieve and compare its current state before saving.',
                    'data' => $this->document($current),
                ], 409);
            }

            $current->cloud_enabled = true;
            $current->cloud_revision++;
            $current->cloud_snapshot = $data['snapshot'];
            $current->cloud_updated_by_device = $device;
            $current->name = $data['snapshot']['project']['name'];
            $current->status = empty($data['snapshot']['project']['archivedAt']) ? 'active' : 'archived';
            $current->save();
            $audit->record([
                'actor_user_id' => $actor->userId($request),
                'device_id' => $device === null ? null : Device::query()->where('user_id', $actor->userId($request))->where('device_id', $device)->value('id'),
                'project_id' => $current->id, 'event_type' => 'project.cloud.saved', 'outcome' => 'completed',
                'payload' => ['revision' => $current->cloud_revision],
            ]);

            return response()->json(['data' => $this->document($current)]);
        }, 3);
    }

    private function authorizeOwner(Request $request, Project $project, ApiActor $actor): void
    {
        // Cloud workspaces are personal even when the device belongs to an administrator.
        abort_unless((int) $project->user_id === $actor->userId($request), 404);
    }

    private function document(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'external_id' => $project->external_id,
            'revision' => $project->cloud_revision,
            'snapshot' => $project->cloud_snapshot,
            'updated_at' => $project->updated_at?->toISOString(),
            'updated_by_device' => $project->cloud_updated_by_device,
        ];
    }
}
