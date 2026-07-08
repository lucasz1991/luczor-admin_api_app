<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $request->headers->set('Accept', 'application/json');

        $token = $request->bearerToken() ?: $request->header('X-Api-Key');
        if (! $token) {
            return response()->json(['message' => 'API key missing'], 401);
        }

        $apiKey = ApiKey::where('token_hash', ApiKey::hashToken($token))->first();
        if (! $apiKey || ! $apiKey->active || $apiKey->isExpired()) {
            return response()->json(['message' => 'Invalid or expired API key'], 401);
        }

        if (! $apiKey->user || ! $apiKey->user->isActive()) {
            return response()->json(['message' => 'Permission denied'], 401);
        }

        if ($ability && ! $apiKey->hasAbility($ability)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->setUserResolver(fn () => $apiKey->user);
        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }
}
