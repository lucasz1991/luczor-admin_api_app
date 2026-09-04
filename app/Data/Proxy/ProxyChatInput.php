<?php

namespace App\Data\Proxy;

final readonly class ProxyChatInput
{
    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,mixed>|null  $tools
     */
    public function __construct(
        public array $messages,
        public ?array $tools,
        public mixed $toolChoice,
        public bool $hasToolChoice,
        public bool $stream,
        public string $inputSource,
        public string $taskType,
        public ?string $clientId,
        public ?string $projectId,
        public ?string $workflowId,
        public ?string $taskId,
        public ?string $sessionId,
        public ?string $featureKey,
        public ?string $contextId,
        public ?string $repoId,
        public ?string $branch,
        public ?string $commitSha,
        public int $toolCallCount,
    ) {}

    /** @param array<string,mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $messages = is_array($validated['messages'] ?? null) ? $validated['messages'] : [];
        $tools = is_array($validated['tools'] ?? null) ? $validated['tools'] : null;

        return new self(
            messages: $messages,
            tools: $tools,
            toolChoice: $validated['tool_choice'] ?? null,
            hasToolChoice: array_key_exists('tool_choice', $validated),
            stream: (bool) ($validated['stream'] ?? false),
            inputSource: self::stringOrDefault($validated['input_source'] ?? null, 'keyboard'),
            taskType: self::stringOrDefault($validated['task_type'] ?? null, 'chat.general'),
            clientId: self::nullableString($validated['client_id'] ?? null),
            projectId: self::nullableString($validated['project_id'] ?? null),
            workflowId: self::nullableString($validated['workflow_id'] ?? null),
            taskId: self::nullableString($validated['task_id'] ?? null),
            sessionId: self::nullableString($validated['session_id'] ?? null),
            featureKey: self::nullableString($validated['feature_key'] ?? null),
            contextId: self::nullableString($validated['context_id'] ?? null),
            repoId: self::nullableString($validated['repo_id'] ?? null),
            branch: self::nullableString($validated['branch'] ?? null),
            commitSha: self::nullableString($validated['commit_sha'] ?? null),
            toolCallCount: (int) ($validated['tool_call_count'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function providerPayload(): array
    {
        $payload = [
            'messages' => $this->messages,
            'stream' => $this->stream,
        ];
        if ($this->tools !== null) {
            $payload['tools'] = $this->tools;
        }
        if ($this->hasToolChoice) {
            $payload['tool_choice'] = $this->toolChoice;
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    public function runMeta(?int $requestUserId): array
    {
        return [
            'user_id' => $requestUserId,
            'client_id' => $this->clientId,
            'project_id' => $this->projectId,
            'project_ref_id' => null,
            'workflow_id' => $this->workflowId,
            'task_id' => $this->taskId,
            'session_id' => $this->sessionId,
            'task_type' => $this->taskType,
            'feature_key' => $this->featureKey,
            'context_id' => $this->contextId,
            'prompt_template_id' => null,
            'context_strategy_id' => 'context.memory_code_budgeted',
            // Filled only after the server resolves the exact use-case policy.
            'network_policy_id' => null,
            'repo_id' => $this->repoId,
            'branch' => $this->branch,
            'commit_sha' => $this->commitSha,
            'tool_call_count' => $this->toolCallCount,
            'retry_count' => 0,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
