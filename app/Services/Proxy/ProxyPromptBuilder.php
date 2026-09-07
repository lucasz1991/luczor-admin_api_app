<?php

namespace App\Services\Proxy;

use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyChatInput;
use App\Models\PromptTemplate;
use App\Services\AssistantProfileService;
use App\Services\ProviderPolicyService;

final class ProxyPromptBuilder
{
    public function __construct(private ProviderPolicyService $providerPolicy, private AssistantProfileService $assistant) {}

    /** @param array<string,mixed> $meta */
    public function prepare(ProxyChatInput $input, array $meta): PreparedProxyRequest
    {
        $payload = $input->providerPayload();
        if (str_starts_with($input->taskType, 'agent.')) {
            // Some free text endpoints reject even an empty tool contract or choice=none.
            // Nonempty/executable contracts have already been rejected by the FormRequest.
            unset($payload['tools'], $payload['tool_choice']);
        }
        $prefix = 0;

        $adminPrompt = PromptTemplate::query()
            ->where('key', 'luczor.system')
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
        if ($adminPrompt) {
            array_unshift($payload['messages'], ['role' => 'system', 'content' => $adminPrompt->body]);
            $meta['prompt_template_id'] = $adminPrompt->key.'@'.$adminPrompt->version;
            $prefix = 1;
        }

        $profile = $this->assistant->forUser(isset($meta['user_id']) ? (int) $meta['user_id'] : null);
        $persona = $profile['persona']['prompt'] ?? null;
        if ($persona) {
            array_splice($payload['messages'], $prefix, 0, [['role' => 'system', 'content' => $persona]]);
            $prefix++;
        }

        $skillMessages = array_map(fn (array $skill): array => ['role' => 'system', 'content' => $skill['prompt']], $profile['skills']);
        if ($skillMessages !== []) {
            array_splice($payload['messages'], $prefix, 0, $skillMessages);
            $prefix += count($skillMessages);
        }

        $useCase = $this->providerPolicy->useCaseFor($input->taskType);
        if ($useCase?->prompt_template_key) {
            $useCasePrompt = PromptTemplate::query()
                ->where('key', $useCase->prompt_template_key)
                ->where('status', 'active')
                ->orderByDesc('version')
                ->first();
            if ($useCasePrompt) {
                array_splice($payload['messages'], $prefix, 0, [['role' => 'system', 'content' => $useCasePrompt->body]]);
                $prefix++;
            }
        }

        $role = match ($useCase?->slug) {
            'coding', 'agent-coding' => 'coder',
            'planner', 'agent-planning' => 'planner',
            'verifier', 'vision', 'agent-review', 'agent-research' => 'analyst',
            default => 'chat',
        };
        $roleMessages = PromptTemplate::activeRolePrompts($role)
            ->map(fn (PromptTemplate $template): array => ['role' => 'system', 'content' => $template->body])
            ->all();
        if ($roleMessages !== []) {
            array_splice($payload['messages'], $prefix, 0, $roleMessages);
        }

        if (in_array($input->inputSource, ['push_to_talk', 'hands_free'], true)) {
            for ($index = count($payload['messages']) - 1; $index >= 0; $index--) {
                if (($payload['messages'][$index]['role'] ?? null) !== 'user') {
                    continue;
                }
                $payload['messages'][$index]['content'] = trim((string) ($payload['messages'][$index]['content'] ?? ''))
                    ."\n\n[Eingabemodus: Sprache; STT-Transkript kann Erkennungsfehler enthalten.]";
                break;
            }
        }

        $requiredCapabilities = [];
        if (str_starts_with($input->taskType, 'vision')) {
            $requiredCapabilities[] = 'vision';
        }
        if ($input->tools !== null && $input->tools !== []) {
            $requiredCapabilities[] = 'tools';
        }

        return new PreparedProxyRequest(
            payload: $payload,
            meta: $meta,
            taskType: $input->taskType,
            useCase: $useCase,
            requiredCapabilities: $requiredCapabilities,
        );
    }
}
