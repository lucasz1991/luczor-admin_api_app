<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class HealthController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'ok' => true,
            'app' => config('app.name'),
            'version' => 'v1',
            'time' => now()->toISOString(),
        ]);
    }
}
