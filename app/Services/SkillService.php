<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\WorkflowRun;
use Illuminate\Support\Str;

/**
 * SOLL §15 P27 — manages reusable skill bundles and applies them: a prompt skill
 * yields its instruction fragment (for prompt assembly), a workflow skill starts
 * its referenced workflow. Skills are also mirrored into skill-scoped memory so
 * the recall path can surface them.
 */
class SkillService
{
    public function __construct(private WorkflowService $workflows, private LuczorMemoryService $memory) {}

    /**
     * Create/update a skill from admin input (validated upstream).
     * @param array<string,mixed> $data
     */
    public function upsert(array $data): Skill
    {
        $kind = in_array($data['kind'] ?? 'prompt', Skill::KINDS, true) ? $data['kind'] : 'prompt';
        abort_if($kind === 'prompt' && trim((string) ($data['prompt'] ?? '')) === '', 422, 'Ein Prompt-Skill braucht einen Prompt-Text.');
        abort_if($kind === 'workflow' && empty($data['workflow_definition_id']), 422, 'Ein Workflow-Skill braucht eine Workflow-Definition.');

        $skill = Skill::updateOrCreate(
            ['slug' => Str::slug($data['name'])],
            [
                'user_id' => $data['user_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'kind' => $kind,
                'prompt' => $kind === 'prompt' ? $data['prompt'] : null,
                'workflow_definition_id' => $kind === 'workflow' ? (int) $data['workflow_definition_id'] : null,
                'tags' => $this->normalizeTags($data['tags'] ?? null),
                'active' => (bool) ($data['active'] ?? true),
            ]
        );

        // Mirror into skill-scoped memory so recall can find reusable skills.
        if (isset($data['user_id'])) {
            $this->memory->remember([
                'user_id' => $data['user_id'],
                'external_id' => 'skill:'.$skill->slug,
                'scope' => 'skill',
                'type' => 'skill',
                'visibility' => 'private',
                'importance' => 0.7,
                'content' => trim($skill->name."\n".($skill->description ?? '')."\n".($skill->prompt ?? '')),
                'meta' => ['skill_id' => $skill->id, 'kind' => $skill->kind],
            ]);
        }

        return $skill;
    }

    /**
     * Apply a skill. Prompt skills return their fragment; workflow skills start a
     * run and return its identity.
     *
     * @return array<string,mixed>
     */
    public function apply(Skill $skill): array
    {
        abort_unless($skill->active, 409, 'Dieser Skill ist deaktiviert.');
        $skill->increment('use_count');
        $skill->update(['last_used_at' => now()]);

        if ($skill->kind === 'workflow') {
            $definition = $skill->workflowDefinition;
            abort_unless($definition !== null, 422, 'Der Workflow dieses Skills existiert nicht mehr.');
            abort_unless($definition->status === 'active', 409, 'Der Workflow dieses Skills ist deaktiviert.');
            $run = $this->workflows->advance($this->workflows->createRun($definition));

            return ['kind' => 'workflow', 'workflow_run_id' => $run->id, 'workflow_run' => $run->public_id, 'status' => $run->status];
        }

        return ['kind' => 'prompt', 'prompt' => (string) $skill->prompt];
    }

    /** Active prompt-skill fragments for a user, for injection into assembly. */
    public function promptFragments(?int $userId): array
    {
        return Skill::active()
            ->where('kind', 'prompt')
            ->when($userId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', $userId)))
            ->orderByDesc('use_count')
            ->pluck('prompt')
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeTags(mixed $tags): ?array
    {
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }

        return is_array($tags) && $tags !== [] ? array_values($tags) : null;
    }
}
