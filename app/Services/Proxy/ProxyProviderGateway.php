<?php

namespace App\Services\Proxy;

use App\Data\ProviderRoutingDecision;
use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyDispatchResult;
use App\Data\Proxy\ProxyResponseLimits;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\NetworkPolicy;
use App\Services\Llm\ProviderDriverRegistry;
use App\Services\Llm\ProviderWireFormat;
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

    public function dispatch(
        ProviderRoutingDecision $routing,
        PreparedProxyRequest $prepared,
        LlmRun $run,
        ProxyResponseLimits $limits,
    ): ProxyDispatchResult {
        $profiles = $routing->profiles;
        $networkPolicy = $routing->networkPolicy;
        $client = $this->httpClients->make($networkPolicy);
        $terminalReadFailure = null;
        $committedCostUsd = 0.0;

        foreach ($profiles as $index => $profile) {
            $attemptNo = $index + 1;
            if ($attemptNo > $routing->maxAttempts) {
                break;
            }

            $credential = $profile->credential?->fresh();
            if (! $credential || ! ProviderWireFormat::isCompatible($profile, $credential)) {
                return $this->policyFailure($run, 'routing_credential_incompatible', 503);
            }

            $remainingProfiles = array_slice(
                $profiles,
                $index,
                $routing->maxAttempts - $index,
            );
            $reservation = $this->providerPolicy->currentCostReservation(
                $remainingProfiles,
                $prepared->payload,
                $networkPolicy,
                count($remainingProfiles),
            );
            if ($reservation === null) {
                return $this->policyFailure($run, 'routing_price_unavailable', 503);
            }
            $projectedCostUsd = $committedCostUsd + $reservation['total'];
            if ($routing->maxCostUsd !== null && $projectedCostUsd > $routing->maxCostUsd) {
                return $this->policyFailure($run, 'routing_budget_exceeded', 422);
            }
            $estimatedCost = $reservation['by_profile_id'][$profile->id] ?? null;
            if ($estimatedCost === null) {
                return $this->policyFailure($run, 'routing_price_unavailable', 503);
            }

            $providerName = $profile->provider;
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
                'routing_policy_version' => $routing->policyVersion,
                'routing_reason' => $routing->reasonCode,
                'selection_source' => $routing->selectionSource,
                'effective_max_attempts' => $routing->maxAttempts,
                'estimated_cost_usd' => $estimatedCost,
                'projected_cumulative_cost_usd' => $projectedCostUsd,
            ]);
            $startedAt = microtime(true);
            // Reserve a sent attempt even when the transport later fails: the
            // provider may still have processed and billed the request.
            $committedCostUsd += $estimatedCost;

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
                    'Provider request failed before a response was received.',
                    $this->elapsedMs($startedAt),
                );
                if ($this->networkOptimizer->shouldRetry(0, $attemptNo, $routing->maxAttempts, $networkPolicy)) {
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
            if ($this->networkOptimizer->shouldRetry($status, $attemptNo, $routing->maxAttempts, $networkPolicy)) {
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
                        'error_message' => 'Provider returned a retriable HTTP status.',
                        'response_hash' => $errorBody->sha256,
                    ]);
                }
                $this->networkOptimizer->backoff($networkPolicy, $attemptNo);

                continue;
            }

            if ($status < 200) {
                $upstream->getBody()->close();
                $attempt = $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), [], [
                    'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                    'connect_ms' => $connectMs,
                    'error_type' => 'provider_informational_status_rejected',
                    'error_message' => 'Provider returned a non-final informational HTTP status.',
                ]);
                $this->telemetry->finishRun($run, $attempt);

                return ProxyDispatchResult::failure(response()->json([
                    'message' => 'Provider returned an invalid final HTTP status.',
                    'code' => 'provider_informational_status_rejected',
                    'provider_status' => $status,
                    'request_id' => $run->request_id,
                ], 502));
            }

            if ($status >= 300 && $status < 400) {
                $upstream->getBody()->close();
                $attempt = $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), [], [
                    'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                    'connect_ms' => $connectMs,
                    'error_type' => 'provider_redirect_rejected',
                    'error_message' => 'Provider redirects are disabled and this status is not retriable by policy.',
                ]);
                $this->telemetry->finishRun($run, $attempt);

                return ProxyDispatchResult::failure(response()->json([
                    'message' => 'Provider redirect was rejected by the active network policy.',
                    'code' => 'provider_redirect_rejected',
                    'provider_status' => $status,
                    'request_id' => $run->request_id,
                ], 502));
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
            'code' => 'routing_candidates_exhausted',
            'request_id' => $run->request_id,
        ], 503));
    }

    private function policyFailure(LlmRun $run, string $reasonCode, int $httpStatus): ProxyDispatchResult
    {
        $lastAttempt = $run->attempts()->orderByDesc('attempt_no')->first();
        if ($lastAttempt) {
            $this->telemetry->finishRun($run, $lastAttempt);
        }
        $attemptCount = $run->attempts()->count();
        $run->update([
            'status' => 'policy_rejected',
            'success' => false,
            'routing_reason_code' => $reasonCode,
            'attempt_count' => $attemptCount,
            'retry_count' => max(0, $attemptCount - 1),
        ]);

        return ProxyDispatchResult::failure(response()->json([
            'message' => 'External routing is unavailable under the active policy.',
            'code' => $reasonCode,
            'request_id' => $run->request_id,
        ], $httpStatus));
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
