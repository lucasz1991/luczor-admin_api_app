<?php

namespace App\Services\Llm;

/**
 * Anthropic Messages wire (POST /v1/messages). System messages move to the
 * top-level `system` field, tool traffic maps to tool_use/tool_result blocks,
 * and consecutive same-role turns are merged (the API requires alternation).
 */
class AnthropicMessagesDriver implements ProviderDriver
{
    public const VERSION = '2023-06-01';

    public function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    public function endpoint(string $baseUrl): string
    {
        return $baseUrl.'/messages';
    }

    public function headers(string $apiKey, bool $stream): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::VERSION,
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
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

        $body = [
            'model' => $payload['model'] ?? null,
            // Required by the Messages API; the proxy always sets a budget.
            'max_tokens' => (int) ($payload['max_tokens'] ?? 1024),
            'messages' => $merged,
            'stream' => (bool) ($payload['stream'] ?? false),
        ];
        if ($system !== []) {
            $body['system'] = implode("\n\n", $system);
        }
        if (isset($payload['temperature'])) {
            // Anthropic caps temperature at 1.
            $body['temperature'] = min(1.0, max(0.0, (float) $payload['temperature']));
        }
        if (! empty($payload['tools'])) {
            $body['tools'] = array_values(array_map(static fn (array $tool) => [
                'name' => (string) ($tool['function']['name'] ?? ''),
                'description' => (string) ($tool['function']['description'] ?? ''),
                'input_schema' => $tool['function']['parameters'] ?? ['type' => 'object'],
            ], $payload['tools']));
            $choice = $payload['tool_choice'] ?? null;
            if ($choice === 'required') {
                $body['tool_choice'] = ['type' => 'any'];
            } elseif (is_array($choice) && isset($choice['function']['name'])) {
                $body['tool_choice'] = ['type' => 'tool', 'name' => (string) $choice['function']['name']];
            }
        }

        return $body;
    }

    public function normalizeResponse(array $json): array
    {
        $text = '';
        $toolCalls = [];
        foreach ($json['content'] ?? [] as $block) {
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

        $usage = $json['usage'] ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);

        return [
            'id' => (string) ($json['id'] ?? ''),
            'object' => 'chat.completion',
            'model' => (string) ($json['model'] ?? ''),
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => self::finishReason($json['stop_reason'] ?? null),
            ]],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion],
        ];
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
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };
    }
}
