<?php

namespace App\Services\Proxy;

use App\Models\ApiKey;
use App\Models\ProviderCredential;
use App\Services\ApiActor;
use App\Services\ProviderPolicyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class ProxyRequestAdmissionService
{
    public function __construct(private ApiActor $actor) {}

    public function admit(Request $request): void
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

        // chat.main resolves its own credential and answers with
        // claude_access_missing instead of this generic outage.
        if ($request->input('task_type') !== ProviderPolicyService::MAIN_TASK_TYPE
            && ! ProviderCredential::query()->where('active', true)->exists()) {
            throw new HttpResponseException(
                response()->json(['message' => 'Kein aktiver Provider im Server konfiguriert.'], 503),
            );
        }

        $request->attributes->set('proxyUserId', $userId);
    }
}
