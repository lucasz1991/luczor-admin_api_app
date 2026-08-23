<?php

namespace App\Services\Proxy;

use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyDispatchResult;
use App\Data\Proxy\ProxyResponseLimits;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Services\Llm\ProviderDriverRegistry;
use App\Services\LlmTelemetryService;
use App\Services\NetworkOptimizer;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use GuzzleHttp\Exception\GuzzleException;

final class ProxyProviderGateway
{
    public function __construct(
        private ProviderHttpClientFactory $httpClients,
        private ProviderDriverRegistry $drivers,
        private NetworkOptimizer $networkOptimizer,
        private ProviderPolicyService $providerPolicy,
        private LlmTelemetryService $telemetry,
        private BoundedBodyReader $bodyReader,
    ) {}

    /** @param array<int,ModelProfile> $profiles */
    public function dispatch(
        array $profiles,
        PreparedProxyRequest $prepared,
        LlmRun $run,
        NetworkPolicy $networkPolicy,
        int|float|null $maxCostUsd,
        ProxyResponseLimits $limits,
    ): ProxyDispatchResult {
        $client = $this->httpClients->make($networkPolicy);
        $terminalReadFailure = null;

        foreach ($profiles as $index => $profile) {
            $attemptNo = $index + 1;
            if ($attemptNo > (int) $networkPolicy->max_attempts) {
                break;
            }

            $credential = $this->credentialFor($profile);
            if (! $credential?->api_key) {
                continue;
            }

            $providerName = $credential->provider ?: 'openrouter';
            $driver = $this->drivers->for($providerName, $credential);
            $baseUrl = rtrim($credential->base_url ?: $driver->defaultBaseUrl(), '/');
            $providerPayload = $prepared->payload;
            $providerPayload['model'] = $profile->model_id;
            $providerPayload['temperature'] = $profile->temperature;
            $providerPayload['max_tokens'] = $this->outputBudget($profile, $providerPayload, $networkPolicy);

            $attempt = $this->telemetry->startAttempt($run, $profile, $credential, $attemptNo, [
                'task_type' => $prepared->taskType,
                'admin_order' => $attemptNo,
                'candidate_count' => count($profiles),
                'network_policy' => $networkPolicy->key,
            ]);
            $startedAt = microtime(true);

            $estimatedCost = $this->providerPolicy
                ->estimatedCost($profile, $providerPayload, (int) $providerPayload['max_tokens']);
            if ($maxCostUsd !== null && $estimatedCost !== null && $estimatedCost > $maxCostUsd) {
                $this->telemetry->failAttempt(
                    $attempt,
                    'cost_budget',
                    'Estimated request cost exceeds the effective budget.',
                    0,
                    0,
                );

                continue;
            }

            try {
                $upstream = $client->request('POST', $driver->endpoint($baseUrl), [
                    'headers' => $driver->headers($credential->api_key, (bool) $providerPayload['stream']),
                    'json' => $driver->buildBody($providerPayload),
                    // Always stream the transport so response limits apply while
                    // reading. The provider JSON `stream` flag remains unchanged.
                    'stream' => true,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $exception) {
                $this->telemetry->failAttempt(
                    $attempt,
                    class_basename($exception),
                    $exception->getMessage(),
                    $this->elapsedMs($startedAt),
                );
                if ($this->networkOptimizer->shouldRetry(0, $attemptNo, count($profiles), $networkPolicy)) {
                    $this->networkOptimizer->backoff($networkPolicy, $attemptNo);

                    continue;
                }
                $this->telemetry->finishRun($run, $attempt->refresh());

                return ProxyDispatchResult::failure(response()->json([
                    'message' => 'Provider is currently unreachable.',
                    'request_id' => $run->request_id,
                ], 503));
            }

            $status = $upstream->getStatusCode();
            $connectMs = $this->elapsedMs($startedAt);
            if ($this->networkOptimizer->shouldRetry($status, $attemptNo, count($profiles), $networkPolicy)) {
                $errorBody = $this->bodyReader->read($upstream->getBody(), $limits->bodyBytes);
                if ($errorBody->limitExceeded || $errorBody->readFailed) {
                    $code = $errorBody->limitExceeded
                        ? 'upstream_response_too_large'
                        : 'upstream_response_read_failed';
                    $message = $errorBody->limitExceeded
                        ? 'Provider response exceeded the gateway size limit.'
                        : 'Provider response could not be read safely.';
                    $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), [], [
                        'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                        'connect_ms' => $connectMs,
                        'error_type' => $code,
                        'error_message' => $message,
                    ]);
                    $terminalReadFailure = [
                        'code' => $code,
                        'message' => $message,
                        'provider_status' => $status,
                    ];
                } else {
                    $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), [], [
                        'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                        'error_type' => 'upstream_http',
                        'error_message' => $errorBody->contents,
                        'response_hash' => $errorBody->sha256,
                    ]);
                }
                $this->networkOptimizer->backoff($networkPolicy, $attemptNo);

                continue;
            }

            return ProxyDispatchResult::winner(
                upstream: $upstream,
                attempt: $attempt,
                profile: $profile,
                credential: $credential,
                driver: $driver,
                startedAt: $startedAt,
                connectMs: $connectMs,
            );
        }

        $lastAttempt = $run->attempts()->orderByDesc('attempt_no')->first();
        if ($lastAttempt) {
            $run = $this->telemetry->finishRun($run, $lastAttempt);
        } else {
            $run->update(['status' => 'error', 'success' => false, 'attempt_count' => 0]);
        }

        if (is_array($terminalReadFailure)) {
            $payload = [
                'message' => $terminalReadFailure['message'],
                'code' => $terminalReadFailure['code'],
                'provider_status' => $terminalReadFailure['provider_status'],
                'request_id' => $run->request_id,
            ];
            if ($terminalReadFailure['code'] === 'upstream_response_too_large') {
                $payload['limit_bytes'] = $limits->bodyBytes;
            }

            return ProxyDispatchResult::failure(
                response()->json($payload, 502)->header('X-Luczor-Request-Id', $run->request_id),
            );
        }

        return ProxyDispatchResult::failure(response()->json([
            'message' => 'No provider candidate completed.',
            'request_id' => $run->request_id,
        ], 503));
    }

    private function credentialFor(ModelProfile $profile): ?ProviderCredential
    {
        return ($profile->provider_credential_id
            ? ProviderCredential::find($profile->provider_credential_id)
            : null)
            ?: ProviderCredential::query()
                ->where('provider', $profile->provider)
                ->where('active', true)
                ->latest()
                ->first()
            ?: ProviderCredential::query()
                ->where('provider', 'openrouter')
                ->where('active', true)
                ->latest()
                ->first();
    }

    /** @param array<string,mixed> $payload */
    private function outputBudget(ModelProfile $profile, array $payload, NetworkPolicy $networkPolicy): int
    {
        return $this->providerPolicy->outputBudget(
            $profile,
            $payload['max_tokens'] ?? $networkPolicy->max_output_tokens,
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
