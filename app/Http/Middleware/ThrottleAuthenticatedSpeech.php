<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** Keep the account throttle after luczor.api despite Laravel's middleware priority. */
class ThrottleAuthenticatedSpeech
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $limiter = 'voice-tts'): Response
    {
        abort_unless($request->user() !== null, 401);

        return $this->throttle->handle($request, $next, $limiter);
    }
}
