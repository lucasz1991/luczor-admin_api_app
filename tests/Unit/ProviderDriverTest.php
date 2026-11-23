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
        $registry = new ProviderDriverRegistry;

        $anthropic = new ProviderCredential(['provider' => 'anthropic', 'request_format' => 'messages']);
        $openai = new ProviderCredential(['provider' => 'openai', 'request_format' => 'responses']);
        $openrouter = new ProviderCredential(['provider' => 'openrouter', 'request_format' => 'chat_completions']);
        $this->assertInstanceOf(AnthropicMessagesDriver::class, $registry->for('anthropic', $anthropic));
        $this->assertInstanceOf(OpenAiResponsesDriver::class, $registry->for('openai', $openai));
        $this->assertInstanceOf(OpenAiCompatDriver::class, $registry->for('openrouter', $openrouter));

        $compat = new ProviderCredential(['provider' => 'openai', 'request_format' => 'chat_completions', 'meta' => ['wire' => 'responses']]);
        $this->assertInstanceOf(OpenAiCompatDriver::class, $registry->for('openai', $compat));
    }

    public function test_openai_compat_driver_passes_through_and_targets_the_right_base_url(): void
    {
        $openrouter = new OpenAiCompatDriver('openrouter');
        $this->assertTrue($openrouter->passthrough());
        $this->assertSame('https://openrouter.ai/api/v1', $openrouter->defaultBaseUrl());
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $openrouter->endpoint($openrouter->defaultBaseUrl()));
        // Passthrough drivers leave the response canonical; the openrouter body
        // only gains reasoning.exclude (internal reasoning kept, never streamed).
        $payload = ['model' => 'x', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true];
        $this->assertSame($payload + ['reasoning' => ['exclude' => true]], $openrouter->buildBody($payload));
        $this->assertSame(['id' => 'abc'], $openrouter->normalizeResponse(['id' => 'abc']));

        // The plain OpenAI-compatible wire strips any reasoning directive.
        $openai = new OpenAiCompatDriver('openai');
        $this->assertSame('https://api.openai.com/v1', $openai->defaultBaseUrl());
        $this->assertArrayNotHasKey('reasoning', $openai->buildBody($payload + ['reasoning' => ['exclude' => true]]));
    }

    public function test_anthropic_never_sends_sampling_parameters_and_defaults_max_tokens(): void
    {
        $driver = new AnthropicMessagesDriver;
        $messages = [['role' => 'user', 'content' => 'x']];

        // Sampling parameters are rejected by the pinned API version.
        $plain = $driver->buildBody(['model' => 'claude', 'messages' => $messages, 'temperature' => 1.9, 'top_p' => 0.5, 'top_k' => 5]);
        $this->assertArrayNotHasKey('temperature', $plain);
        $this->assertArrayNotHasKey('top_p', $plain);
        $this->assertArrayNotHasKey('top_k', $plain);
        $this->assertSame(16000, $plain['max_tokens']);       // non-stream default

        $stream = $driver->buildBody(['model' => 'claude', 'messages' => $messages, 'stream' => true]);
        $this->assertSame(64000, $stream['max_tokens']);      // stream default

        // A profile/policy budget always wins over the defaults.
        $budgeted = $driver->buildBody(['model' => 'claude', 'messages' => $messages, 'stream' => true, 'max_tokens' => 200]);
        $this->assertSame(200, $budgeted['max_tokens']);
    }

    public function test_anthropic_headers_pin_the_api_version_and_gate_the_fallback_beta(): void
    {
        $driver = new AnthropicMessagesDriver;

        $plain = $driver->headers('test-key', false);
        $this->assertSame('2026-10-21', $plain['anthropic-version']);
        $this->assertSame('test-key', $plain['x-api-key']);
        $this->assertArrayNotHasKey('anthropic-beta', $plain);

        $fallback = $driver->headers('test-key', true, ['model' => 'claude-opus-5-5', 'provider_fallback' => true]);
        $this->assertSame('server-side-fallback-2026-07-01', $fallback['anthropic-beta']);
        $this->assertSame('text/event-stream', $fallback['Accept']);

        // Haiku has no server-side fallback; a non-boolean flag never opts in.
        $this->assertArrayNotHasKey('anthropic-beta', $driver->headers('test-key', true, ['model' => 'claude-haiku-5-5', 'provider_fallback' => true]));
        $this->assertArrayNotHasKey('anthropic-beta', $driver->headers('test-key', true, ['model' => 'claude-opus-5-5', 'provider_fallback' => 'true']));
    }

    public function test_anthropic_body_adds_fallbacks_only_when_opted_in_and_supported(): void
    {
        $driver = new AnthropicMessagesDriver;
        $messages = [['role' => 'user', 'content' => 'x']];

        $this->assertSame('default', $driver->buildBody(['model' => 'claude-sonnet-5-5', 'messages' => $messages, 'provider_fallback' => true])['fallbacks']);
        $this->assertArrayNotHasKey('fallbacks', $driver->buildBody(['model' => 'claude-haiku-5-5', 'messages' => $messages, 'provider_fallback' => true]));
        $this->assertArrayNotHasKey('fallbacks', $driver->buildBody(['model' => 'claude-sonnet-5-5', 'messages' => $messages, 'provider_fallback' => false]));
        $body = $driver->buildBody(['model' => 'claude-sonnet-5-5', 'messages' => $messages, 'effort' => 'high']);
        $this->assertArrayNotHasKey('fallbacks', $body);
        // The internal routing hints never leak into the provider body.
        $this->assertArrayNotHasKey('provider_fallback', $body);
        $this->assertArrayNotHasKey('effort', $body);
    }

    public function test_anthropic_body_sends_adaptive_thinking_and_every_supported_effort(): void
    {
        $driver = new AnthropicMessagesDriver;
        $messages = [['role' => 'user', 'content' => 'x']];

        $plain = $driver->buildBody(['model' => 'claude', 'messages' => $messages]);
        $this->assertSame(['type' => 'adaptive'], $plain['thinking']);
        $this->assertArrayNotHasKey('output_config', $plain);

        foreach (['low', 'medium', 'high', 'xhigh', 'max'] as $effort) {
            $body = $driver->buildBody(['model' => 'claude', 'messages' => $messages, 'effort' => $effort]);
            $this->assertSame(['effort' => $effort], $body['output_config']);
            $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        }
        $this->assertArrayNotHasKey('output_config', $driver->buildBody(['model' => 'claude', 'messages' => $messages, 'effort' => 'ultra']));
    }

    public function test_anthropic_never_forces_tool_use(): void
    {
        $driver = new AnthropicMessagesDriver;
        $payload = [
            'model' => 'claude', 'messages' => [['role' => 'user', 'content' => 'x']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'clock_now', 'parameters' => ['type' => 'object']]]],
        ];

        // Forced choices are rejected by current models and degrade to auto.
        $this->assertArrayNotHasKey('tool_choice', $driver->buildBody($payload + ['tool_choice' => 'required']));
        $this->assertArrayNotHasKey('tool_choice', $driver->buildBody($payload + ['tool_choice' => ['type' => 'function', 'function' => ['name' => 'clock_now']]]));
        $this->assertArrayNotHasKey('tool_choice', $driver->buildBody($payload + ['tool_choice' => 'auto']));
        $this->assertSame(['type' => 'none'], $driver->buildBody($payload + ['tool_choice' => 'none'])['tool_choice']);
    }

    public function test_anthropic_system_prompt_is_one_cacheable_block(): void
    {
        $driver = new AnthropicMessagesDriver;

        $body = $driver->buildBody(['model' => 'claude', 'messages' => [
            ['role' => 'system', 'content' => 'Regel A'],
            ['role' => 'system', 'content' => 'Regel B'],
            ['role' => 'user', 'content' => 'x'],
        ]]);
        $this->assertSame([[
            'type' => 'text',
            'text' => "Regel A\n\nRegel B",
            'cache_control' => ['type' => 'ephemeral'],
        ]], $body['system']);

        // No system text, no system field (an empty cache block is invalid).
        $this->assertArrayNotHasKey('system', $driver->buildBody(['model' => 'claude', 'messages' => [
            ['role' => 'system', 'content' => ''],
            ['role' => 'user', 'content' => 'x'],
        ]]));
    }

    public function test_anthropic_refusal_before_output_maps_to_content_filter(): void
    {
        $canonical = (new AnthropicMessagesDriver)->normalizeResponse([
            'id' => 'msg_r', 'model' => 'claude-opus-5-5', 'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
            'content' => [],
            'usage' => ['input_tokens' => 4, 'output_tokens' => 0],
        ]);

        $this->assertSame('content_filter', $canonical['choices'][0]['finish_reason']);
        $this->assertSame(['category' => 'cyber', 'phase' => 'before_output'], $canonical['luczor_refusal']);
        $this->assertSame('', $canonical['choices'][0]['message']['content']);
    }

    public function test_anthropic_refusal_after_partial_output_is_marked_mid_stream(): void
    {
        $canonical = (new AnthropicMessagesDriver)->normalizeResponse([
            'id' => 'msg_r', 'model' => 'claude-opus-5-5', 'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null],
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'Teil']],
            'usage' => ['input_tokens' => 4, 'output_tokens' => 2],
        ]);

        $this->assertSame(['category' => null, 'phase' => 'mid_stream'], $canonical['luczor_refusal']);

        // Ordinary responses never carry the marker.
        $normal = (new AnthropicMessagesDriver)->normalizeResponse(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'ok']]]);
        $this->assertArrayNotHasKey('luczor_refusal', $normal);
    }

    public function test_anthropic_response_reports_cache_tokens_and_skips_unknown_blocks(): void
    {
        $canonical = (new AnthropicMessagesDriver)->normalizeResponse([
            'id' => 'msg_c', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'fallback', 'model' => 'claude-opus-5'],
                ['type' => 'text', 'text' => 'Antwort'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 6, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 120],
        ]);

        $this->assertSame('Antwort', $canonical['choices'][0]['message']['content']);
        $this->assertSame([
            'prompt_tokens' => 10, 'completion_tokens' => 6, 'total_tokens' => 16,
            'prompt_tokens_details' => ['cached_tokens' => 900],
            'cache_write_tokens' => 120,
        ], $canonical['usage']);
    }

    public function test_anthropic_stream_adapter_marks_refusals_in_the_final_frame(): void
    {
        $before = $this->streamFrames([
            ['type' => 'message_start', 'message' => ['id' => 'msg_r', 'model' => 'claude-opus-5-5', 'usage' => ['input_tokens' => 5]]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'bio']], 'usage' => ['output_tokens' => 0]],
            ['type' => 'message_stop'],
        ]);
        $final = $this->decodeFrame($before[count($before) - 2]);
        $this->assertSame('content_filter', $final['choices'][0]['finish_reason']);
        $this->assertSame(['category' => 'bio', 'phase' => 'before_output'], $final['luczor_refusal']);
        $this->assertSame(5, $final['usage']['prompt_tokens']);
        $this->assertSame('data: [DONE]', end($before));

        $mid = $this->streamFrames([
            ['type' => 'message_start', 'message' => ['id' => 'msg_r', 'model' => 'claude-opus-5-5', 'usage' => ['input_tokens' => 5]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Teil']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null]], 'usage' => ['output_tokens' => 2]],
            ['type' => 'message_stop'],
        ]);
        $final = $this->decodeFrame($mid[count($mid) - 2]);
        $this->assertSame(['category' => null, 'phase' => 'mid_stream'], $final['luczor_refusal']);
        // Only the final frame carries the marker.
        $this->assertStringNotContainsString('luczor_refusal', $mid[1]);

        $normal = implode("\n", $this->streamFrames([
            ['type' => 'message_start', 'message' => ['id' => 'm', 'model' => 'claude', 'usage' => ['input_tokens' => 1]]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'model_context_window_exceeded'], 'usage' => ['output_tokens' => 1]],
            ['type' => 'message_stop'],
        ]));
        $this->assertStringNotContainsString('luczor_refusal', $normal);
        $this->assertStringContainsString('"finish_reason":"length"', $normal);
    }

    public function test_anthropic_stream_adapter_skips_unknown_blocks_and_keeps_tool_indexes_dense(): void
    {
        $frames = $this->streamFrames([
            ['type' => 'message_start', 'message' => ['id' => 'msg_t', 'model' => 'claude-opus-5-5', 'usage' => ['input_tokens' => 7, 'cache_read_input_tokens' => 300, 'cache_creation_input_tokens' => 40]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'geheim']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'fallback', 'model' => 'claude-opus-5']],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":1}']],
            ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'clock_now']],
            ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"tz":']],
            ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"UTC"}']],
            ['type' => 'content_block_start', 'index' => 5, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'file_read']],
            ['type' => 'content_block_delta', 'index' => 5, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'ping'],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['input_tokens' => 9, 'output_tokens' => 21]],
            ['type' => 'message_stop'],
        ]);
        $joined = implode("\n", $frames);

        // Thinking and unknown blocks never reach the client.
        $this->assertStringNotContainsString('geheim', $joined);
        $this->assertStringNotContainsString('web_search', $joined);
        $this->assertStringNotContainsString('fallback', $joined);

        $toolFrames = [];
        foreach ($frames as $frame) {
            $toolCall = $frame === 'data: [DONE]' ? null : ($this->decodeFrame($frame)['choices'][0]['delta']['tool_calls'][0] ?? null);
            if ($toolCall !== null) {
                $toolFrames[] = $toolCall;
            }
        }
        $this->assertSame([0, 0, 0, 1, 1], array_column($toolFrames, 'index'));
        $this->assertSame('toolu_1', $toolFrames[0]['id']);
        $this->assertSame('{"tz":', $toolFrames[1]['function']['arguments']);
        $this->assertSame('toolu_2', $toolFrames[3]['id']);

        $final = $this->decodeFrame($frames[count($frames) - 2]);
        $this->assertSame('tool_calls', $final['choices'][0]['finish_reason']);
        // message_delta input tokens override message_start; cache counts survive.
        $this->assertSame([
            'prompt_tokens' => 9, 'completion_tokens' => 21, 'total_tokens' => 30,
            'prompt_tokens_details' => ['cached_tokens' => 300],
            'cache_write_tokens' => 40,
        ], $final['usage']);
    }

    public function test_openai_compat_driver_strips_anthropic_only_routing_hints(): void
    {
        $body = (new OpenAiCompatDriver('openai'))->buildBody([
            'model' => 'x', 'messages' => [['role' => 'user', 'content' => 'hi']], 'effort' => 'high', 'provider_fallback' => true,
        ]);

        $this->assertArrayNotHasKey('effort', $body);
        $this->assertArrayNotHasKey('provider_fallback', $body);
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<int,string>
     */
    private function streamFrames(array $events): array
    {
        $adapter = new AnthropicStreamAdapter;
        $frames = [];
        foreach ($events as $event) {
            $frames = array_merge($frames, $adapter->feed('data: '.json_encode($event, JSON_THROW_ON_ERROR)));
        }

        return array_merge($frames, $adapter->finish());
    }

    /** @return array<string,mixed> */
    private function decodeFrame(string $frame): array
    {
        return json_decode(substr($frame, 6), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_anthropic_merges_consecutive_same_role_turns(): void
    {
        $driver = new AnthropicMessagesDriver;

        $body = $driver->buildBody(['model' => 'claude', 'messages' => [
            ['role' => 'user', 'content' => 'erste Frage'],
            ['role' => 'user', 'content' => 'zweite Frage'],
            ['role' => 'assistant', 'content' => 'Antwort'],
        ]]);

        // The API rejects non-alternating roles; the two user turns must merge.
        $this->assertCount(2, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertCount(2, $body['messages'][0]['content']);
        $this->assertSame('assistant', $body['messages'][1]['role']);
    }

    public function test_finish_reason_mapping_covers_provider_stop_reasons(): void
    {
        $this->assertSame('length', AnthropicMessagesDriver::finishReason('max_tokens'));
        $this->assertSame('tool_calls', AnthropicMessagesDriver::finishReason('tool_use'));
        $this->assertSame('stop', AnthropicMessagesDriver::finishReason('end_turn'));
        $this->assertSame('stop', AnthropicMessagesDriver::finishReason(null));
        $this->assertSame('content_filter', AnthropicMessagesDriver::finishReason('refusal'));
        $this->assertSame('length', AnthropicMessagesDriver::finishReason('model_context_window_exceeded'));
    }

    public function test_openai_responses_maps_incomplete_max_output_tokens_to_length(): void
    {
        $driver = new OpenAiResponsesDriver;

        $canonical = $driver->normalizeResponse([
            'id' => 'resp_1', 'model' => 'gpt', 'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'teilweise']]]],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
        ]);

        $this->assertSame('length', $canonical['choices'][0]['finish_reason']);
        $this->assertSame('teilweise', $canonical['choices'][0]['message']['content']);
        $this->assertSame(8, $canonical['usage']['total_tokens']);
    }

    public function test_anthropic_stream_adapter_finish_is_idempotent_after_message_stop(): void
    {
        $adapter = new AnthropicStreamAdapter;
        $adapter->feed('data: '.json_encode(['type' => 'message_start', 'message' => ['id' => 'm', 'model' => 'claude', 'usage' => ['input_tokens' => 1]]]));
        $stop = $adapter->feed('data: '.json_encode(['type' => 'message_stop']));
        $this->assertContains('data: [DONE]', $stop);
        // finish() after an explicit message_stop must not emit a second DONE.
        $this->assertSame([], $adapter->finish());
    }

    public function test_anthropic_body_moves_system_up_and_maps_tool_traffic(): void
    {
        $driver = new AnthropicMessagesDriver;

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

        $this->assertSame("Regel A\n\nRegel B", $body['system'][0]['text']);
        $this->assertArrayNotHasKey('temperature', $body); // sampling parameters are never sent
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
        $driver = new AnthropicMessagesDriver;

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
        $adapter = new AnthropicStreamAdapter;
        $frames = [];
        $lines = [
            'data: {"type":"message_start","message":{"id":"msg_s","model":"claude-x","usage":{"input_tokens":9}}}',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text"}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hallo"}}',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":3}}',
            'data: {"type":"message_stop"}',
        ];
        foreach ($lines as $line) {
            $frames = array_merge($frames, $adapter->feed($line));
        }
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
        $driver = new OpenAiResponsesDriver;

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
        $driver = new OpenAiResponsesDriver;

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
        $adapter = new OpenAiResponsesStreamAdapter;
        $frames = [];
        $lines = [
            'data: {"type":"response.created","response":{"id":"resp_s","model":"gpt-x"}}',
            'data: {"type":"response.output_text.delta","delta":"Hal"}',
            'data: {"type":"response.output_text.delta","delta":"lo"}',
            'data: {"type":"response.completed","response":{"id":"resp_s","usage":{"input_tokens":6,"output_tokens":2,"total_tokens":8}}}',
        ];
        foreach ($lines as $line) {
            $frames = array_merge($frames, $adapter->feed($line));
        }
        $frames = array_merge($frames, $adapter->finish());

        $joined = implode("\n", $frames);
        $this->assertStringContainsString('"content":"Hal"', $joined);
        $this->assertStringContainsString('"content":"lo"', $joined);
        $this->assertStringContainsString('"finish_reason":"stop"', $joined);
        $this->assertStringContainsString('"prompt_tokens":6', $joined);
        $this->assertSame('data: [DONE]', end($frames));
    }
}
