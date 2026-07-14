<?php

namespace App\Services;

use App\Models\WorkflowDefinition;

/**
 * SOLL §14 P16 — catalog-hydrated starter workflows (adapted from AUF's
 * WorkflowTemplateService). Definitions use the board format (lists + steps
 * with payload.list/title) so they open cleanly in the editor.
 */
class WorkflowTemplateService
{
    /** @return array<string,array{name:string,description:string,definition:array<string,mixed>}> */
    public static function templates(): array
    {
        return [
            'recherche' => [
                'name' => 'Recherche & Antwort',
                'description' => 'Kontext + Erinnerungen sammeln, LLM antworten lassen, Ergebnis reviewen.',
                'definition' => [
                    'lists' => [
                        ['key' => 'vorbereitung', 'name' => 'Vorbereitung'],
                        ['key' => 'antwort', 'name' => 'Antwort'],
                    ],
                    'steps' => [
                        ['key' => 'kontext', 'type' => 'context', 'payload' => ['title' => 'Kontext sammeln', 'list' => 'vorbereitung', 'query' => '']],
                        ['key' => 'erinnerungen', 'type' => 'memory.recall', 'depends_on' => ['kontext'], 'payload' => ['title' => 'Erinnerungen abrufen', 'list' => 'vorbereitung', 'query' => '', 'top_k' => 6]],
                        ['key' => 'antwort', 'type' => 'llm', 'depends_on' => ['erinnerungen'], 'payload' => ['title' => 'Antwort erzeugen', 'list' => 'antwort'], 'routes' => ['failed' => ['type' => 'fail']]],
                        ['key' => 'review', 'type' => 'review', 'depends_on' => ['antwort'], 'payload' => ['title' => 'Ergebnis prüfen', 'list' => 'antwort'], 'routes' => ['success' => ['type' => 'end']]],
                    ],
                ],
            ],
            'freigabe' => [
                'name' => 'Freigabe-Pipeline',
                'description' => 'Manuelle Vorbereitung, Freigabe-Gate, dann Umsetzung mit Folgeaufgabe.',
                'definition' => [
                    'lists' => [
                        ['key' => 'pruefung', 'name' => 'Prüfung'],
                        ['key' => 'umsetzung', 'name' => 'Umsetzung'],
                    ],
                    'steps' => [
                        ['key' => 'vorbereiten', 'type' => 'manual', 'payload' => ['title' => 'Auftrag beschreiben', 'list' => 'pruefung']],
                        ['key' => 'freigabe', 'type' => 'approval', 'depends_on' => ['vorbereiten'], 'payload' => ['title' => 'Freigabe einholen', 'list' => 'pruefung']],
                        ['key' => 'umsetzen', 'type' => 'llm', 'depends_on' => ['freigabe'], 'payload' => ['title' => 'Umsetzen', 'list' => 'umsetzung'], 'routes' => ['failed' => ['type' => 'fail']]],
                        ['key' => 'folgeaufgabe', 'type' => 'task.create', 'depends_on' => ['umsetzen'], 'payload' => ['title' => 'Ergebnis nachverfolgen', 'list' => 'umsetzung', 'priority' => 'normal'], 'routes' => ['success' => ['type' => 'end']]],
                    ],
                ],
            ],
            'geraete-automation' => [
                'name' => 'Geräte-Automation (Browser)',
                'description' => 'Seite auf dem Gerät öffnen und auslesen, Ergebnis als Erinnerung sichern — mit Wiederholungs-Route.',
                'definition' => [
                    'lists' => [
                        ['key' => 'geraet', 'name' => 'Gerät'],
                        ['key' => 'nachbereitung', 'name' => 'Nachbereitung'],
                    ],
                    'steps' => [
                        ['key' => 'oeffnen', 'type' => 'browser.open_url', 'payload' => ['title' => 'Seite öffnen', 'list' => 'geraet', 'url' => 'https://example.org']],
                        ['key' => 'lesen', 'type' => 'browser.read', 'depends_on' => ['oeffnen'], 'payload' => ['title' => 'Seite auslesen', 'list' => 'geraet', 'selector' => 'body'], 'routes' => ['failed' => ['type' => 'step', 'step_key' => 'oeffnen', 'max_iterations' => 2]]],
                        ['key' => 'merken', 'type' => 'memory.remember', 'depends_on' => ['lesen'], 'payload' => ['title' => 'Ergebnis merken', 'list' => 'nachbereitung', 'content' => 'Ergebnis der Browser-Automation.'], 'routes' => ['success' => ['type' => 'end']]],
                    ],
                ],
            ],
        ];
    }

    /** Create one template as a fresh definition for the user (validated). */
    public function create(int $userId, string $key): WorkflowDefinition
    {
        $template = self::templates()[$key] ?? null;
        abort_unless($template !== null, 404, 'Unbekannte Workflow-Vorlage.');
        app(WorkflowService::class)->assertDefinition($template['definition']);

        $name = $template['name'];
        $suffix = 2;
        while (WorkflowDefinition::where('name', $name)->exists()) {
            $name = $template['name'].' '.$suffix++;
        }

        return WorkflowDefinition::create([
            'user_id' => $userId,
            'name' => $name,
            'version' => 1,
            'status' => 'active',
            'definition' => $template['definition'],
        ]);
    }

    /** Seed every template whose base name does not exist yet. */
    public function seedMissing(int $userId): int
    {
        $created = 0;
        foreach (self::templates() as $key => $template) {
            if (! WorkflowDefinition::where('name', $template['name'])->exists()) {
                $this->create($userId, $key);
                $created++;
            }
        }

        return $created;
    }
}
