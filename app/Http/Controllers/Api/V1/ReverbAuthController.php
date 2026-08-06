<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use App\Services\ApiActor;
use Illuminate\Http\Request;

class ReverbAuthController extends Controller
{
    public function __invoke(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:120'],
            'channel_name' => ['required', 'string', 'max:200'],
            'client_id' => ['required', 'string', 'max:120'],
        ]);
        $deviceId = $actor->deviceId($request, $data['client_id'], true);
        abort_unless($data['channel_name'] === 'private-device.'.$deviceId, 403, 'Channel does not belong to this device.');
        $token = (string) $request->header('X-Device-Session');
        abort_unless($token !== '', 401, 'A short-lived device session is required.');
        $session = DeviceSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->whereHas('device', fn ($query) => $query->where('device_id', $deviceId)->where('user_id', $actor->userId($request)))
            ->first();
        abort_unless($session, 401, 'Device session expired.');
        $session->update(['last_seen_at' => now()]);

        $key = (string) (
            config('broadcasting.connections.reverb.key')
            ?: config('broadcasting.connections.pusher.key')
        );
        $secret = (string) (
            config('broadcasting.connections.reverb.secret')
            ?: config('broadcasting.connections.pusher.secret')
        );
        abort_unless($key !== '' && $secret !== '', 503, 'Reverb credentials are not configured.');
        $signature = hash_hmac('sha256', $data['socket_id'].':'.$data['channel_name'], $secret);

        return response()->json(['auth' => $key.':'.$signature]);
    }
}
