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
        return [
            // Accepted for wire compatibility, deliberately ignored for selection.
            'model' => ['nullable', 'string', 'max:180'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:100000'],
            'messages.*.name' => ['nullable', 'string', 'max:160'],
            'messages.*.tool_call_id' => ['nullable', 'string', 'max:200'],
            'messages.*.tool_calls' => ['nullable', 'array', 'max:64'],
            'messages.*.tool_calls.*.id' => ['required_with:messages.*.tool_calls', 'string', 'max:200'],
            'messages.*.tool_calls.*.type' => ['required_with:messages.*.tool_calls', 'in:function'],
            'messages.*.tool_calls.*.function.name' => ['required_with:messages.*.tool_calls', 'string', 'max:160'],
            'messages.*.tool_calls.*.function.arguments' => ['required_with:messages.*.tool_calls', 'string', 'max:100000'],
            'tools' => ['nullable', 'array', 'max:64'],
            'tool_choice' => ['nullable'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'stream' => ['nullable', 'boolean'],
            'input_source' => ['nullable', 'string', 'in:keyboard,push_to_talk,hands_free'],
            'task_type' => ['nullable', 'string', 'max:120'],
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
            $messages = $this->input('messages');
            if (! is_array($messages)) {
                return;
            }

            foreach ($messages as $index => $message) {
                if (! is_array($message)) {
                    continue;
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
        });
    }
}
