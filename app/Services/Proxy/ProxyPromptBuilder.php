<?php

namespace App\Services\Proxy;

use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyChatInput;
use App\Models\Persona;
use App\Models\PromptTemplate;
use App\Services\ProviderPolicyService;

final class ProxyPromptBuilder
{
    public function __construct(private ProviderPolicyService $providerPolicy) {}

    /** @param array<string,mixed> $meta */
    public function prepare(ProxyChatInput $input, array $meta): PreparedProxyRequest
    {
        $payload = $input->providerPayload();
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

        $persona = Persona::activePrompt();
        if ($persona) {
            array_splice($payload['messages'], $prefix, 0, [['role' => 'system', 'content' => $persona]]);
            $prefix++;
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
            'coding' => 'coder',
            'planner' => 'planner',
            'verifier', 'vision' => 'analyst',
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
