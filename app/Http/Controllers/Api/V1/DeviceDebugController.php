<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceDebugRequest;
use App\Services\ApiActor;
use Illuminate\Http\Request;

class DeviceDebugController extends Controller
{
    public function poll(Request $request, ApiActor $actor)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120']]);
        $device = $this->device($request, $actor, $data['client_id']);
        $debug = DeviceDebugRequest::query()
            ->where('device_id', $device->id)->where('status', 'pending')
            ->oldest('requested_at')->first();

        if (! $debug) {
            return response()->json(['data' => null]);
        }

        $debug->update(['status' => 'collecting', 'claimed_at' => now()]);

        return response()->json(['data' => ['id' => $debug->public_id, 'requested_at' => $debug->requested_at?->toIso8601String()]]);
    }

    public function complete(Request $request, string $publicId, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'report' => ['required', 'array'],
        ]);
        $device = $this->device($request, $actor, $data['client_id']);
        $debug = DeviceDebugRequest::query()->where('public_id', $publicId)->where('device_id', $device->id)->firstOrFail();
        abort_unless($debug->status === 'collecting', 409, 'Debug request is not active.');

        $debug->update([
            'status' => 'completed',
            'completed_at' => now(),
            'payload' => $data['report'],
            'meta' => ['client_id' => $data['client_id'], 'report_version' => $data['report']['version'] ?? null],
        ]);

        return response()->json(['ok' => true]);
    }

    private function device(Request $request, ApiActor $actor, string $clientId): Device
    {
        $deviceId = $actor->deviceId($request, $clientId, true);

        return Device::query()->where('device_id', $deviceId)
            ->where('user_id', $actor->userId($request))->whereNull('revoked_at')->firstOrFail();
    }
}
