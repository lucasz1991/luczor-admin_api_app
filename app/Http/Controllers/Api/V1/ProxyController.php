<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ProviderCredential;
use App\Models\PromptTemplate;
use App\Services\ApiActor;
use App\Services\Llm\ProviderDriver;
use App\Services\Llm\ProviderDriverRegistry;
use App\Services\LlmTelemetryService;
use App\Services\NetworkOptimizer;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
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
        ProviderDriverRegistry $drivers,
    ) {
        $userId = $actor->userId($request);
        $rateKey = 'proxy:'.$userId.':'.($request->attributes->get('apiKey')?->id ?? 'unknown');
        if (RateLimiter::tooManyAttempts($rateKey, (int) config('luczor.proxy.requests_per_minute'))) {
            return response()->json(['message' => 'Provider rate limit exceeded.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($rateKey));
        }
        RateLimiter::hit($rateKey, 60);

        if (! ProviderCredential::query()->where('active', true)->exists()) {
            return response()->json(['message' => 'Kein aktiver Provider im Server konfiguriert.'], 503);
        }

        $validated = $request->validate([
            // Accepted for wire compatibility, deliberately ignored for selection.
            'model' => ['nullable', 'string', 'max:180'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:100000'],
            'messages.*.name' => ['nullable', 'string', 'max:160'],
            'messages.*.tool_call_id' => ['nullable', 'string', 'max:200'],
            'messages.*.tool_calls' => ['nullable', 'array', 'max:64'],
            'messages.*.tool_calls.*.id' => ['required_with:messages.*.tool_calls', 'string', 'max:200'],
            'messages.*.tool_calls.*.type' => ['required_with:messages.*.tool_calls', 'in:function'],
            'messages.*.tool_calls.*.function.name' => ['required_with:messages.*.tool_calls', 'string', 'max:160'],
            'messages.*.tool_calls.*.function.arguments' => ['required_with:messages.*.tool_calls', 'string', 'max:100000'],
            'tools' => ['nullable', 'array', 'max:64'],
            'tool_choice' => ['nullable'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'stream' => ['nullable', 'boolean'],
            'input_source' => ['nullable', 'string', 'in:keyboard,push_to_talk,hands_free'],
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
        $providerPayload['messages'] = $this->normalizeToolMessages($providerPayload['messages']);
        // Client values are accepted only for backward wire compatibility.
        // Routing, sampling and token limits are exclusively server-owned.
        unset($providerPayload['model'], $providerPayload['temperature'], $providerPayload['max_tokens']);
        // SOLL §10/§19 — prompt order: luczor.system -> use-case supplement ->
        // role rules (by priority) -> history.
        $prefix = 0;
        $adminPrompt = PromptTemplate::query()->where('key', 'luczor.system')->where('status', 'active')
            ->orderByDesc('version')->first();
        if ($adminPrompt) {
            array_unshift($providerPayload['messages'], ['role' => 'system', 'content' => $adminPrompt->body]);
            $meta['prompt_template_id'] = $adminPrompt->key.'@'.$adminPrompt->version;
            $prefix = 1;
        }

        // §27 — active AI personality (persona) right after the system prompt.
        $persona = \App\Models\Persona::activePrompt();
        if ($persona) {
            array_splice($providerPayload['messages'], $prefix, 0, [['role' => 'system', 'content' => $persona]]);
            $prefix++;
        }

        $useCase = $providerPolicy->useCaseFor($taskType);
        if ($useCase && $useCase->prompt_template_key) {
            $useCasePrompt = PromptTemplate::query()->where('key', $useCase->prompt_template_key)
                ->where('status', 'active')->orderByDesc('version')->first();
            if ($useCasePrompt) {
                array_splice($providerPayload['messages'], $prefix, 0, [['role' => 'system', 'content' => $useCasePrompt->body]]);
                $prefix++;
            }
        }

        // §19 — prioritized role rules (only role-tagged templates, never the
        // global luczor.system) for reproducible per-role behaviour.
        $role = match ($useCase?->slug) {
            'coding' => 'coder', 'planner' => 'planner', 'verifier' => 'analyst', 'vision' => 'analyst', default => 'chat',
        };
        $roleMessages = PromptTemplate::activeRolePrompts($role)
            ->map(fn ($t) => ['role' => 'system', 'content' => $t->body])->all();
        if ($roleMessages !== []) {
            array_splice($providerPayload['messages'], $prefix, 0, $roleMessages);
        }

        // SOLL §10 — mark spoken input so the model treats STT text as fallible.
        $inputSource = $validated['input_source'] ?? 'keyboard';
        if (in_array($inputSource, ['push_to_talk', 'hands_free'], true)) {
            for ($i = count($providerPayload['messages']) - 1; $i >= 0; $i--) {
                if (($providerPayload['messages'][$i]['role'] ?? null) === 'user') {
                    $providerPayload['messages'][$i]['content'] = trim((string) ($providerPayload['messages'][$i]['content'] ?? ''))
                        ."\n\n[Eingabemodus: Sprache; STT-Transkript kann Erkennungsfehler enthalten.]";
                    break;
                }
            }
        }

        // SOLL §18 — derive required model capabilities from the request.
        $required = [];
        if (str_starts_with($taskType, 'vision')) $required[] = 'vision';
        if (! empty($providerPayload['tools'])) $required[] = 'tools';
        $profiles = $providerPolicy->candidates(null, $taskType, $required);
        $providerPayload['stream'] = (bool) ($providerPayload['stream'] ?? false);
        $run = $telemetry->startRun($meta, $taskType, $providerPayload, $providerPolicy->selectionSource());
        // SOLL §27 (Ressourcenmanager) — a use case may tighten the budget below
        // the global network policy (never loosen it).
        $tightest = static fn ($a, $b) => $a && $b ? min($a, $b) : ($a ?: $b);
        $maxInputTokens = $tightest($networkPolicy->max_input_tokens, $useCase?->max_input_tokens);
        $maxCostUsd = $tightest($networkPolicy->max_cost_usd, $useCase?->max_cost_usd);

        $estimatedInputTokens = $providerPolicy->estimatedInputTokens($providerPayload);
        if ($maxInputTokens && $estimatedInputTokens > $maxInputTokens) {
            $run->update(['status' => 'budget_rejected', 'success' => false, 'input_tokens' => $estimatedInputTokens]);
            return response()->json(['message' => 'Context exceeds the server input-token budget.', 'request_id' => $run->request_id], 422);
        }
        $client = $httpClients->make($networkPolicy);

        $winner = null;
        $winnerProfile = null;
        $winnerCredential = null;
        $winnerDriver = null;
        $winnerStarted = null;
        $winnerConnectMs = null;
        $upstream = null;
        foreach ($profiles as $index => $profile) {
            $attemptNo = $index + 1;
            if ($attemptNo > (int) $networkPolicy->max_attempts) break;
            // SOLL §2/P2b — resolve the credential from the profile, then by the
            // profile's provider, then the openrouter default (legacy profiles).
            $profileCredential = ($profile->provider_credential_id
                ? ProviderCredential::find($profile->provider_credential_id) : null)
                ?: ProviderCredential::query()->where('provider', $profile->provider)->where('active', true)->latest()->first()
                ?: ProviderCredential::query()->where('provider', 'openrouter')->where('active', true)->latest()->first();
            if (! $profileCredential?->api_key) continue;
            $providerName = $profileCredential->provider ?: 'openrouter';
            $driver = $drivers->for($providerName, $profileCredential);
            $profileBaseUrl = rtrim($profileCredential->base_url ?: $driver->defaultBaseUrl(), '/');
            $providerPayload['model'] = $profile->model_id;
            $providerPayload['temperature'] = $profile->temperature;
            $providerPayload['max_tokens'] = $providerPolicy->outputBudget($profile, $providerPayload['max_tokens'] ?? $networkPolicy->max_output_tokens);
            $attempt = $telemetry->startAttempt($run, $profile, $profileCredential, $attemptNo, [
                'task_type' => $taskType,
                'admin_order' => $attemptNo,
                'candidate_count' => count($profiles),
                'network_policy' => $networkPolicy->key,
            ]);
            $attemptStarted = microtime(true);
            $estimatedCost = $providerPolicy->estimatedCost($profile, $providerPayload, (int) $providerPayload['max_tokens']);
            if ($maxCostUsd !== null && $estimatedCost !== null && $estimatedCost > $maxCostUsd) {
                $telemetry->failAttempt($attempt, 'cost_budget', 'Estimated request cost exceeds the effective budget.', 0, 0);
                continue;
            }

            try {
                $upstream = $client->post($driver->endpoint($profileBaseUrl), [
                    'headers' => $driver->headers($profileCredential->api_key, (bool) $providerPayload['stream']),
                    'json' => $driver->buildBody($providerPayload),
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
                return response()->json(['message' => 'Provider is currently unreachable.', 'request_id' => $run->request_id], 503);
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
            $winnerProfile = $profile;
            $winnerCredential = $profileCredential;
            $winnerDriver = $driver;
            $winnerStarted = $attemptStarted;
            $winnerConnectMs = $connectMs;
            break;
        }

        if (! $winner || ! $upstream) {
            // Finalize telemetry consistently even when every candidate was rejected.
            $last = $run->attempts()->orderByDesc('attempt_no')->first();
            if ($last) $telemetry->finishRun($run, $last);
            else $run->update(['status' => 'error', 'success' => false, 'attempt_count' => 0]);
            return response()->json(['message' => 'No provider candidate completed.', 'request_id' => $run->request_id], 503);
        }

        $luczorHeaders = [
            'X-Luczor-Use-Case' => $useCase?->slug ?? '',
            'X-Luczor-Model-Profile' => $winnerProfile->slug,
            'X-Luczor-Model-Id' => $winnerProfile->model_id,
            'X-Luczor-Provider' => $winnerCredential->provider ?: 'openrouter',
            'X-Luczor-Review-Enabled' => $useCase?->review_enabled ? '1' : '0',
        ];

        // SOLL §2 — classify upstream errors on BOTH paths (stream previously
        // leaked the raw 401/403 body as text/event-stream).
        if ($upstream->getStatusCode() >= 400) {
            return $this->jsonResponse($upstream, $winnerDriver, $winner, $run, $telemetry, (float) $winnerStarted, (int) $winnerConnectMs, $luczorHeaders);
        }

        return $providerPayload['stream']
            ? $this->streamResponse($upstream, $winnerDriver, $winner, $run, $telemetry, (float) $winnerStarted, (int) $winnerConnectMs, $luczorHeaders)
            : $this->jsonResponse($upstream, $winnerDriver, $winner, $run, $telemetry, (float) $winnerStarted, (int) $winnerConnectMs, $luczorHeaders);
    }

    private function jsonResponse($upstream, ProviderDriver $driver, LlmAttempt $attempt, LlmRun $run, LlmTelemetryService $telemetry, float $started, int $connectMs, array $luczorHeaders = [])
    {
        $body = $upstream->getBody()->getContents();
        $json = json_decode($body, true) ?: [];
        // P2b — non-passthrough drivers translate the provider response into
        // the canonical chat.completion shape the client speaks.
        $normalize = $upstream->getStatusCode() < 400 && ! $driver->passthrough();
        $canonical = $normalize ? $driver->normalizeResponse($json) : $json;
        $usage = is_array($canonical['usage'] ?? null) ? $canonical['usage'] : [];
        $finishReason = $canonical['choices'][0]['finish_reason'] ?? null;
        $generationId = $canonical['id'] ?? ($upstream->getHeaderLine('X-Generation-Id') ?: null);
        $attemptMeta = [
            'generation_id' => $generationId,
            'connect_ms' => $connectMs,
            'finish_reason' => $finishReason,
            'response_hash' => hash('sha256', $body),
        ];
        if (in_array($upstream->getStatusCode(), [401, 403], true)) {
            $attemptMeta['error_type'] = 'provider_auth_failed';
            $attemptMeta['error_message'] = 'Provider rejected the configured credential.';
        }
        $attempt = $telemetry->finishAttempt($attempt, $upstream->getStatusCode(), $this->elapsedMs($started), $usage, $attemptMeta);
        $run = $telemetry->finishRun($run, $attempt);

        if (in_array($upstream->getStatusCode(), [401, 403], true)) {
            return response()->json([
                'message' => 'Provider authentication failed. Bitte den Provider-Key im Adminbereich prüfen oder neu speichern.',
                'code' => 'provider_auth_failed',
                'provider_status' => $upstream->getStatusCode(),
                'request_id' => $run->request_id,
            ], 502)->withHeaders($luczorHeaders)->header('X-Luczor-Request-Id', $run->request_id);
        }

        return response($normalize ? json_encode($canonical, JSON_UNESCAPED_UNICODE) : $body, $upstream->getStatusCode())
            ->header('Content-Type', 'application/json')
            ->withHeaders($luczorHeaders)
            ->header('X-Luczor-Request-Id', $run->request_id);
    }

    private function streamResponse($upstream, ProviderDriver $driver, LlmAttempt $attempt, LlmRun $run, LlmTelemetryService $telemetry, float $started, int $connectMs, array $luczorHeaders = []): StreamedResponse
    {
        $body = $upstream->getBody();
        $status = $upstream->getStatusCode();
        $headerGenerationId = $upstream->getHeaderLine('X-Generation-Id') ?: null;

        return new StreamedResponse(function () use ($body, $status, $driver, $attempt, $run, $telemetry, $headerGenerationId, $started, $connectMs) {
            $buffer = '';
            $usage = [];
            $finishReason = null;
            $generationId = $headerGenerationId;
            $ttftMs = null;
            $hash = hash_init('sha256');

            // Shared telemetry extraction over canonical `data: {...}` lines.
            $absorb = function (string $line) use (&$usage, &$finishReason, &$generationId, &$ttftMs, $started) {
                $line = trim($line);
                if (! str_starts_with($line, 'data:')) return;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') return;
                $event = json_decode($data, true);
                if (! is_array($event)) return;
                if ($generationId === null && is_string($event['id'] ?? null) && $event['id'] !== '') $generationId = $event['id'];
                if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
                $finishReason = $event['choices'][0]['finish_reason'] ?? $finishReason;
                $content = $event['choices'][0]['delta']['content'] ?? null;
                if ($ttftMs === null && is_string($content) && $content !== '') $ttftMs = $this->elapsedMs($started);
            };

            if ($driver->passthrough()) {
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') continue;
                    echo $chunk;
                    @ob_flush(); @flush();
                    hash_update($hash, $chunk);
                    $buffer .= $chunk;
                    $lines = preg_split('/\r?\n/', $buffer);
                    $buffer = array_pop($lines) ?? '';
                    foreach ($lines as $line) $absorb($line);
                }
            } else {
                // P2b — translate the provider's SSE dialect into canonical
                // chat.completion.chunk frames while it streams.
                $adapter = $driver->streamAdapter();
                $emit = function (string $frame) use (&$hash, $absorb) {
                    echo $frame."\n\n";
                    @ob_flush(); @flush();
                    hash_update($hash, $frame."\n\n");
                    $absorb($frame);
                };
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') continue;
                    $buffer .= $chunk;
                    $lines = preg_split('/\r?\n/', $buffer);
                    $buffer = array_pop($lines) ?? '';
                    foreach ($lines as $line) {
                        foreach ($adapter->feed($line) as $frame) $emit($frame);
                    }
                }
                if ($buffer !== '') {
                    foreach ($adapter->feed($buffer) as $frame) $emit($frame);
                }
                foreach ($adapter->finish() as $frame) $emit($frame);
            }

            $attempt = $telemetry->finishAttempt($attempt, $status, $this->elapsedMs($started), $usage, [
                'generation_id' => $generationId,
                'connect_ms' => $connectMs,
                'finish_reason' => $finishReason,
                'ttft_ms' => $ttftMs,
                'response_hash' => hash_final($hash),
            ]);
            $telemetry->finishRun($run, $attempt);
        }, $status, array_merge($luczorHeaders, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Luczor-Request-Id' => $run->request_id,
        ]));
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
            // Set only when a system prompt is actually injected (see chat()).
            'prompt_template_id' => null,
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
        return ['task_type', 'input_source', 'client_id', 'project_id', 'workflow_id', 'task_id', 'session_id', 'feature_key', 'context_id', 'prompt_template_id', 'context_strategy_id', 'network_policy_id', 'repo_id', 'branch', 'commit_sha', 'tool_call_count'];
    }

    private function elapsedMs(float $started): int { return max(0, (int) round((microtime(true) - $started) * 1000)); }

    /** Keep the OpenAI tool protocol intact after Laravel validation. */
    private function normalizeToolMessages(array $messages): array
    {
        foreach ($messages as $index => &$message) {
            if (($message['role'] ?? null) === 'tool' && blank($message['tool_call_id'] ?? null) && blank($message['name'] ?? null)) {
                throw ValidationException::withMessages(["messages.$index" => 'Tool messages require tool_call_id or name.']);
            }
            if (($message['role'] ?? null) === 'assistant' && ! empty($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $toolIndex => $toolCall) {
                    if (blank($toolCall['id'] ?? null) || blank($toolCall['function']['name'] ?? null)) {
                        throw ValidationException::withMessages(["messages.$index.tool_calls.$toolIndex" => 'Assistant tool calls require id and function name.']);
                    }
                }
            }
        }
        unset($message);
        return $messages;
    }
}
