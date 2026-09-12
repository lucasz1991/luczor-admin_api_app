<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceJob;
use App\Services\DeviceLeadership;
use App\Services\ProjectMirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class CoordinatedArtifactController extends Controller
{
    public function content(Request $request, string $publicId, string $sha256, DeviceLeadership $leadership)
    {
        $device = $leadership->device($request);
        $job = DeviceJob::where('user_id', $device->user_id)->where('protocol_version', 2)->where('public_id', $publicId)->firstOrFail();
        $key = 'job-artifacts/'.$job->public_id.'/'.$sha256;
        $disk = Storage::disk('cloud-projects');
        if ($request->isMethod('PUT')) {
            abort_unless((int) $job->device_id === (int) $device->id && $job->attempt_id, 403, 'coordination_artifact_executor_required');
            $content = $request->getContent();
            abort_unless(strlen($content) <= ProjectMirror::CHUNK_BYTES && hash_equals($sha256, hash('sha256', $content)), 422, 'coordination_artifact_integrity_invalid');
            abort_unless($disk->put($key, Crypt::encryptString($content)), 503, 'coordination_artifact_storage_failed');

            return response()->json(['data' => ['sha256' => $sha256, 'size' => strlen($content),
                'path' => '/api/v1/coordination/jobs/'.$publicId.'/artifacts/'.$sha256]]);
        }
        abort_unless($disk->exists($key), 404);
        $content = Crypt::decryptString($disk->get($key));
        abort_unless(hash_equals($sha256, hash('sha256', $content)), 503, 'coordination_artifact_corrupt');

        return response($content)->header('Content-Type', 'application/octet-stream')->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Content-SHA256', $sha256);
    }
}
