<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceDebugRequest;
use App\Services\ApiActor;
use App\Services\DeviceDebugRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceDebugController extends Controller
{
    public function poll(Request $request, ApiActor $actor)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120']]);
        $device = $this->device($request, $actor, $data['client_id']);
        $debug = DB::transaction(function () use ($device) {
            $debug = DeviceDebugRequest::query()->where('device_id', $device->id)
                ->where(fn ($query) => $query->where('status', 'pending')->orWhere(fn ($stale) => $stale
                    ->where('status', 'collecting')->where('claimed_at', '<', now()->subMinutes(2))))
                ->oldest('requested_at')->lockForUpdate()->first();
            $debug?->update(['status' => 'collecting', 'claimed_at' => now()]);
            return $debug;
        });

        if (! $debug) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => ['id' => $debug->public_id, 'requested_at' => $debug->requested_at?->toIso8601String()]]);
    }

    public function complete(Request $request, string $publicId, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'report' => ['required', 'array'],
            'report.chat_trace.events' => ['sometimes', 'array', 'max:500'],
        ]);
        abort_if(strlen(json_encode($data['report'])) > 6 * 1024 * 1024, 413, 'Debug report exceeds 6 MiB.');
        if (($data['report']['version'] ?? null) === 'luczor-debug-v3') {
            abort_unless(($data['report']['consent']['diagnostics_enabled'] ?? false) === true
                && ($data['report']['chat_trace']['enabled'] ?? false) === true, 422);
        }
        $device = $this->device($request, $actor, $data['client_id']);
        DB::transaction(function () use ($publicId, $device, $data) {
            $debug = DeviceDebugRequest::query()->where('public_id', $publicId)->where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            if ($debug->status === 'completed') {
                return; // Lost acknowledgement: never overwrite an already accepted report.
            }
            abort_unless($debug->status === 'collecting', 409, 'Debug request is not active.');
            $debug->update([
            'status' => 'completed',
            'completed_at' => now(),
            'payload' => app(DeviceDebugRedactor::class)->clean($data['report']),
            'meta' => ['client_id' => $data['client_id'], 'report_version' => $data['report']['version'] ?? null,
                'trace_events' => count($data['report']['chat_trace']['events'] ?? []),
                'dropped_events' => $data['report']['chat_trace']['dropped_events'] ?? 0],
            ]);
        });

        return response()->json(['ok' => true]);
    }

    private function device(Request $request, ApiActor $actor, string $clientId): Device
    {
        $deviceId = $actor->deviceId($request, $clientId, true);

        return Device::query()->where('device_id', $deviceId)
            ->where('user_id', $actor->userId($request))->whereNull('revoked_at')->firstOrFail();
    }
}
