<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/dashboard';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(600)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('voice-tts', function (Request $request) {
            return Limit::perMinute(max(1, min(60, (int) config('shared_speech.requests_per_minute', 30))))
                ->by('voice-tts:user:'.$request->user()?->id);
        });
        RateLimiter::for('voice-tts-status', function (Request $request) {
            return Limit::perMinute(12)->by('voice-tts-status:user:'.$request->user()?->id);
        });
        RateLimiter::for('memory-improve', function (Request $request) {
            $credential = trim((string) $request->header('X-Api-Key', ''));
            $actor = $request->user()?->id
                ? 'user:'.$request->user()->id
                : ($credential !== ''
                    ? 'key:'.hash('sha256', $credential)
                    : 'ip:'.$request->ip());

            return [
                Limit::perMinute(2)->by('memory-improve:minute:'.$actor),
                Limit::perHour(12)->by('memory-improve:hour:'.$actor),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
