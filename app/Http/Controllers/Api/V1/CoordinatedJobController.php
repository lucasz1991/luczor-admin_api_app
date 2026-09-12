<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceJob;
use App\Services\CoordinatedDeviceJobs;
use App\Services\DeviceLeadership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CoordinatedJobController extends Controller
{
    public function store(Request $request, DeviceLeadership $leadership, CoordinatedDeviceJobs $jobs)
    {
        abort_if(strlen($request->getContent()) > 1_048_576, 413, 'coordination_payload_too_large');
        $data = $this->data($request, ['operation_id' => 'required|uuid', 'target_device_id' => 'required|string|max:120',
            'master_epoch' => 'required|integer|min:1', 'project_id' => 'nullable|string|max:120',
            'conversation_id' => 'nullable|uuid', 'tool_profile' => 'required|string|max:120', 'payload' => 'present|array']);

        return response()->json(['data' => $jobs->envelope($jobs->create($leadership->device($request), $data))], 201);
    }

    public function index(Request $request, DeviceLeadership $leadership, CoordinatedDeviceJobs $jobs)
    {
        $device = $leadership->device($request);
        $data = $request->validate(['after' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|between:1,100']);
        $query = DeviceJob::where('user_id', $device->user_id)->where('protocol_version', 2)->orderBy('id');
        if ($request->route()->getActionMethod() === 'pending') {
            $query->where('device_id', $device->id)->whereIn('status', ['queued', 'running', 'cancelling']);
        }
        $rows = $query->where('id', '>', $data['after'] ?? 0)->limit($data['limit'] ?? 50)->get();

        return response()->json(['data' => $rows->map(fn ($job) => $jobs->envelope($job)), 'next_cursor' => $rows->last()->id ?? ($data['after'] ?? 0)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function pending(Request $request, DeviceLeadership $leadership, CoordinatedDeviceJobs $jobs)
    {
        return $this->index($request, $leadership, $jobs);
    }

    public function show(Request $request, string $publicId, DeviceLeadership $leadership, CoordinatedDeviceJobs $jobs)
    {
        $device = $leadership->device($request);
        $job = DeviceJob::where('user_id', $device->user_id)->where('protocol_version', 2)->where('public_id', $publicId)->firstOrFail();

        return response()->json(['data' => $jobs->envelope($job)])->header('Cache-Control', 'private, no-store');
    }

    public function mutate(Request $request, string $publicId, string $action, DeviceLeadership $leadership, CoordinatedDeviceJobs $jobs)
    {
        abort_unless(in_array($action, ['claim', 'progress', 'complete', 'cancel', 'cancel-ack', 'adopt'], true), 404);
        abort_if(strlen($request->getContent()) > 1_048_576, 413, 'coordination_result_too_large');
        $rules = ['master_epoch' => 'required|integer|min:1', 'authority_epoch' => 'sometimes|integer|min:1'];
        if ($action !== 'cancel') {
            $rules['attempt_id'] = 'required|uuid';
        }
        if ($action === 'progress') {
            $rules += ['sequence' => 'required|integer|between:1,1000000', 'status' => 'sometimes|in:running,waiting,checking', 'summary' => 'sometimes|string|max:4000'];
        }
        if ($action === 'complete') {
            $rules += ['ok' => 'required|boolean', 'result' => 'sometimes|nullable|array', 'error' => 'sometimes|nullable|string|max:8000'];
        }

        return response()->json(['data' => $jobs->envelope($jobs->mutate($leadership->device($request), $publicId, $action, $this->data($request, $rules)))]);
    }

    private function data(Request $request, array $rules): array
    {
        // Tool input and public results must not be trimmed by form middleware.
        $body = json_decode($request->getContent(), true, 64);

        return Validator::make(is_array($body) ? $body : [], $rules)->validate();
    }
}
