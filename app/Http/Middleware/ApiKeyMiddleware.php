<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    private const LAST_USED_TOUCH_INTERVAL_MINUTES = 10;

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

        $this->touchLastUsedAt($apiKey);

        $request->setUserResolver(fn () => $apiKey->user);
        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }

    private function touchLastUsedAt(ApiKey $apiKey): void
    {
        $usedAt = now();
        $staleBefore = $usedAt->copy()->subMinutes(self::LAST_USED_TOUCH_INTERVAL_MINUTES);

        // The timestamp predicate is part of the UPDATE so concurrent requests
        // cannot all turn an otherwise read-only API call into a database write.
        $updated = ApiKey::query()
            ->whereKey($apiKey->getKey())
            ->where(function ($query) use ($staleBefore) {
                $query->whereNull('last_used_at')
                    ->orWhere('last_used_at', '<=', $staleBefore);
            })
            ->update(['last_used_at' => $usedAt]);

        if ($updated === 1) {
            $apiKey->setAttribute('last_used_at', $usedAt);
        }
    }
}
