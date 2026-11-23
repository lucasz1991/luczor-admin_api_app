<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProviderKeyRequest;
use App\Http\Requests\Api\V1\UpdateProviderKeyOptionsRequest;
use App\Http\Requests\Api\V1\UpdateProviderKeySourceRequest;
use App\Models\ProviderCredential;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\ClaudeAccessService;
use App\Services\Llm\AnthropicKeyProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal Claude (Anthropic) access of the calling user. Every action is
 * scoped to the authenticated user; the API key itself is write-only and is
 * never returned, logged or audited.
 */
class ProviderKeyController extends Controller
{
    public function __construct(
        private ApiActor $actor,
        private ClaudeAccessService $claude,
        private AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->claude->status($this->actor->userId($request)));
    }

    public function store(StoreProviderKeyRequest $request, AnthropicKeyProbe $probe): JsonResponse
    {
        $userId = $this->actor->userId($request);
        if (! $this->claude->userKeysAllowed()) {
            return $this->error('claude_user_keys_disabled', 'Persönliche Claude-Schlüssel sind auf diesem Server nicht freigegeben.', 403);
        }

        $apiKey = (string) $request->validated('api_key');
        $failure = $probe->check($apiKey);
        if ($failure !== null) {
            $this->record($request, 'provider_key.rejected', $failure);

            return $failure === AnthropicKeyProbe::INVALID
                ? $this->error($failure, 'Der Claude-Schlüssel wurde von Anthropic abgelehnt.', 422)
                : $this->error($failure, 'Anthropic ist derzeit nicht erreichbar. Der Schlüssel wurde nicht gespeichert.', 502);
        }

        ProviderCredential::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => ClaudeAccessService::PROVIDER],
            [
                'label' => 'Persönlicher Claude-Zugang',
                'api_key' => $apiKey,
                'base_url' => null,
                'request_format' => ClaudeAccessService::REQUEST_FORMAT,
                'active' => true,
                'last_tested_at' => now(),
                'last_test_status' => 'ok',
                'last_error_code' => null,
            ],
        );
        $this->record($request, 'provider_key.set', 'saved');

        return response()->json($this->claude->status($userId));
    }

    public function test(Request $request, AnthropicKeyProbe $probe): JsonResponse
    {
        $userId = $this->actor->userId($request);
        $credential = $this->claude->resolve($userId)['credential'];
        if (! $credential) {
            return response()->json(['ok' => false, 'error_code' => 'claude_access_missing']);
        }

        $failure = $probe->check((string) $credential->api_key, $credential->base_url);
        $credential->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $failure === null ? 'ok' : 'failed',
            'last_error_code' => $failure,
        ])->save();
        $this->record($request, 'provider_key.tested', $failure ?? 'ok');

        return response()->json(['ok' => $failure === null, 'error_code' => $failure]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->actor->userId($request);
        // Ownership is part of the query: another user's key is unreachable.
        $deleted = ProviderCredential::query()
            ->ownedBy($userId)
            ->where('provider', ClaudeAccessService::PROVIDER)
            ->delete();
        if ($deleted > 0) {
            $this->record($request, 'provider_key.deleted', 'deleted');
        }

        return response()->json($this->claude->status($userId));
    }

    public function updateSource(UpdateProviderKeySourceRequest $request): JsonResponse
    {
        $userId = $this->actor->userId($request);
        $source = (string) $request->validated('source');
        if (! in_array($source, $this->claude->allowedSources(), true)) {
            return $this->error('claude_source_not_allowed', 'Diese Schlüsselquelle ist auf diesem Server nicht freigegeben.', 422);
        }

        $this->claude->setPreferredSource($userId, $source);
        $this->record($request, 'provider_key.source', $source);

        return response()->json($this->claude->status($userId));
    }

    public function updateOptions(UpdateProviderKeyOptionsRequest $request): JsonResponse
    {
        $userId = $this->actor->userId($request);
        $enabled = (bool) $request->validated('provider_fallback');
        $this->claude->setProviderFallback($userId, $enabled);
        $this->record($request, 'provider_key.options', $enabled ? 'provider_fallback_on' : 'provider_fallback_off');

        return response()->json($this->claude->status($userId));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'error_code' => $code], $status);
    }

    /** Audit trail without any key material. */
    private function record(Request $request, string $eventType, string $outcome): void
    {
        $this->audit->record([
            'actor_user_id' => $request->user()?->id,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'payload' => ['provider' => ClaudeAccessService::PROVIDER],
        ]);
    }
}
