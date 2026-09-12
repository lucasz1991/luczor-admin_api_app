<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** User row is the common fencing lock for leadership, job admission and mirror publication. */
class DeviceLeadership
{
    public const LEASE_SECONDS = 45;

    public function device(Request $request): Device
    {
        $id = app(ApiActor::class)->deviceId($request, $request->input('client_id'), true);

        return Device::where('user_id', $request->user()->id)->where('device_id', $id)->whereNull('revoked_at')->firstOrFail();
    }

    public function status(Device $device, ?array $heartbeat = null): array
    {
        return DB::transaction(function () use ($device, $heartbeat) {
            $user = User::whereKey($device->user_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($device->id)->whereNull('revoked_at')->firstOrFail();
            if ($heartbeat !== null) {
                $device->forceFill(['coordination_seen_at' => now(), 'coordination_available' => $heartbeat['available'],
                    'coordination_busy' => $heartbeat['busy'], 'last_seen_at' => now(),
                    'status' => $heartbeat['available'] ? ($heartbeat['busy'] ? 'busy' : 'online') : 'offline'])->save();
                if (($heartbeat['preferred'] ?? false) === true) {
                    $user->forceFill(['master_device_id' => $device->id])->save();
                }
            }
            DB::table('device_leaderships')->insertOrIgnore(['user_id' => $user->id, 'epoch' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $state = DB::table('device_leaderships')->where('user_id', $user->id)->first();
            $eligible = Device::where('user_id', $user->id)->whereNull('revoked_at')->where('coordination_available', true)
                ->where('coordination_seen_at', '>', now()->subSeconds(self::LEASE_SECONDS))->orderBy('id')->get();
            $current = $eligible->firstWhere('id', $state->leader_device_id);
            $preferred = $eligible->firstWhere('id', $user->master_device_id);
            $live = $current && $state->lease_expires_at && Carbon::parse($state->lease_expires_at)->isFuture();
            $candidate = $live ? $current : ($preferred ?? $eligible->first());
            $hasEffects = DeviceJob::where('user_id', $user->id)->where('protocol_version', 2)
                ->whereIn('status', ['running', 'cancelling'])->exists();
            $pending = $live && $preferred && $current->id !== $preferred->id;
            if ($pending && ! $current->coordination_busy && ! $hasEffects) {
                $candidate = $preferred;
            }
            $changed = ($candidate?->id ?? null) !== $state->leader_device_id || (! $live && $candidate !== null);
            $epoch = (int) $state->epoch + ($changed ? 1 : 0);
            $expiry = $changed || ($heartbeat !== null && $candidate?->id === $device->id)
                ? ($candidate ? now()->addSeconds(self::LEASE_SECONDS) : null) : $state->lease_expires_at;
            if ($changed) {
                DeviceJob::where('user_id', $user->id)->where('protocol_version', 2)->whereIn('status', ['running', 'cancelling'])
                    ->update(['cancel_requested_at' => now(), 'status' => 'cancelling']);
                DeviceJob::where('user_id', $user->id)->where('protocol_version', 2)->whereIn('status', ['queued', 'approval_required'])
                    ->update(['cancel_requested_at' => now(), 'status' => 'cancelled', 'finished_at' => now()]);
            }
            DB::table('device_leaderships')->where('user_id', $user->id)->update([
                'leader_device_id' => $candidate?->id, 'preferred_device_id' => $user->master_device_id,
                'epoch' => $epoch, 'lease_expires_at' => $expiry, 'updated_at' => now(),
            ]);

            return ['schema_version' => 1, 'leader_device_id' => $candidate?->device_id,
                'preferred_device_id' => Device::where('user_id', $user->id)->whereKey($user->master_device_id)->value('device_id'),
                'epoch' => $epoch, 'lease_expires_at' => $expiry ? Carbon::parse($expiry)->toIso8601String() : null,
                'heartbeat_seconds' => 10, 'lease_seconds' => self::LEASE_SECONDS,
                'role' => $candidate?->id === $device->id ? 'master' : 'assistant',
                'handoff_pending' => $candidate && $preferred && $candidate->id !== $preferred->id];
        }, 3);
    }

    /** Call within a transaction; the lock remains held until the protected write commits. */
    public function fence(Device $device, int $epoch, bool $requireLeader = true): object
    {
        User::whereKey($device->user_id)->lockForUpdate()->firstOrFail();
        abort_if(Device::whereKey($device->id)->whereNotNull('revoked_at')->exists(), 403, 'coordination_device_revoked');
        $state = DB::table('device_leaderships')->where('user_id', $device->user_id)->first();
        abort_unless($state && (int) $state->epoch === $epoch && $state->lease_expires_at
            && Carbon::parse($state->lease_expires_at)->isFuture(), 409, 'coordination_epoch_expired');
        abort_if($requireLeader && (int) $state->leader_device_id !== (int) $device->id, 403, 'coordination_master_required');

        return $state;
    }
}
