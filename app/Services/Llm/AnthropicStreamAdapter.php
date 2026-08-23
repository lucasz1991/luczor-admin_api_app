<?php

namespace App\Services\Llm;

/**
 * Translates Anthropic Messages SSE events (message_start, content_block_*,
 * message_delta, message_stop) into canonical chat.completion.chunk frames.
 */
class AnthropicStreamAdapter implements ProviderStreamAdapter
{
    private string $id = '';

    private string $model = '';

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private ?string $finishReason = null;

    /** @var array<int,int> upstream content-block index -> canonical tool index */
    private array $toolIndex = [];

    private bool $done = false;

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
            case 'message_start':
                $message = $event['message'] ?? [];
                $this->id = (string) ($message['id'] ?? '');
                $this->model = (string) ($message['model'] ?? '');
                $this->promptTokens = (int) ($message['usage']['input_tokens'] ?? 0);

                return [$this->frame(['role' => 'assistant', 'content' => ''])];
            case 'content_block_start':
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? null) !== 'tool_use') {
                    return [];
                }
                $index = count($this->toolIndex);
                $this->toolIndex[(int) ($event['index'] ?? 0)] = $index;

                return [$this->frame(['tool_calls' => [[
                    'index' => $index,
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => ['name' => (string) ($block['name'] ?? ''), 'arguments' => ''],
                ]]])];
            case 'content_block_delta':
                $delta = $event['delta'] ?? [];
                if (($delta['type'] ?? null) === 'text_delta') {
                    $text = (string) ($delta['text'] ?? '');

                    return $text === '' ? [] : [$this->frame(['content' => $text])];
                }
                if (($delta['type'] ?? null) === 'input_json_delta') {
                    $index = $this->toolIndex[(int) ($event['index'] ?? 0)] ?? 0;

                    return [$this->frame(['tool_calls' => [[
                        'index' => $index,
                        'function' => ['arguments' => (string) ($delta['partial_json'] ?? '')],
                    ]]])];
                }

                return [];
            case 'message_delta':
                $this->finishReason = AnthropicMessagesDriver::finishReason($event['delta']['stop_reason'] ?? null);
                $this->completionTokens = (int) ($event['usage']['output_tokens'] ?? $this->completionTokens);

                return [];
            case 'message_stop':
                $this->done = true;

                return [$this->finalFrame(), 'data: [DONE]'];
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

        return [$this->finalFrame(), 'data: [DONE]'];
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

    private function finalFrame(): string
    {
        return $this->frame([], $this->finishReason ?? 'stop', [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->promptTokens + $this->completionTokens,
        ]);
    }
}
