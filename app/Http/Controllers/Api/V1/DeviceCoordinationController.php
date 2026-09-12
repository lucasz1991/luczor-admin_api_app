<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceLanIdentity;
use App\Services\DeviceLeadership;
use Illuminate\Http\Request;

class DeviceCoordinationController extends Controller
{
    public function identity(Request $request, DeviceLeadership $leadership, DeviceLanIdentity $identity)
    {
        $device = $leadership->device($request);
        if ($request->isMethod('POST')) {
            $data = $request->validate(['cert_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/']);

            return response()->json(['data' => $identity->register($device, $data['cert_sha256'])])->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $identity->peers($device->user_id)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, DeviceLeadership $leadership)
    {
        return response()->json(['data' => $leadership->status($leadership->device($request))])->header('Cache-Control', 'private, no-store');
    }

    public function heartbeat(Request $request, DeviceLeadership $leadership)
    {
        $data = $request->validate(['available' => 'required|boolean', 'busy' => 'required|boolean', 'preferred' => 'sometimes|boolean',
            'platform' => 'sometimes|in:windows,linux,macos,unknown', 'model_tier' => 'sometimes|integer|between:1,5', 'generation' => 'sometimes|integer|min:0']);

        return response()->json(['data' => $leadership->status($leadership->device($request), $data)])->header('Cache-Control', 'private, no-store');
    }
}
