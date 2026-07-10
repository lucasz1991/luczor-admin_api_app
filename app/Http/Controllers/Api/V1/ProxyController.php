<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LlmRun;
use App\Models\ProviderCredential;
use App\Services\EvaluationService;
use App\Services\ApiActor;
use App\Services\ProviderPolicyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provider proxy: the desktop client sends chat requests here (authenticated
 * with its device key) and the server injects the encrypted OpenRouter key and
 * forwards the request. This keeps provider keys off the client device.
 *
 * Streaming (SSE) responses are passed through chunk-by-chunk.
 */
class ProxyController extends Controller
{
    public function chat(Request $request, ApiActor $actor, ProviderPolicyService $policy)
    {
        $userId = $actor->userId($request);
        $rateKey = 'proxy:'.$userId.':'.($request->attributes->get('apiKey')?->id ?? 'unknown');
        if (RateLimiter::tooManyAttempts($rateKey, config('luczor.proxy.requests_per_minute'))) {
            return response()->json(['message' => 'Provider rate limit exceeded.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($rateKey));
        }
        RateLimiter::hit($rateKey, 60);

        $cred = ProviderCredential::query()
            ->where('provider', 'openrouter')
            ->where('active', true)
            ->latest()
            ->first();

        if (! $cred || ! $cred->api_key) {
            return response()->json(
                ['message' => 'Kein aktiver OpenRouter-Provider im Server konfiguriert.'],
                400
            );
        }

        $base = rtrim($cred->base_url ?: 'https://openrouter.ai/api/v1', '/');
        $url = $base.'/chat/completions';
        $payload = $request->validate([
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
            'prompt_template_id' => ['nullable', 'string', 'max:120'],
            'context_strategy_id' => ['nullable', 'string', 'max:120'],
            'network_policy_id' => ['nullable', 'string', 'max:120'],
            'repo_id' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:160'],
            'commit_sha' => ['nullable', 'string', 'max:80'],
            'tool_call_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'retry_count' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);
        $meta = $this->runMeta($request, $payload);
        $meta['user_id'] = $userId;
        $meta['client_id'] = $actor->deviceId($request, $payload['client_id'] ?? null);
        $project = $actor->project($request, $payload['project_id'] ?? null);
        $meta['project_ref_id'] = $project?->id;

        // Luczor-only metadata; strip before forwarding to OpenRouter.
        $taskType = $meta['task_type'];
        foreach ([
            'task_type', 'client_id', 'project_id', 'workflow_id', 'task_id', 'session_id',
            'feature_key', 'context_id', 'prompt_template_id', 'context_strategy_id',
            'network_policy_id', 'repo_id', 'branch', 'commit_sha', 'tool_call_count', 'retry_count',
        ] as $internalKey) {
            unset($payload[$internalKey]);
        }
        $profiles = $policy->candidates($payload['model'] ?? null, $taskType);
        $profile = $profiles[0];
        $model = $profile->model_id;
        $payload['model'] = $model;
        $payload['max_tokens'] = $policy->outputBudget($profile, $payload['max_tokens'] ?? null);
        $stream = (bool) ($payload['stream'] ?? false);

        $started = microtime(true);
        $client = new Client([
            'connect_timeout' => config('luczor.proxy.connect_timeout'),
            'timeout' => config('luczor.proxy.timeout'),
        ]);

        $upstream = null;
        $attempt = 0;
        foreach ($profiles as $candidate) {
            $attempt++;
            $payload['model'] = $candidate->model_id;
            $payload['max_tokens'] = $policy->outputBudget($candidate, $payload['max_tokens'] ?? null);
            try {
                $upstream = $client->post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$cred->api_key,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => 'https://luczor.local',
                        'X-Title' => 'Luczor',
                        'Accept' => $stream ? 'text/event-stream' : 'application/json',
                    ],
                    'json' => $payload,
                    'stream' => $stream,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException) {
                if ($attempt < count($profiles)) {
                    usleep(200000 * $attempt);
                    continue;
                }
                return response()->json(['message' => 'OpenRouter is currently unreachable.'], 503);
            }

            if ($upstream->getStatusCode() < 500 || $stream || $attempt === count($profiles)) {
                break;
            }
            usleep(200000 * $attempt);
        }

        if (! $upstream) {
            return response()->json(['message' => 'OpenRouter is currently unreachable.'], 503);
        }

        $status = $upstream->getStatusCode();

        if (! $stream) {
            $bodyStr = $upstream->getBody()->getContents();
            $json = json_decode($bodyStr, true) ?: [];
            $this->recordRun($meta, (string) $payload['model'], $taskType, $status, (int) round((microtime(true) - $started) * 1000), $json['usage'] ?? []);

            return response($bodyStr, $status)->header('Content-Type', 'application/json');
        }

        $body = $upstream->getBody();

        return new StreamedResponse(function () use ($body, $meta, $payload, $taskType, $status, $started) {
            while (! $body->eof()) {
                echo $body->read(8192);
                @ob_flush();
                @flush();
            }
            $this->recordRun($meta, (string) $payload['model'], $taskType, $status, (int) round((microtime(true) - $started) * 1000), []);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
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
            'prompt_template_id' => $payload['prompt_template_id'] ?? null,
            'context_strategy_id' => $payload['context_strategy_id'] ?? 'context.memory_code_budgeted',
            'network_policy_id' => $payload['network_policy_id'] ?? 'proxy.openrouter.default',
            'repo_id' => $payload['repo_id'] ?? null,
            'branch' => $payload['branch'] ?? null,
            'commit_sha' => $payload['commit_sha'] ?? null,
            'tool_call_count' => (int) ($payload['tool_call_count'] ?? 0),
            'retry_count' => (int) ($payload['retry_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $meta @param array<string,mixed> $usage */
    private function recordRun(array $meta, string $model, string $taskType, int $status, int $latencyMs, array $usage): void
    {
        $ok = $status >= 200 && $status < 300;
        $inputTokens = $usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? null);
        $outputTokens = $usage['completion_tokens'] ?? ($usage['output_tokens'] ?? null);
        $cost = $usage['cost'] ?? ($usage['total_cost'] ?? 0);

        $run = LlmRun::create([
            'user_id' => $meta['user_id'],
            'client_id' => $meta['client_id'],
            'project_id' => $meta['project_id'],
            'project_ref_id' => $meta['project_ref_id'],
            'workflow_id' => $meta['workflow_id'],
            'task_id' => $meta['task_id'],
            'session_id' => $meta['session_id'],
            'task_type' => $taskType,
            'feature_key' => $meta['feature_key'],
            'context_id' => $meta['context_id'],
            'model_id' => $model,
            'provider_id' => 'openrouter',
            'prompt_template_id' => $meta['prompt_template_id'],
            'context_strategy_id' => $meta['context_strategy_id'],
            'network_policy_id' => $meta['network_policy_id'],
            'repo_id' => $meta['repo_id'],
            'branch' => $meta['branch'],
            'commit_sha' => $meta['commit_sha'],
            'status' => $ok ? 'ok' : 'error',
            'success' => $ok,
            'latency_ms' => $latencyMs,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_total' => $cost,
            'tool_call_count' => $meta['tool_call_count'],
            'retry_count' => $meta['retry_count'],
        ]);

        app(EvaluationService::class)->recordMetric($run, [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'tool_call_count' => $meta['tool_call_count'],
            'retry_count' => $meta['retry_count'],
            'cost_total' => $cost,
            'prompt_template_id' => $meta['prompt_template_id'],
            'context_strategy_id' => $meta['context_strategy_id'],
            'network_policy_id' => $meta['network_policy_id'],
            'raw_usage' => $usage ?: null,
        ]);
    }

}
