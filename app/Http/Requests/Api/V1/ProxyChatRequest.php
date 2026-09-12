<?php

namespace App\Http\Requests\Api\V1;

use App\Data\Proxy\ProxyChatInput;
use App\Services\Proxy\ProxyRequestAdmissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class ProxyChatRequest extends FormRequest
{
    private const DEFAULT_MAX_BODY_BYTES = 16 * 1024 * 1024;

    public function authorize(ProxyRequestAdmissionService $admission): bool
    {
        $admission->admit($this);
        $this->enforceBodyLimit();

        return true;
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        $rules = [
            // Accepted for wire compatibility, deliberately ignored for selection.
            'model' => ['nullable', 'string', 'max:180'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*' => ['required', 'array'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:100000'],
            'messages.*.name' => ['nullable', 'string', 'max:160'],
            'messages.*.tool_call_id' => ['nullable', 'string', 'max:200'],
            'messages.*.tool_calls' => ['nullable', 'array', 'max:64'],
            'messages.*.tool_calls.*' => ['required', 'array'],
            'messages.*.tool_calls.*.id' => ['required_with:messages.*.tool_calls', 'string', 'max:200'],
            'messages.*.tool_calls.*.type' => ['required_with:messages.*.tool_calls', 'in:function'],
            'messages.*.tool_calls.*.function' => ['required_with:messages.*.tool_calls', 'array'],
            'messages.*.tool_calls.*.function.name' => ['required_with:messages.*.tool_calls', 'string', 'max:160'],
            'messages.*.tool_calls.*.function.arguments' => ['required_with:messages.*.tool_calls', 'string', 'max:100000'],
            'tools' => ['nullable', 'array', 'max:64'],
            'tool_choice' => ['nullable'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'stream' => ['nullable', 'boolean'],
            'input_source' => ['nullable', 'string', 'in:keyboard,push_to_talk,hands_free'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'agent_team_policy_revision' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'workflow_id' => ['nullable', 'string', 'max:120'],
            'task_id' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'context_id' => ['nullable', 'string', 'max:120'],
            'repo_id' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:160'],
            'commit_sha' => ['nullable', 'string', 'max:80'],
            'tool_call_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];

        if (is_string($this->input('task_type')) && str_starts_with($this->input('task_type'), 'agent.')) {
            $rules += [
                'tools.*' => ['required', 'array:type,function'],
                'tools.*.type' => ['required', 'in:function'],
                'tools.*.function' => ['required', 'array:name,description,parameters,strict'],
                'tools.*.function.name' => ['required', 'in:context_search,context_read'],
                'tools.*.function.description' => ['sometimes', 'string', 'max:10000'],
                'tools.*.function.parameters' => ['required', 'array'],
                'tools.*.function.parameters.type' => ['required', 'in:object'],
                'tools.*.function.strict' => ['sometimes', 'boolean'],
            ];
        }

        return $rules;
    }

    public function toData(): ProxyChatInput
    {
        /** @var array<string,mixed> $validated */
        $validated = $this->validated();

        return ProxyChatInput::fromValidated($validated);
    }

    private function enforceBodyLimit(): void
    {
        $limit = max(1, (int) config('luczor.proxy.max_request_bytes', self::DEFAULT_MAX_BODY_BYTES));
        $declaredLength = $this->headers->get('Content-Length');
        $declaredTooLarge = is_numeric($declaredLength) && (int) $declaredLength > $limit;
        $actualTooLarge = strlen($this->getContent()) > $limit;

        if ($declaredTooLarge || $actualTooLarge) {
            throw new HttpResponseException(response()->json([
                'message' => 'Proxy request exceeds the server size limit.',
                'code' => 'proxy_request_too_large',
                'limit_bytes' => $limit,
            ], 413));
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Cross-field checks must not cast or index malformed input before type errors are returned.
            if ($validator->errors()->isNotEmpty()) {
                if (is_string($this->input('task_type')) && str_starts_with($this->input('task_type'), 'agent.')
                    && collect($validator->errors()->keys())->contains(fn ($key) => str_starts_with($key, 'tools.'))) {
                    $validator->errors()->add('tools', 'The selected specialist context tool contract is invalid.');
                }

                return;
            }
            $agentTask = str_starts_with((string) $this->input('task_type', ''), 'agent.');
            if ($agentTask && ! $this->filled('agent_team_policy_revision')) {
                $validator->errors()->add('agent_team_policy_revision', 'Agent specialists require the exact approved team policy revision.');
            }
            $selected = [];
            if ($agentTask) {
                $tools = $this->input('tools') ?? [];
                if (! array_is_list($tools)) {
                    $validator->errors()->add('tools', 'Context tools must be a list.');
                }
                foreach ($tools as $tool) {
                    $selected[] = $tool['function']['name'];
                }
                if (count($selected) > 2 || count($selected) !== count(array_unique($selected))
                    || ! in_array($this->input('tool_choice'), $selected === [] ? [null, 'none'] : [null, 'auto', 'none'], true)) {
                    $validator->errors()->add('tools', 'Invalid context tool selection.');
                }
            }
            $messages = $this->input('messages');
            if (! is_array($messages)) {
                return;
            }

            $pending = [];
            $seen = [];
            if ($agentTask && ! array_is_list($messages)) {
                $validator->errors()->add('messages', 'Specialist messages must be a list.');
            }

            foreach ($messages as $index => $message) {
                if (! is_array($message)) {
                    continue;
                }
                if ($agentTask && ($message['role'] ?? null) === 'tool' && ! in_array($message['name'] ?? null, $selected, true)) {
                    $validator->errors()->add("messages.$index", 'Only selected context tool results are accepted.');
                }
                if ($agentTask) {
                    $calls = $message['tool_calls'] ?? [];
                    if (! array_is_list($calls)) {
                        $validator->errors()->add("messages.$index.tool_calls", 'Context calls must be a list.');
                    }
                    if (($message['role'] ?? null) !== 'tool' && $pending !== []) {
                        $validator->errors()->add("messages.$index", 'Complete each selected context call before continuing the conversation.');
                    }
                    foreach ($calls as $call) {
                        if (! is_array($call) || ! in_array($call['function']['name'] ?? null, $selected, true)) {
                            $validator->errors()->add("messages.$index", 'Only selected context tool calls are accepted.');
                        }
                        $callId = $call['id'];
                        if (($message['role'] ?? null) !== 'assistant' || isset($seen[$callId])) {
                            $validator->errors()->add("messages.$index", 'Context calls require an assistant message and a unique call ID.');
                        }
                        $pending[$callId] = $call['function']['name'];
                        $seen[$callId] = true;
                    }
                    if (($message['role'] ?? null) === 'tool') {
                        $callId = $message['tool_call_id'] ?? '';
                        if (! isset($pending[$callId]) || $pending[$callId] !== ($message['name'] ?? null)) {
                            $validator->errors()->add("messages.$index", 'Context results must match a preceding selected call ID and name.');
                        } else {
                            unset($pending[$callId]);
                        }
                    }
                }
                if (($message['role'] ?? null) === 'tool'
                    && blank($message['tool_call_id'] ?? null)
                    && blank($message['name'] ?? null)) {
                    $validator->errors()->add("messages.$index", 'Tool messages require tool_call_id or name.');
                }
                if (($message['role'] ?? null) !== 'assistant' || empty($message['tool_calls']) || ! is_array($message['tool_calls'])) {
                    continue;
                }
                foreach ($message['tool_calls'] as $toolIndex => $toolCall) {
                    if (! is_array($toolCall)
                        || blank($toolCall['id'] ?? null)
                        || blank($toolCall['function']['name'] ?? null)) {
                        $validator->errors()->add(
                            "messages.$index.tool_calls.$toolIndex",
                            'Assistant tool calls require id and function name.',
                        );
                    }
                }
            }
            if ($agentTask && $pending !== []) {
                $validator->errors()->add('messages', 'Specialist context history contains calls without results.');
            }
        });
    }
}
