<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ProviderCredential;
use App\Models\PromptTemplate;
use App\Services\ApiActor;
use App\Services\LlmTelemetryService;
use App\Services\NetworkOptimizer;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Provider-key isolation, admin-owned model routing and full attempt telemetry. */
class ProxyController extends Controller
{
    public function chat(
        Request $request,
        ApiActor $actor,
        ProviderPolicyService $providerPolicy,
        NetworkOptimizer $networkOptimizer,
        LlmTelemetryService $telemetry,
        ProviderHttpClientFactory $httpClients,
    ) {
        $userId = $actor->userId($request);
        $rateKey = 'proxy:'.$userId.':'.($request->attributes->get('apiKey')?->id ?? 'unknown');
        if (RateLimiter::tooManyAttempts($rateKey, (int) config('luczor.proxy.requests_per_minute'))) {
            return response()->json(['message' => 'Provider rate limit exceeded.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($rateKey));
        }
        RateLimiter::hit($rateKey, 60);

        $credential = ProviderCredential::query()->where('provider', 'openrouter')->where('active', true)->latest()->first();
        if (! $credential?->api_key) {
            return response()->json(['message' => 'Kein aktiver OpenRouter-Provider im Server konfiguriert.'], 503);
        }

        $validated = $request->validate([
            // Accepted for wire compatibility, deliberately ignored for selection.
            'model' => ['nullable', 'string', 'max:180'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:100000'],
            'tools' => ['nullable', 'array', 'max:64'],
            'tool_choice' => ['nullable'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'stream' => ['nullable', 'boolean'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'workflow_id' => ['nullable', 'string', 'max:120'],
            'task_id' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'context_id' => ['nullable', 'string', 'max:120'],
            'repo_id' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:160'],
            'commit_sha' => ['nullable', 'string', 'max:80'],
            'tool_call_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $meta = $this->runMeta($request, $validated);
        $meta['user_id'] = $userId;
        $meta['client_id'] = $actor->deviceId($request, $validated['client_id'] ?? null);
        $meta['project_ref_id'] = $actor->project($request, $validated['project_id'] ?? null)?->id;
        $taskType = $meta['task_type'];
        $networkPolicy = $networkOptimizer->policy('proxy.openrouter.default');

        $providerPayload = $validated;
        foreach ($this->internalKeys() as $key) unset($providerPayload[$key]);
        // Client values are accepted only for backward wire compatibility.
        // Routing, sampling and token limits are exclusively server-owned.
        unset($providerPayload['model'], $providerPayload['temperature'], $providerPayload['max_tokens']);
        $adminPrompt = PromptTemplate::query()->where('key', 'luczor.system')->where('status', 'active')
            ->orderByDesc('version')->first();
        if ($adminPrompt) {
            array_unshift($providerPayload['messages'], ['role' => 'system', 'content' => $adminPrompt->body]);
            $meta['prompt_template_id'] = $adminPrompt->key.'@'.$adminPrompt->version;
        }

        $profiles = $providerPolicy->candidates(null, $taskType);
        $providerPayload['stream'] = (bool) ($providerPayload['stream'] ?? false);
        $run = $telemetry->startRun($meta, $taskType, $providerPayload, $providerPolicy->selectionSource());
        $estimatedInputTokens = $providerPolicy->estimatedInputTokens($providerPayload);
        if ($networkPolicy->max_input_tokens && $estimatedInputTokens > $networkPolicy->max_input_tokens) {
            $run->update(['status' => 'budget_rejected', 'success' => false, 'input_tokens' => $estimatedInputTokens]);
            return response()->json(['message' => 'Context exceeds the server input-token budget.', 'request_id' => $run->request_id], 422);
        }
        $baseUrl = rtrim($credential->base_url ?: 'https://openrouter.ai/api/v1', '/');
        $client = $httpClients->make($networkPolicy);

        $winner = null;
        $winnerStarted = null;
        $winnerConnectMs = null;
        $upstream = null;
        foreach ($profiles as $index => $profile) {
            $attemptNo = $index + 1;
            if ($attemptNo > (int) $networkPolicy->max_attempts) break;
            $providerPayload['model'] = $profile->model_id;
            $providerPayload['temperature'] = $profile->temperature;
            $providerPayload['max_tokens'] = $providerPolicy->outputBudget($profile, $providerPayload['max_tokens'] ?? $networkPolicy->max_output_tokens);
            $attempt = $telemetry->startAttempt($run, $profile, $credential, $attemptNo, [
                'task_type' => $taskType,
                'admin_order' => $attemptNo,
                'candidate_count' => count($profiles),
                'network_policy' => $networkPolicy->key,
            ]);
            $attemptStarted = microtime(true);
            $estimatedCost = $providerPolicy->estimatedCost($profile, $providerPayload, (int) $providerPayload['max_tokens']);
            if ($networkPolicy->max_cost_usd !== null && $estimatedCost !== null && $estimatedCost > $networkPolicy->max_cost_usd) {
                $telemetry->failAttempt($attempt, 'cost_budget', 'Estimated request cost exceeds the active network policy.', 0, 0);
                continue;
            }

            try {
                $upstream = $client->post($baseUrl.'/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$credential->api_key,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url'),
                        'X-OpenRouter-Title' => 'Luczor',
                        'Accept' => $providerPayload['stream'] ? 'text/event-stream' : 'application/json',
                    ],
                    'json' => $providerPayload,
                    'stream' => $providerPayload['stream'],
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                $telemetry->failAttempt($attempt, class_basename($e), $e->getMessage(), $this->elapsedMs($attemptStarted));
                if ($networkOptimizer->shouldRetry(0, $attemptNo, count($profiles), $networkPolicy)) {
                    $networkOptimizer->backoff($networkPolicy, $attemptNo);
                    continue;
                }
                $telemetry->finishRun($run, $attempt->refresh());
                return response()->json(['message' => 'OpenRouter is currently unreachable.', 'request_id' => $run->request_id], 503);
            }

            $status = $upstream->getStatusCode();
            $connectMs = $this->elapsedMs($attemptStarted);
            if ($networkOptimizer->shouldRetry($status, $attemptNo, count($profiles), $networkPolicy)) {
                $errorBody = $upstream->getBody()->getContents();
                $telemetry->finishAttempt($attempt, $status, $this->elapsedMs($attemptStarted), [], [
                    'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                    'error_type' => 'upstream_http',
                    'error_message' => $errorBody,
                    'response_hash' => hash('sha256', $errorBody),
                ]);
                $networkOptimizer->backoff($networkPolicy, $attemptNo);
                continue;
            }

            $winner = $attempt;
            $winnerStarted = $attemptStarted;
            $winnerConnectMs = $connectMs;
            break;
        }

        if (! $winner || ! $upstream) {
            $run->update(['status' => 'error', 'success' => false, 'attempt_count' => $run->attempts()->count()]);
            return response()->json(['message' => 'No provider candidate completed.', 'request_id' => $run->request_id], 503);
        }

        return $providerPayload['stream']
            ? $this->streamResponse($upstream, $winner, $run, $telemetry, (float) $winnerStarted, (int) $winnerConnectMs)
            : $this->jsonResponse($upstream, $winner, $run, $telemetry, (float) $winnerStarted, (int) $winnerConnectMs);
    }

    private function jsonResponse($upstream, LlmAttempt $attempt, LlmRun $run, LlmTelemetryService $telemetry, float $started, int $connectMs)
    {
        $body = $upstream->getBody()->getContents();
        $json = json_decode($body, true) ?: [];
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $finishReason = $json['choices'][0]['finish_reason'] ?? null;
        $generationId = $json['id'] ?? ($upstream->getHeaderLine('X-Generation-Id') ?: null);
        $attempt = $telemetry->finishAttempt($attempt, $upstream->getStatusCode(), $this->elapsedMs($started), $usage, [
            'generation_id' => $generationId,
            'connect_ms' => $connectMs,
            'finish_reason' => $finishReason,
            'response_hash' => hash('sha256', $body),
        ]);
        $run = $telemetry->finishRun($run, $attempt);

        return response($body, $upstream->getStatusCode())
            ->header('Content-Type', 'application/json')
            ->header('X-Luczor-Request-Id', $run->request_id);
    }

    private function streamResponse($upstream, LlmAttempt $attempt, LlmRun $run, LlmTelemetryService $telemetry, float $started, int $connectMs): StreamedResponse
    {
        $body = $upstream->getBody();
        $status = $upstream->getStatusCode();
        $headerGenerationId = $upstream->getHeaderLine('X-Generation-Id') ?: null;

        return new StreamedResponse(function () use ($body, $status, $attempt, $run, $telemetry, $headerGenerationId, $started, $connectMs) {
            $buffer = '';
            $usage = [];
            $finishReason = null;
            $generationId = $headerGenerationId;
            $ttftMs = null;
            $hash = hash_init('sha256');

            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') continue;
                echo $chunk;
                @ob_flush(); @flush();
                hash_update($hash, $chunk);
                $buffer .= $chunk;
                $lines = preg_split('/\r?\n/', $buffer);
                $buffer = array_pop($lines) ?? '';
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (! str_starts_with($line, 'data:')) continue;
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') continue;
                    $event = json_decode($data, true);
                    if (! is_array($event)) continue;
                    $generationId ??= $event['id'] ?? null;
                    if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
                    $finishReason = $event['choices'][0]['finish_reason'] ?? $finishReason;
                    $content = $event['choices'][0]['delta']['content'] ?? null;
                    if ($ttftMs === null && is_string($content) && $content !== '') $ttftMs = $this->elapsedMs($started);
                }
            }

            $attempt = $telemetry->finishAttempt($attempt, $status, $this->elapsedMs($started), $usage, [
                'generation_id' => $generationId,
                'connect_ms' => $connectMs,
                'finish_reason' => $finishReason,
                'ttft_ms' => $ttftMs,
                'response_hash' => hash_final($hash),
            ]);
            $telemetry->finishRun($run, $attempt);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Luczor-Request-Id' => $run->request_id,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function runMeta(Request $request, array $payload): array
    {
        return [
            'user_id' => $request->user()?->id,
            'client_id' => $payload['client_id'] ?? null,
            'project_id' => $payload['project_id'] ?? null,
            'project_ref_id' => null,
            'workflow_id' => $payload['workflow_id'] ?? null,
            'task_id' => $payload['task_id'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'task_type' => is_string($payload['task_type'] ?? null) ? $payload['task_type'] : 'chat.general',
            'feature_key' => $payload['feature_key'] ?? null,
            'context_id' => $payload['context_id'] ?? null,
            'prompt_template_id' => 'luczor.system@1',
            'context_strategy_id' => 'context.memory_code_budgeted',
            'network_policy_id' => 'proxy.openrouter.default',
            'repo_id' => $payload['repo_id'] ?? null,
            'branch' => $payload['branch'] ?? null,
            'commit_sha' => $payload['commit_sha'] ?? null,
            'tool_call_count' => (int) ($payload['tool_call_count'] ?? 0),
            'retry_count' => 0,
        ];
    }

    /** @return array<int,string> */
    private function internalKeys(): array
    {
        return ['task_type', 'client_id', 'project_id', 'workflow_id', 'task_id', 'session_id', 'feature_key', 'context_id', 'prompt_template_id', 'context_strategy_id', 'network_policy_id', 'repo_id', 'branch', 'commit_sha', 'tool_call_count'];
    }

    private function elapsedMs(float $started): int { return max(0, (int) round((microtime(true) - $started) * 1000)); }
}
