<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\LlmRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountDevicesController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isActive(), 403);
        $user = $request->user();
        $devices = Device::where('user_id', $user->id)->latest('last_seen_at')->get();
        $costs = LlmRun::where('user_id', $user->id)->select('client_id')
            ->selectRaw('COUNT(*) as runs_count, COALESCE(SUM(estimated_cost_usd), 0) as estimated_cost_usd, SUM(CASE WHEN estimated_cost_usd IS NULL THEN 1 ELSE 0 END) as unknown_cost_count')
            ->groupBy('client_id')->get();

        return view('devices.index', compact('user', 'devices', 'costs'));
    }

    public function update(Request $request, Device $device)
    {
        abort_unless($request->user()->isActive(), 403);
        // The account page never inherits an administrator's cross-account scope.
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'master' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $device, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($device->revoked_at, 409);
            $device->update(['name' => $data['name']]);
            if ($data['master']) {
                $user->forceFill(['master_device_id' => $device->id])->save();
            } elseif ((int) $user->master_device_id === (int) $device->id) {
                $user->forceFill(['master_device_id' => null])->save();
            }
            app(AuditLogger::class)->record(['actor_user_id' => $user->id, 'device_id' => $device->id, 'event_type' => 'device.coordination_updated', 'payload' => ['master_device_id' => $user->master_device_id]]);
        });

        return back()->with('status', 'Gerätezuordnung gespeichert.');
    }
}
