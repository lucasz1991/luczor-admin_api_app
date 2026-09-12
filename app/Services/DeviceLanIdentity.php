<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Attests TLS certificate ownership; this is explicitly not an offline execution/leadership grant. */
class DeviceLanIdentity
{
    public function register(Device $device, string $fingerprint): array
    {
        return DB::transaction(function () use ($device, $fingerprint) {
            Device::whereKey($device->id)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
            $existing = DB::table('device_lan_identities')->where('device_id', $device->id)->first();
            if (! $existing || ! hash_equals($existing->cert_sha256, $fingerprint) || Carbon::parse($existing->expires_at)->lt(now()->addDay())) {
                DB::table('device_lan_identities')->updateOrInsert(['device_id' => $device->id], [
                    'cert_sha256' => $fingerprint, 'issued_at' => now(), 'expires_at' => now()->addDays(7)]);
            }

            return $this->signed($device, DB::table('device_lan_identities')->where('device_id', $device->id)->first());
        }, 3);
    }

    public function peers(int $userId): array
    {
        return Device::where('user_id', $userId)->whereNull('revoked_at')->get()->flatMap(function ($device) {
            $identity = DB::table('device_lan_identities')->where('device_id', $device->id)->where('expires_at', '>', now())->first();

            return $identity ? [$this->signed($device, $identity)] : [];
        })->all();
    }

    private function signed(Device $device, object $identity): array
    {
        $data = ['protocol_version' => 1, 'user_id' => (int) $device->user_id, 'client_id' => $device->device_id,
            'cert_sha256' => $identity->cert_sha256, 'issued_at' => Carbon::parse($identity->issued_at)->toIso8601String(),
            'expires_at' => Carbon::parse($identity->expires_at)->toIso8601String()];

        return $data + ['algorithm' => 'RSA-SHA256', 'signature' => app(DeviceJobSigner::class)->signMessage(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }
}
