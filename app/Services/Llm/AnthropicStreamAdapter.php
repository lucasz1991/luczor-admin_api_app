<?php

namespace App\Services\Llm;

/**
 * Translates Anthropic Messages SSE events (message_start, content_block_*,
 * message_delta, message_stop) into canonical chat.completion.chunk frames.
 * Unknown content block types (thinking, fallback markers, server tools) are
 * skipped without disturbing the canonical tool-call index.
 */
class AnthropicStreamAdapter implements ProviderStreamAdapter
{
    private string $id = '';

    private string $model = '';

    /** @var array<string,mixed> merged upstream usage (message_start + message_delta) */
    private array $usage = [];

    private ?string $finishReason = null;

    /** @var array{category: ?string, phase: string}|null */
    private ?array $refusal = null;

    private bool $producedOutput = false;

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
                $this->mergeUsage($message['usage'] ?? null);

                return [$this->frame(['role' => 'assistant', 'content' => ''])];
            case 'content_block_start':
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? null) !== 'tool_use') {
                    return [];
                }
                $index = count($this->toolIndex);
                $this->toolIndex[(int) ($event['index'] ?? 0)] = $index;
                $this->producedOutput = true;

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
                    if ($text === '') {
                        return [];
                    }
                    $this->producedOutput = true;

                    return [$this->frame(['content' => $text])];
                }
                if (($delta['type'] ?? null) === 'input_json_delta') {
                    $index = $this->toolIndex[(int) ($event['index'] ?? 0)] ?? null;
                    if ($index === null) {
                        // Arguments of a block that never became a canonical tool call.
                        return [];
                    }

                    return [$this->frame(['tool_calls' => [[
                        'index' => $index,
                        'function' => ['arguments' => (string) ($delta['partial_json'] ?? '')],
                    ]]])];
                }

                return [];
            case 'message_delta':
                $stopReason = $event['delta']['stop_reason'] ?? null;
                $stopReason = is_string($stopReason) ? $stopReason : null;
                $this->finishReason = AnthropicMessagesDriver::finishReason($stopReason);
                if ($stopReason === 'refusal') {
                    $this->refusal = AnthropicMessagesDriver::refusal(
                        $event['delta']['stop_details'] ?? $event['stop_details'] ?? null,
                        $this->producedOutput,
                    );
                }
                $this->mergeUsage($event['usage'] ?? null);

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

    private function mergeUsage(mixed $usage): void
    {
        if (! is_array($usage)) {
            return;
        }
        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $this->usage[$key] = (int) $usage[$key];
            }
        }
    }

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $extra  additional top-level chunk keys
     */
    private function frame(array $delta, ?string $finishReason = null, array $extra = []): string
    {
        $chunk = [
            'id' => $this->id,
            'object' => 'chat.completion.chunk',
            'model' => $this->model,
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ] + $extra;

        return 'data: '.json_encode($chunk, JSON_UNESCAPED_UNICODE);
    }

    private function finalFrame(): string
    {
        $extra = ['usage' => AnthropicMessagesDriver::usage($this->usage)];
        if ($this->refusal !== null) {
            $extra['luczor_refusal'] = $this->refusal;
        }

        return $this->frame([], $this->finishReason ?? 'stop', $extra);
    }
}
