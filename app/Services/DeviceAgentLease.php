<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/** Bounded permission for read-only LAN analysis, never authority to publish project changes. */
class DeviceAgentLease
{
    public function issue(Device $source, int $epoch): array
    {
        return DB::transaction(function () use ($source, $epoch) {
            app(DeviceLeadership::class)->fence($source, $epoch);
            $targets = collect(app(DeviceLanIdentity::class)->peers($source->user_id))
                ->pluck('client_id')->reject(fn ($id) => $id === $source->device_id)->sort()->values()->all();
            abort_if(count($targets) > 256, 422, 'Too many devices for a bounded LAN agent lease.');
            $data = ['protocol_version' => 1, 'scope' => 'agent.read', 'user_id' => (int) $source->user_id,
                'source_device_id' => $source->device_id, 'target_device_ids' => $targets, 'epoch' => $epoch,
                'issued_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(15)->toIso8601String()];

            return $data + ['algorithm' => 'RSA-SHA256', 'signature' => app(DeviceJobSigner::class)->signMessage(
                json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        }, 3);
    }
}
