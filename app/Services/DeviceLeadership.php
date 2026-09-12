<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\LocalModelCatalog;
use App\Models\User;
use App\Models\WorkflowRun;
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
                $metadata = array_intersect_key($heartbeat, array_flip(['platform', 'model_tier', 'generation', 'active_model_id']));
                $tier = isset($heartbeat['model_tier']) ? $heartbeat['model_tier'] : $this->activeModelTier($heartbeat['active_model_id'] ?? null);
                $metadata['model_tier'] = $tier;
                $metadata['model_tier_source'] = $tier === null ? null : (isset($heartbeat['model_tier']) ? 'explicit' : 'published_model');
                $device->forceFill(['meta' => array_merge($device->meta ?? [], ['coordination' => $metadata])])->save();
                if (($heartbeat['preferred'] ?? false) === true) {
                    $user->forceFill(['master_device_id' => $device->id])->save();
                }
            }
            DB::table('device_leaderships')->insertOrIgnore(['user_id' => $user->id, 'epoch' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $state = DB::table('device_leaderships')->where('user_id', $user->id)->first();
            $eligible = Device::where('user_id', $user->id)->whereNull('revoked_at')->where('coordination_available', true)
                ->where('coordination_seen_at', '>', now()->subSeconds(self::LEASE_SECONDS))->orderBy('id')->get()
                ->sortByDesc(fn ($item) => (int) ($item->meta['coordination']['model_tier'] ?? 0));
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
            $changed = ($candidate->id ?? null) !== $state->leader_device_id || (! $live && $candidate !== null);
            $epoch = (int) $state->epoch + ($changed ? 1 : 0);
            $expiry = $changed || ($heartbeat !== null && $candidate?->id === $device->id)
                ? ($candidate ? now()->addSeconds(self::LEASE_SECONDS) : null) : $state->lease_expires_at;
            if ($changed) {
                // A handoff revokes new effects, not evidence of a claimed effect. Never requeue a claimed attempt.
                DeviceJob::where('user_id', $user->id)->where('protocol_version', 2)->where('status', 'running')->whereNotNull('attempt_id')
                    ->update(['authority_epoch' => $epoch, 'reconciliation_required' => true, 'lease_expires_at' => null]);
                if ($candidate) {
                    // Unclaimed jobs have no effects/ledger attempt yet. Refresh their admission envelope only.
                    DeviceJob::where('user_id', $user->id)->where('protocol_version', 2)->where('status', 'queued')->whereNull('attempt_id')->whereNull('cancel_requested_at')
                        ->update(['master_epoch' => $epoch, 'authority_epoch' => $epoch, 'source_device_id' => $candidate->id]);
                    foreach (WorkflowRun::where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->lockForUpdate()->get() as $run) {
                        $context = $run->context ?? [];
                        if (isset($context['_execution']['coordination_epoch'], $context['_execution']['coordination_source_device_id'])) {
                            $context['_execution']['coordination_epoch'] = $epoch;
                            $context['_execution']['coordination_source_device_id'] = $candidate->id;
                            $run->update(['context' => $context]);
                        }
                    }
                }
            }
            DB::table('device_leaderships')->where('user_id', $user->id)->update([
                'leader_device_id' => $candidate?->id, 'preferred_device_id' => $user->master_device_id,
                'epoch' => $epoch, 'lease_expires_at' => $expiry, 'updated_at' => now(),
            ]);

            return ['schema_version' => 1, 'leader_device_id' => $candidate?->device_id,
                'preferred_device_id' => Device::where('user_id', $user->id)->whereKey($user->master_device_id)->value('device_id'),
                'epoch' => $epoch, 'lease_expires_at' => $expiry ? Carbon::parse($expiry)->toIso8601String() : null,
                'heartbeat_seconds' => 10, 'lease_seconds' => self::LEASE_SECONDS,
                'peers' => app(DeviceLanIdentity::class)->peers($user->id),
                'role' => $candidate?->id === $device->id ? 'master' : 'assistant',
                'handoff_pending' => $candidate && $preferred && $candidate->id !== $preferred->id,
                'devices' => Device::where('user_id', $user->id)->whereNull('revoked_at')->orderBy('id')->get()->map(fn ($item) => [
                    'id' => $item->id, 'client_id' => $item->device_id, 'device_id' => $item->device_id, 'name' => $item->name,
                    'platform' => $item->meta['coordination']['platform'] ?? 'unknown',
                    'model_tier' => $item->meta['coordination']['model_tier'] ?? null,
                    'active_model_id' => $item->meta['coordination']['active_model_id'] ?? null,
                    'model_tier_source' => $item->meta['coordination']['model_tier_source'] ?? null,
                    'generation' => $item->meta['coordination']['generation'] ?? null,
                    'available' => $eligible->contains('id', $item->id), 'busy' => (bool) $item->coordination_busy,
                    'last_seen_at' => $item->coordination_seen_at?->toIso8601String(),
                ])->values()->all()];
        }, 3);
    }

    private function activeModelTier(?string $modelId): ?int
    {
        if ($modelId === null || $modelId === '') {
            return null;
        }
        $models = LocalModelCatalog::find(1)?->published['models'] ?? null;
        if (! is_array($models) || ! array_is_list($models)) {
            return null;
        }
        foreach (array_slice($models, 0, 5) as $index => $model) {
            if (is_array($model) && ($model['id'] ?? null) === $modelId) {
                return $index + 1;
            }
        }

        return null;
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
