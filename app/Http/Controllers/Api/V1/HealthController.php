<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = ['database' => $this->database()];
        if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
            $checks['redis'] = $this->redis();
        }
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'ok' => $ok,
            'app' => config('app.name'),
            'version' => 'v1',
            'time' => now()->toISOString(),
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function database(): bool
    {
        try {
            DB::select('select 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function redis(): bool
    {
        try {
            $reply = Redis::connection()->command('ping');
            return $reply === true || strtoupper((string) $reply) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
