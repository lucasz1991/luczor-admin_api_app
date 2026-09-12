<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DeviceLeadership;
use App\Services\ProjectMirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProjectMirrorController extends Controller
{
    public function head(Request $request, Project $project, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $mirror->owned($leadership->device($request), $project);

        return response()->json(['data' => $mirror->head($project)])->header('Cache-Control', 'private, no-store');
    }

    public function lease(Request $request, Project $project, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        return response()->json(['data' => $mirror->lease($leadership->device($request), $project, $this->body($request, [
            'master_epoch' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:0',
        ]))]);
    }

    public function chunk(Request $request, Project $project, string $sha256, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $device = $leadership->device($request);
        $mirror->owned($device, $project);
        if ($request->isMethod('PUT')) {
            abort_if(strlen($request->getContent()) > ProjectMirror::CHUNK_BYTES, 413, 'mirror_chunk_too_large');

            return response()->json(['data' => $mirror->putChunk($device, $project, $sha256, $request->getContent())]);
        }
        if ($request->isMethod('HEAD')) {
            $row = DB::table('project_mirror_chunks')->where('project_id', $project->id)->where('sha256', $sha256)->first();
            abort_unless($row && Storage::disk('cloud-projects')->exists($row->storage_key), 404);

            return response('', 200)->header('Content-Length', (string) $row->size)->header('X-Content-SHA256', $sha256)
                ->header('Cache-Control', 'private, no-store')->header('Content-Type', 'application/octet-stream');
        }

        return response($mirror->chunk($project, $sha256))->header('Content-Type', 'application/octet-stream')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-SHA256', $sha256)->header('X-Content-Type-Options', 'nosniff');
    }

    public function create(Request $request, Project $project, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $data = $this->body($request, ['operation_id' => 'required|uuid', 'base_revision' => 'required|integer|min:0',
            'master_epoch' => 'sometimes|integer|min:1', 'lease_id' => 'sometimes|uuid', 'proposal' => 'sometimes|boolean',
            'draft' => 'sometimes|boolean', 'job_id' => 'sometimes|uuid'] + $this->entryRules());

        return response()->json(['data' => $mirror->create($leadership->device($request), $project, $data)], 201);
    }

    public function entries(Request $request, Project $project, string $manifestId, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $data = $this->body($request, ['operation_id' => 'required|uuid'] + $this->entryRules());

        return response()->json(['data' => $mirror->append($leadership->device($request), $project, $manifestId, $data)]);
    }

    public function show(Request $request, Project $project, string $manifestId, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $mirror->owned($leadership->device($request), $project);
        $data = $request->validate(['offset' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|between:1,1000']);
        $manifest = $mirror->manifest($project, $manifestId);
        $rows = DB::table('project_mirror_entries')->where('manifest_id', $manifestId)->orderBy('id')
            ->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 500)->get();
        $next = ($data['offset'] ?? 0) + $rows->count();

        return response()->json(['data' => $mirror->metadata($manifest) + ['entries' => $rows->map(fn ($row) => json_decode(Crypt::decryptString($row->entry), true)),
            'next_offset' => $next < $manifest->entry_count ? $next : null]])->header('Cache-Control', 'private, no-store');
    }

    public function proposals(Request $request, Project $project, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $mirror->owned($leadership->device($request), $project);

        return response()->json(['data' => DB::table('project_mirror_manifests')->where('project_id', $project->id)->where('status', 'proposed')
            ->orderBy('created_at')->limit(100)->get()->map(fn ($row) => $mirror->metadata($row))])->header('Cache-Control', 'private, no-store');
    }

    public function publish(Request $request, Project $project, string $manifestId, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        $data = $this->body($request, ['operation_id' => 'required|uuid', 'master_epoch' => 'required|integer|min:1',
            'lease_id' => 'required|uuid', 'expected_revision' => 'required|integer|min:0']);

        return response()->json(['data' => $mirror->publish($leadership->device($request), $project, $manifestId, $data)]);
    }

    public function propose(Request $request, Project $project, string $manifestId, DeviceLeadership $leadership, ProjectMirror $mirror)
    {
        return response()->json(['data' => $mirror->propose($leadership->device($request), $project, $manifestId)]);
    }

    private function body(Request $request, array $rules): array
    {
        abort_if(strlen($request->getContent()) > 16_777_216, 413, 'mirror_manifest_page_too_large');

        $body = json_decode($request->getContent(), true, 64);

        return Validator::make(is_array($body) ? $body : [], $rules)->validate();
    }

    private function entryRules(): array
    {
        return ['entries' => 'present|array|max:1000', 'entries.*' => 'required|array:path,type,size,sha256,chunks,target,mode,mtimeMs,metadata',
            'entries.*.path' => 'required|string|max:32768', 'entries.*.type' => 'required|in:file,directory,symlink',
            'entries.*.size' => 'required_if:entries.*.type,file|integer|min:0|max:9007199254740991',
            'entries.*.sha256' => 'required_if:entries.*.type,file|string|regex:/^[a-f0-9]{64}$/',
            'entries.*.chunks' => 'present_if:entries.*.type,file|array',
            'entries.*.chunks.*' => 'required|array:sha256,size', 'entries.*.chunks.*.sha256' => 'required|string|regex:/^[a-f0-9]{64}$/',
            'entries.*.chunks.*.size' => 'required|integer|between:0,8388608',
            'entries.*.target' => 'required_if:entries.*.type,symlink|string|max:32768',
            'entries.*.mode' => 'sometimes|integer|min:0', 'entries.*.mtimeMs' => 'sometimes|integer', 'entries.*.metadata' => 'sometimes|array'];
    }
}
