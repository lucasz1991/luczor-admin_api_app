<?php

namespace Tests\Unit;

use App\Models\ProviderCredential;
use App\Services\Llm\AnthropicMessagesDriver;
use App\Services\Llm\AnthropicStreamAdapter;
use App\Services\Llm\OpenAiCompatDriver;
use App\Services\Llm\OpenAiResponsesDriver;
use App\Services\Llm\OpenAiResponsesStreamAdapter;
use App\Services\Llm\ProviderDriverRegistry;
use Tests\TestCase;

class ProviderDriverTest extends TestCase
{
    public function test_registry_resolves_the_driver_by_provider_with_wire_override(): void
    {
        $registry = new ProviderDriverRegistry();

        $this->assertInstanceOf(AnthropicMessagesDriver::class, $registry->for('anthropic'));
        $this->assertInstanceOf(OpenAiResponsesDriver::class, $registry->for('openai'));
        $this->assertInstanceOf(OpenAiCompatDriver::class, $registry->for('openrouter'));

        $compat = new ProviderCredential(['provider' => 'openai', 'meta' => ['wire' => 'chat_completions']]);
        $this->assertInstanceOf(OpenAiCompatDriver::class, $registry->for('openai', $compat));
    }

    public function test_anthropic_body_moves_system_up_and_maps_tool_traffic(): void
    {
        $driver = new AnthropicMessagesDriver();

        $body = $driver->buildBody([
            'model' => 'claude-x', 'temperature' => 1.7, 'max_tokens' => 50, 'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => 'Regel A'],
                ['role' => 'system', 'content' => 'Regel B'],
                ['role' => 'user', 'content' => 'Wie spät ist es?'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => 'clock_now', 'arguments' => '{"tz":"Europe/Berlin"}'],
                ]]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '12:00'],
                ['role' => 'user', 'content' => 'Danke.'],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => ['name' => 'clock_now', 'description' => 'Uhrzeit', 'parameters' => ['type' => 'object']],
            ]],
        ]);

        $this->assertSame("Regel A\n\nRegel B", $body['system']);
        $this->assertSame(1.0, $body['temperature']); // Anthropic caps at 1
        $this->assertSame(50, $body['max_tokens']);
        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        $this->assertSame(['tz' => 'Europe/Berlin'], $body['messages'][1]['content'][0]['input']);
        // tool result and the following user text merge into ONE user turn (alternation).
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('call_1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('Danke.', $body['messages'][2]['content'][1]['text']);
        $this->assertCount(3, $body['messages']);
        $this->assertSame('clock_now', $body['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
    }

    public function test_anthropic_response_normalizes_to_canonical_chat_completion(): void
    {
        $driver = new AnthropicMessagesDriver();

        $canonical = $driver->normalizeResponse([
            'id' => 'msg_1', 'model' => 'claude-x', 'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Ich rufe das Tool auf.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'clock_now', 'input' => ['tz' => 'UTC']],
            ],
            'usage' => ['input_tokens' => 11, 'output_tokens' => 5],
        ]);

        $message = $canonical['choices'][0]['message'];
        $this->assertSame('Ich rufe das Tool auf.', $message['content']);
        $this->assertSame('clock_now', $message['tool_calls'][0]['function']['name']);
        $this->assertSame('{"tz":"UTC"}', $message['tool_calls'][0]['function']['arguments']);
        $this->assertSame('tool_calls', $canonical['choices'][0]['finish_reason']);
        $this->assertSame(['prompt_tokens' => 11, 'completion_tokens' => 5, 'total_tokens' => 16], $canonical['usage']);
    }

    public function test_anthropic_stream_adapter_emits_canonical_frames_and_done(): void
    {
        $adapter = new AnthropicStreamAdapter();
        $frames = [];
        $lines = [
            'data: {"type":"message_start","message":{"id":"msg_s","model":"claude-x","usage":{"input_tokens":9}}}',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text"}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hallo"}}',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":3}}',
            'data: {"type":"message_stop"}',
        ];
        foreach ($lines as $line) $frames = array_merge($frames, $adapter->feed($line));
        $frames = array_merge($frames, $adapter->finish());

        $joined = implode("\n", $frames);
        $this->assertStringContainsString('"content":"Hallo"', $joined);
        $this->assertStringContainsString('"finish_reason":"stop"', $joined);
        $this->assertStringContainsString('"prompt_tokens":9', $joined);
        $this->assertStringContainsString('"completion_tokens":3', $joined);
        $this->assertSame('data: [DONE]', end($frames));
    }

    public function test_openai_responses_body_uses_input_items_and_flattened_tools(): void
    {
        $driver = new OpenAiResponsesDriver();

        $body = $driver->buildBody([
            'model' => 'gpt-x', 'temperature' => 0.2, 'max_tokens' => 40, 'stream' => true,
            'messages' => [
                ['role' => 'system', 'content' => 'Systemregel'],
                ['role' => 'user', 'content' => 'Hallo'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'call_9', 'type' => 'function',
                    'function' => ['name' => 'clock_now', 'arguments' => '{}'],
                ]]],
                ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '12:00'],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => ['name' => 'clock_now', 'description' => 'Uhrzeit', 'parameters' => ['type' => 'object']],
            ]],
            'tool_choice' => 'auto',
        ]);

        $this->assertSame('Systemregel', $body['instructions']);
        $this->assertSame(40, $body['max_output_tokens']);
        $this->assertArrayNotHasKey('messages', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertSame(['role' => 'user', 'content' => 'Hallo'], $body['input'][0]);
        $this->assertSame('function_call', $body['input'][1]['type']);
        $this->assertSame('call_9', $body['input'][1]['call_id']);
        $this->assertSame('function_call_output', $body['input'][2]['type']);
        $this->assertSame('12:00', $body['input'][2]['output']);
        $this->assertSame('clock_now', $body['tools'][0]['name']); // flattened, no nested function
        $this->assertSame('auto', $body['tool_choice']);
    }

    public function test_openai_responses_response_normalizes_output_to_canonical_shape(): void
    {
        $driver = new OpenAiResponsesDriver();

        $canonical = $driver->normalizeResponse([
            'id' => 'resp_1', 'model' => 'gpt-x', 'status' => 'completed',
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hallo von GPT']]],
                ['type' => 'function_call', 'call_id' => 'call_2', 'name' => 'clock_now', 'arguments' => '{"tz":"UTC"}'],
            ],
            'usage' => ['input_tokens' => 7, 'output_tokens' => 2, 'total_tokens' => 9],
        ]);

        $this->assertSame('Hallo von GPT', $canonical['choices'][0]['message']['content']);
        $this->assertSame('call_2', $canonical['choices'][0]['message']['tool_calls'][0]['id']);
        $this->assertSame('tool_calls', $canonical['choices'][0]['finish_reason']);
        $this->assertSame(['prompt_tokens' => 7, 'completion_tokens' => 2, 'total_tokens' => 9], $canonical['usage']);
    }

    public function test_openai_responses_stream_adapter_translates_deltas_and_completion(): void
    {
        $adapter = new OpenAiResponsesStreamAdapter();
        $frames = [];
        $lines = [
            'data: {"type":"response.created","response":{"id":"resp_s","model":"gpt-x"}}',
            'data: {"type":"response.output_text.delta","delta":"Hal"}',
            'data: {"type":"response.output_text.delta","delta":"lo"}',
            'data: {"type":"response.completed","response":{"id":"resp_s","usage":{"input_tokens":6,"output_tokens":2,"total_tokens":8}}}',
        ];
        foreach ($lines as $line) $frames = array_merge($frames, $adapter->feed($line));
        $frames = array_merge($frames, $adapter->finish());

        $joined = implode("\n", $frames);
        $this->assertStringContainsString('"content":"Hal"', $joined);
        $this->assertStringContainsString('"content":"lo"', $joined);
        $this->assertStringContainsString('"finish_reason":"stop"', $joined);
        $this->assertStringContainsString('"prompt_tokens":6', $joined);
        $this->assertSame('data: [DONE]', end($frames));
    }
}
