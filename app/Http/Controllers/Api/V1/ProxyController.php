<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LlmRun;
use App\Models\ProviderCredential;
use App\Services\EvaluationService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
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
    public function chat(Request $request)
    {
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
        $payload = $request->all();
        $meta = $this->runMeta($request, $payload);

        // Luczor-only metadata; strip before forwarding to OpenRouter.
        $taskType = $meta['task_type'];
        foreach ([
            'task_type', 'client_id', 'project_id', 'workflow_id', 'task_id', 'session_id',
            'feature_key', 'context_id', 'prompt_template_id', 'context_strategy_id',
            'network_policy_id', 'repo_id', 'branch', 'commit_sha', 'tool_call_count', 'retry_count',
        ] as $internalKey) {
            unset($payload[$internalKey]);
        }
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : 'unknown';
        $stream = (bool) ($payload['stream'] ?? false);

        $started = microtime(true);
        $client = new Client(['timeout' => 0]);

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

        $status = $upstream->getStatusCode();

        if (! $stream) {
            $bodyStr = $upstream->getBody()->getContents();
            $json = json_decode($bodyStr, true) ?: [];
            $this->recordRun($meta, $model, $taskType, $status, (int) round((microtime(true) - $started) * 1000), $json['usage'] ?? []);

            return response($bodyStr, $status)->header('Content-Type', 'application/json');
        }

        $body = $upstream->getBody();

        return new StreamedResponse(function () use ($body, $meta, $model, $taskType, $status, $started) {
            while (! $body->eof()) {
                echo $body->read(8192);
                @ob_flush();
                @flush();
            }
            $this->recordRun($meta, $model, $taskType, $status, (int) round((microtime(true) - $started) * 1000), []);
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

    private function elevenCredential(): ?ProviderCredential
    {
        return ProviderCredential::query()
            ->where('provider', 'elevenlabs')
            ->where('active', true)
            ->latest()
            ->first();
    }

    public function elevenTts(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string'],
            'voice_id' => ['required', 'string'],
            'model_id' => ['nullable', 'string'],
            'output_format' => ['nullable', 'string'],
        ]);

        $cred = $this->elevenCredential();
        if (! $cred || ! $cred->api_key) {
            return response()->json(['message' => 'Kein aktiver ElevenLabs-Provider im Server konfiguriert.'], 400);
        }

        $model = $data['model_id'] ?? 'eleven_multilingual_v2';
        $fmt = $data['output_format'] ?? 'mp3_44100_128';
        $base = rtrim($cred->base_url ?: 'https://api.elevenlabs.io', '/');
        $url = $base.'/v1/text-to-speech/'.$data['voice_id'].'?output_format='.$fmt;

        $resp = (new Client(['timeout' => 60]))->post($url, [
            'headers' => ['xi-api-key' => $cred->api_key, 'Content-Type' => 'application/json'],
            'json' => ['text' => $data['text'], 'model_id' => $model],
            'http_errors' => false,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return response()->json(['message' => 'ElevenLabs TTS Fehler', 'detail' => (string) $resp->getBody()], $resp->getStatusCode());
        }

        $mime = str_starts_with($fmt, 'pcm_') ? 'audio/wav' : (str_starts_with($fmt, 'opus_') ? 'audio/ogg' : 'audio/mpeg');

        return response()->json(['base64' => base64_encode((string) $resp->getBody()), 'mime' => $mime]);
    }

    public function elevenStt(Request $request)
    {
        $data = $request->validate([
            'base64' => ['required', 'string'],
            'mime' => ['nullable', 'string'],
            'model_id' => ['nullable', 'string'],
            'language_code' => ['nullable', 'string'],
        ]);

        $cred = $this->elevenCredential();
        if (! $cred || ! $cred->api_key) {
            return response()->json(['message' => 'Kein aktiver ElevenLabs-Provider im Server konfiguriert.'], 400);
        }

        $b64 = $data['base64'];
        if (str_contains($b64, ',')) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        $bytes = base64_decode($b64) ?: '';
        $mime = $data['mime'] ?? 'audio/wav';
        $ext = str_contains($mime, 'wav') ? 'wav' : (str_contains($mime, 'mp3') ? 'mp3' : (str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'ogg') ? 'ogg' : 'bin')));

        $multipart = [
            ['name' => 'file', 'contents' => $bytes, 'filename' => 'audio.'.$ext],
            ['name' => 'model_id', 'contents' => $data['model_id'] ?? 'scribe_v2'],
        ];
        if (! empty($data['language_code'])) {
            $multipart[] = ['name' => 'language_code', 'contents' => $data['language_code']];
        }

        $base = rtrim($cred->base_url ?: 'https://api.elevenlabs.io', '/');
        $resp = (new Client(['timeout' => 120]))->post($base.'/v1/speech-to-text', [
            'headers' => ['xi-api-key' => $cred->api_key],
            'multipart' => $multipart,
            'http_errors' => false,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return response()->json(['message' => 'ElevenLabs STT Fehler', 'detail' => (string) $resp->getBody()], $resp->getStatusCode());
        }

        $json = json_decode((string) $resp->getBody(), true) ?: [];

        return response()->json(['text' => $json['text'] ?? '', 'language_code' => $json['language_code'] ?? null]);
    }
}
