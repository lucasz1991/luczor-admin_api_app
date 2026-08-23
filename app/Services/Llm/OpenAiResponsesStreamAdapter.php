<?php

namespace App\Services\Llm;

/**
 * Translates OpenAI Responses SSE events (response.output_text.delta,
 * response.output_item.added, response.function_call_arguments.delta,
 * response.completed) into canonical chat.completion.chunk frames.
 */
class OpenAiResponsesStreamAdapter implements ProviderStreamAdapter
{
    private string $id = '';

    private string $model = '';

    private bool $sawToolCall = false;

    private bool $done = false;

    /** @var array<int|string,int> upstream output index/item id -> canonical tool index */
    private array $toolIndex = [];

    public function feed(string $line): array
    {
        $line = trim($line);
        if (! str_starts_with($line, 'data:')) {
            return [];
        }
        $event = json_decode(trim(substr($line, 5)), true);
        if (! is_array($event)) {
            return [];
        }

        switch ($event['type'] ?? '') {
            case 'response.created':
                $response = $event['response'] ?? [];
                $this->id = (string) ($response['id'] ?? '');
                $this->model = (string) ($response['model'] ?? '');

                return [$this->frame(['role' => 'assistant', 'content' => ''])];
            case 'response.output_item.added':
                $item = $event['item'] ?? [];
                if (($item['type'] ?? null) !== 'function_call') {
                    return [];
                }
                $this->sawToolCall = true;
                $index = count($this->toolIndex);
                $this->toolIndex[(string) ($event['output_index'] ?? $item['id'] ?? $index)] = $index;

                return [$this->frame(['tool_calls' => [[
                    'index' => $index,
                    'id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    'type' => 'function',
                    'function' => ['name' => (string) ($item['name'] ?? ''), 'arguments' => ''],
                ]]])];
            case 'response.function_call_arguments.delta':
                $index = $this->toolIndex[(string) ($event['output_index'] ?? '')] ?? 0;

                return [$this->frame(['tool_calls' => [[
                    'index' => $index,
                    'function' => ['arguments' => (string) ($event['delta'] ?? '')],
                ]]])];
            case 'response.output_text.delta':
                $text = (string) ($event['delta'] ?? '');

                return $text === '' ? [] : [$this->frame(['content' => $text])];
            case 'response.completed':
            case 'response.incomplete':
                $this->done = true;
                $response = $event['response'] ?? [];
                $usage = $response['usage'] ?? [];
                $prompt = (int) ($usage['input_tokens'] ?? 0);
                $completion = (int) ($usage['output_tokens'] ?? 0);
                $finishReason = $this->sawToolCall ? 'tool_calls' : 'stop';
                if (($response['incomplete_details']['reason'] ?? null) === 'max_output_tokens') {
                    $finishReason = 'length';
                }

                return [$this->frame([], $finishReason, [
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens' => (int) ($usage['total_tokens'] ?? $prompt + $completion),
                ]), 'data: [DONE]'];
            default:
                return [];
        }
    }

    public function finish(): array
    {
        if ($this->done) {
            return [];
        }
        $this->done = true;

        return [$this->frame([], $this->sawToolCall ? 'tool_calls' : 'stop'), 'data: [DONE]'];
    }

    /** @param array<string,mixed> $delta */
    private function frame(array $delta, ?string $finishReason = null, ?array $usage = null): string
    {
        $chunk = [
            'id' => $this->id,
            'object' => 'chat.completion.chunk',
            'model' => $this->model,
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ];
        if ($usage !== null) {
            $chunk['usage'] = $usage;
        }

        return 'data: '.json_encode($chunk, JSON_UNESCAPED_UNICODE);
    }
}
