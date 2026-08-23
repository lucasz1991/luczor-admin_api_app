<?php

namespace App\Services\Proxy;

use App\Http\Requests\Api\V1\ProxyChatRequest;
use App\Models\ApiKey;
use App\Models\ProviderCredential;
use App\Services\ApiActor;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

final class ProxyRequestAdmissionService
{
    public function __construct(private ApiActor $actor) {}

    public function admit(ProxyChatRequest $request): void
    {
        $userId = $this->actor->userId($request);
        $apiKey = $request->attributes->get('apiKey');
        $apiKeyId = $apiKey instanceof ApiKey ? $apiKey->id : 'unknown';
        $rateKey = 'proxy:'.$userId.':'.$apiKeyId;

        if (RateLimiter::tooManyAttempts($rateKey, (int) config('luczor.proxy.requests_per_minute'))) {
            throw new HttpResponseException(
                response()->json(['message' => 'Provider rate limit exceeded.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($rateKey)),
            );
        }
        RateLimiter::hit($rateKey, 60);

        if (! ProviderCredential::query()->where('active', true)->exists()) {
            throw new HttpResponseException(
                response()->json(['message' => 'Kein aktiver Provider im Server konfiguriert.'], 503),
            );
        }

        $request->attributes->set('proxyUserId', $userId);
    }
}
