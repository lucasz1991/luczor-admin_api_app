<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DevicePairing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Browser login retains Fortify's authentication and verification requirements. */
class DevicePairingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120'], 'name' => ['required', 'string', 'max:120']]);
        $secret = Str::random(64);
        $pairing = DevicePairing::create($data + ['id' => (string) Str::uuid(), 'secret_hash' => hash('sha256', $secret), 'expires_at' => now()->addMinutes(10)]);

        return response()->json(['id' => $pairing->id, 'secret' => $secret, 'verification_url' => route('devices.pair.show', $pairing->id), 'expires_in' => 600], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id)
    {
        abort_unless($request->user()->isActive(), 403);
        $pairing = DevicePairing::whereKey($id)->where('expires_at', '>', now())->firstOrFail();
        return view('devices.pair', compact('pairing'));
    }

    public function approve(Request $request, string $id)
    {
        abort_unless($request->user()->isActive(), 403);
        DB::transaction(function () use ($request, $id) {
            $pairing = DevicePairing::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($pairing->expires_at->isPast(), 410);
            abort_if($pairing->user_id, 409, 'Dieses Gerät wurde bereits zugeordnet.');
            // A browser login cannot take ownership of an existing device.
            $device = Device::where('device_id', $pairing->client_id)->first();
            abort_if($device && (int) $device->user_id !== (int) $request->user()->id, 409, 'Dieses Gerät gehört bereits zu einem anderen Benutzer.');
            $minted = ApiKey::mint(['user_id' => $request->user()->id, 'name' => $pairing->name, 'device_name' => $pairing->name, 'device_id' => $pairing->client_id, 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true]);
            $pairing->update(['user_id' => $request->user()->id, 'api_key_id' => $minted['model']->id, 'credential' => $minted['plain']]);
        });
        return redirect()->route('devices.pair.show', $id)->with('status', 'Gerät freigegeben. Kehre zu Luczor zurück und übernimm die Anmeldung.');
    }

    public function claim(Request $request, string $id)
    {
        $data = $request->validate(['secret' => ['required', 'string', 'size:64']]);
        return DB::transaction(function () use ($data, $id) {
            $pairing = DevicePairing::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($pairing->secret_hash, hash('sha256', $data['secret'])), 404);
            abort_if($pairing->expires_at->isPast(), 410);
            if (! $pairing->user_id) {
                return response()->json(['status' => 'pending'], 202);
            }
            $key = ApiKey::with('user')->findOrFail($pairing->api_key_id);
            abort_unless($key->active && $key->user?->isActive(), 403);
            abort_unless($pairing->credential, 410);
            $credential = $pairing->credential;
            $pairing->update(['credential' => null]);
            return response()->json(['status' => 'approved', 'device_key' => $credential, 'user' => $key->user->only(['id', 'name', 'email'])])->header('Cache-Control', 'no-store');
        });
    }
}
