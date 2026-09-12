<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceLeadership;
use Illuminate\Http\Request;

class DeviceCoordinationController extends Controller
{
    public function show(Request $request, DeviceLeadership $leadership)
    {
        return response()->json(['data' => $leadership->status($leadership->device($request))])->header('Cache-Control', 'private, no-store');
    }

    public function heartbeat(Request $request, DeviceLeadership $leadership)
    {
        $data = $request->validate(['available' => 'required|boolean', 'busy' => 'required|boolean', 'preferred' => 'sometimes|boolean']);

        return response()->json(['data' => $leadership->status($leadership->device($request), $data)])->header('Cache-Control', 'private, no-store');
    }
}
