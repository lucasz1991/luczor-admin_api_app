<?php

namespace App\Services\Llm;

/**
 * Anthropic Messages wire (POST /v1/messages). System messages move to the
 * top-level `system` field, tool traffic maps to tool_use/tool_result blocks,
 * and consecutive same-role turns are merged (the API requires alternation).
 */
class AnthropicMessagesDriver implements ProviderDriver
{
    public const VERSION = '2026-10-21';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const DEFAULT_MAX_TOKENS = 16000;

    private const DEFAULT_STREAM_MAX_TOKENS = 64000;

    public function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    public function endpoint(string $baseUrl): string
    {
        return $baseUrl.'/messages';
    }

    public function headers(string $apiKey, bool $stream, array $payload = []): array
    {
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::VERSION,
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
        if (self::wantsProviderFallback($payload)) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        return $headers;
    }

    public function buildBody(array $payload): array
    {
        $system = [];
        $messages = [];
        foreach ($payload['messages'] ?? [] as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');
            if ($role === 'system') {
                if ($content !== '') {
                    $system[] = $content;
                }

                continue;
            }
            if ($role === 'tool') {
                $messages[] = ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? $message['name'] ?? ''),
                    'content' => $content,
                ]]];

                continue;
            }
            $blocks = [];
            if ($content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }
            foreach ($message['tool_calls'] ?? [] as $toolCall) {
                $input = json_decode((string) ($toolCall['function']['arguments'] ?? ''), true);
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => (string) ($toolCall['id'] ?? ''),
                    'name' => (string) ($toolCall['function']['name'] ?? ''),
                    'input' => is_array($input) ? $input : new \stdClass,
                ];
            }
            if ($blocks === []) {
                $blocks[] = ['type' => 'text', 'text' => ''];
            }
            $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $blocks];
        }

        // Merge consecutive same-role turns; Anthropic rejects non-alternating roles.
        $merged = [];
        foreach ($messages as $message) {
            $last = $merged !== [] ? array_key_last($merged) : null;
            if ($last !== null && $merged[$last]['role'] === $message['role']) {
                $merged[$last]['content'] = array_merge($merged[$last]['content'], $message['content']);

                continue;
            }
            $merged[] = $message;
        }

        $stream = (bool) ($payload['stream'] ?? false);
        $body = [
            'model' => $payload['model'] ?? null,
            // Thinking tokens count against this budget, so the fallback is
            // generous; a profile/policy budget in the payload still wins.
            'max_tokens' => isset($payload['max_tokens'])
                ? (int) $payload['max_tokens']
                : ($stream ? self::DEFAULT_STREAM_MAX_TOKENS : self::DEFAULT_MAX_TOKENS),
            'messages' => $merged,
            'stream' => $stream,
            'thinking' => ['type' => 'adaptive'],
        ];
        $effort = $payload['effort'] ?? null;
        if (is_string($effort) && in_array($effort, self::EFFORTS, true)) {
            $body['output_config'] = ['effort' => $effort];
        }
        if (self::wantsProviderFallback($payload)) {
            $body['fallbacks'] = 'default';
        }
        if ($system !== []) {
            // One cacheable block: the system prefix is stable across turns.
            $body['system'] = [[
                'type' => 'text',
                'text' => implode("\n\n", $system),
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }
        // Sampling parameters (temperature/top_p/top_k) are rejected by the
        // pinned API version and are therefore never forwarded.
        if (! empty($payload['tools'])) {
            $body['tools'] = array_values(array_map(static fn (array $tool) => [
                'name' => (string) ($tool['function']['name'] ?? ''),
                'description' => (string) ($tool['function']['description'] ?? ''),
                'input_schema' => $tool['function']['parameters'] ?? ['type' => 'object'],
            ], $payload['tools']));
            // Forced tool use ("required"/named) is rejected by current models;
            // those choices degrade to the default (auto) by omission.
            if (($payload['tool_choice'] ?? null) === 'none') {
                $body['tool_choice'] = ['type' => 'none'];
            }
        }

        return $body;
    }

    public function normalizeResponse(array $json): array
    {
        $text = '';
        $toolCalls = [];
        foreach ($json['content'] ?? [] as $block) {
            // Unknown block types (thinking, fallback markers, ...) are skipped.
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
            if (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => json_encode($block['input'] ?? new \stdClass, JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }
        $message = ['role' => 'assistant', 'content' => $text];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        $stopReason = is_string($json['stop_reason'] ?? null) ? $json['stop_reason'] : null;
        $canonical = [
            'id' => (string) ($json['id'] ?? ''),
            'object' => 'chat.completion',
            'model' => (string) ($json['model'] ?? ''),
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => self::finishReason($stopReason),
            ]],
            'usage' => self::usage(is_array($json['usage'] ?? null) ? $json['usage'] : []),
        ];
        if ($stopReason === 'refusal') {
            $canonical['luczor_refusal'] = self::refusal(
                $json['stop_details'] ?? null,
                $text !== '' || $toolCalls !== [],
            );
        }

        return $canonical;
    }

    public function passthrough(): bool
    {
        return false;
    }

    public function streamAdapter(): ProviderStreamAdapter
    {
        return new AnthropicStreamAdapter;
    }

    public static function finishReason(?string $stopReason): string
    {
        return match ($stopReason) {
            'max_tokens', 'model_context_window_exceeded' => 'length',
            'tool_use' => 'tool_calls',
            'refusal' => 'content_filter',
            default => 'stop',
        };
    }

    /**
     * Canonical refusal marker shared by the JSON response and the final
     * stream frame.
     *
     * @return array{category: ?string, phase: string}
     */
    public static function refusal(mixed $stopDetails, bool $producedOutput): array
    {
        $category = is_array($stopDetails) ? ($stopDetails['category'] ?? null) : null;

        return [
            'category' => is_string($category) && $category !== '' ? $category : null,
            'phase' => $producedOutput ? 'mid_stream' : 'before_output',
        ];
    }

    /**
     * Canonical usage. prompt_tokens stays the uncached input so the cost
     * telemetry can price cache reads and writes separately.
     *
     * @param  array<string,mixed>  $usage
     * @return array<string,mixed>
     */
    public static function usage(array $usage): array
    {
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);
        $canonical = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
        ];
        if (isset($usage['cache_read_input_tokens'])) {
            $canonical['prompt_tokens_details'] = ['cached_tokens' => (int) $usage['cache_read_input_tokens']];
        }
        if (isset($usage['cache_creation_input_tokens'])) {
            $canonical['cache_write_tokens'] = (int) $usage['cache_creation_input_tokens'];
        }

        return $canonical;
    }

    /**
     * Server-side refusal fallback is opt-in per request and unavailable on
     * Haiku models.
     *
     * @param  array<string,mixed>  $payload
     */
    private static function wantsProviderFallback(array $payload): bool
    {
        return ($payload['provider_fallback'] ?? null) === true
            && ! str_contains(strtolower((string) ($payload['model'] ?? '')), 'haiku');
    }
}
