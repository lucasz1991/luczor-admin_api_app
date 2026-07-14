<?php

namespace App\Services;

use App\Models\WorkflowDefinition;
use Illuminate\Support\Str;

/**
 * SOLL §15 P27 — Planning-Engine: turns a goal into a validated workflow draft.
 *
 * This is a deterministic scaffold planner (gather context + memory → optional
 * device research → answer → review → follow-up task). It emits a board-format
 * definition that always passes WorkflowService::assertDefinition, so the draft
 * opens cleanly in the editor. An LLM-backed planner (the 'planner' use-case)
 * can later replace planDefinition() while keeping the same output contract.
 */
class WorkflowPlanner
{
    public function __construct(private WorkflowService $workflows) {}

    /**
     * @return array<string,mixed> a board-format definition (lists + steps)
     */
    public function planDefinition(string $goal, bool $includeResearch = false): array
    {
        $goal = trim($goal);
        abort_if($goal === '', 422, 'Das Ziel darf nicht leer sein.');
        $goal = Str::limit($goal, 480, '');

        $lists = [
            ['key' => 'planung', 'name' => 'Planung'],
            ['key' => 'ergebnis', 'name' => 'Ergebnis'],
        ];
        $steps = [
            ['key' => 'kontext', 'type' => 'context', 'payload' => ['title' => 'Kontext zum Ziel', 'list' => 'planung', 'query' => $goal]],
            ['key' => 'erinnerungen', 'type' => 'memory.recall', 'depends_on' => ['kontext'], 'payload' => ['title' => 'Relevante Erinnerungen', 'list' => 'planung', 'query' => $goal, 'top_k' => 6]],
        ];
        $answerDeps = ['erinnerungen'];

        if ($includeResearch) {
            $lists[] = ['key' => 'recherche', 'name' => 'Recherche'];
            $steps[] = ['key' => 'recherche', 'type' => 'api.call', 'depends_on' => ['erinnerungen'], 'payload' => [
                'title' => 'Externe Recherche', 'list' => 'recherche',
                'method' => 'GET', 'url' => 'https://duckduckgo.com/html/?q='.rawurlencode($goal),
            ], 'routes' => ['failed' => ['type' => 'step', 'step_key' => 'erinnerungen', 'max_iterations' => 2]]];
            $answerDeps[] = 'recherche';
        }

        $steps[] = ['key' => 'antwort', 'type' => 'llm', 'depends_on' => $answerDeps, 'payload' => ['title' => 'Antwort/Plan erstellen', 'list' => 'ergebnis'], 'routes' => ['failed' => ['type' => 'fail']]];
        $steps[] = ['key' => 'review', 'type' => 'review', 'depends_on' => ['antwort'], 'payload' => ['title' => 'Ergebnis prüfen', 'list' => 'ergebnis']];
        $steps[] = ['key' => 'folgeaufgabe', 'type' => 'task.create', 'depends_on' => ['review'], 'payload' => ['title' => 'Folgeaufgabe anlegen', 'list' => 'ergebnis', 'title_task' => Str::limit('Ziel umsetzen: '.$goal, 180, '')], 'routes' => ['success' => ['type' => 'end']]];

        $definition = ['lists' => $lists, 'steps' => $steps, 'meta' => ['planned_from_goal' => $goal]];
        // Fail loudly here rather than at save time if the scaffold ever drifts.
        $this->workflows->assertDefinition($definition);

        return $definition;
    }

    /** Plan a goal into a fresh, editable workflow definition for the user. */
    public function plan(int $userId, string $goal, bool $includeResearch = false): WorkflowDefinition
    {
        $definition = $this->planDefinition($goal, $includeResearch);
        $name = Str::limit('Plan: '.trim($goal), 140, '');
        $suffix = 2;
        while (WorkflowDefinition::where('name', $name)->exists()) {
            $name = Str::limit('Plan: '.trim($goal), 132, '').' '.$suffix++;
        }

        return WorkflowDefinition::create([
            'user_id' => $userId,
            'name' => $name,
            'version' => 1,
            'status' => 'active',
            'definition' => $definition,
        ]);
    }
}
