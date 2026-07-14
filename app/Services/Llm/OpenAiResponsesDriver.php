<?php

namespace App\Services\Llm;

/**
 * OpenAI Responses wire (POST /v1/responses). System messages move to
 * `instructions`, history becomes `input` items, tool traffic maps to
 * function_call/function_call_output items and flattened function tools.
 */
class OpenAiResponsesDriver implements ProviderDriver
{
    public function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function endpoint(string $baseUrl): string
    {
        return $baseUrl.'/responses';
    }

    public function headers(string $apiKey, bool $stream): array
    {
        return [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
    }

    public function buildBody(array $payload): array
    {
        $instructions = [];
        $input = [];
        foreach ($payload['messages'] ?? [] as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');
            if ($role === 'system') {
                if ($content !== '') $instructions[] = $content;
                continue;
            }
            if ($role === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($message['tool_call_id'] ?? $message['name'] ?? ''),
                    'output' => $content,
                ];
                continue;
            }
            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                if ($content !== '') $input[] = ['role' => 'assistant', 'content' => $content];
                foreach ($message['tool_calls'] as $toolCall) {
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => (string) ($toolCall['id'] ?? ''),
                        'name' => (string) ($toolCall['function']['name'] ?? ''),
                        'arguments' => (string) ($toolCall['function']['arguments'] ?? '{}'),
                    ];
                }
                continue;
            }
            $input[] = ['role' => $role, 'content' => $content];
        }

        $body = [
            'model' => $payload['model'] ?? null,
            'input' => $input,
            'stream' => (bool) ($payload['stream'] ?? false),
        ];
        if ($instructions !== []) $body['instructions'] = implode("\n\n", $instructions);
        if (isset($payload['temperature']) && $payload['temperature'] !== null) {
            $body['temperature'] = (float) $payload['temperature'];
        }
        if (isset($payload['max_tokens'])) $body['max_output_tokens'] = (int) $payload['max_tokens'];
        if (! empty($payload['tools'])) {
            $body['tools'] = array_values(array_map(static fn (array $tool) => [
                'type' => 'function',
                'name' => (string) ($tool['function']['name'] ?? ''),
                'description' => (string) ($tool['function']['description'] ?? ''),
                'parameters' => $tool['function']['parameters'] ?? ['type' => 'object'],
            ], $payload['tools']));
            $choice = $payload['tool_choice'] ?? null;
            if (in_array($choice, ['auto', 'none', 'required'], true)) $body['tool_choice'] = $choice;
            elseif (is_array($choice) && isset($choice['function']['name'])) {
                $body['tool_choice'] = ['type' => 'function', 'name' => (string) $choice['function']['name']];
            }
        }

        return $body;
    }

    public function normalizeResponse(array $json): array
    {
        $text = '';
        $toolCalls = [];
        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? null) === 'output_text') $text .= (string) ($part['text'] ?? '');
                }
            }
            if (($item['type'] ?? null) === 'function_call') {
                $toolCalls[] = [
                    'id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($item['name'] ?? ''),
                        'arguments' => (string) ($item['arguments'] ?? '{}'),
                    ],
                ];
            }
        }
        $message = ['role' => 'assistant', 'content' => $text];
        if ($toolCalls !== []) $message['tool_calls'] = $toolCalls;

        $finishReason = 'stop';
        if ($toolCalls !== []) $finishReason = 'tool_calls';
        elseif (($json['status'] ?? null) === 'incomplete'
            && ($json['incomplete_details']['reason'] ?? null) === 'max_output_tokens') $finishReason = 'length';

        $usage = $json['usage'] ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);

        return [
            'id' => (string) ($json['id'] ?? ''),
            'object' => 'chat.completion',
            'model' => (string) ($json['model'] ?? ''),
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finishReason]],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => (int) ($usage['total_tokens'] ?? $prompt + $completion),
            ],
        ];
    }

    public function passthrough(): bool
    {
        return false;
    }

    public function streamAdapter(): ProviderStreamAdapter
    {
        return new OpenAiResponsesStreamAdapter();
    }
}
