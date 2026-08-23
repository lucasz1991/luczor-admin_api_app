<?php

namespace App\Http\Controllers\Admin;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\WorkflowPlanner;
use App\Services\WorkflowService;
use App\Services\WorkflowTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkflowController extends AdminController
{
    public function storeWorkflow(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'definition_json' => ['required', 'string', 'max:200000']]);
        $definition = json_decode($data['definition_json'], true);
        if (! is_array($definition) || ! isset($definition['steps'])) {
            return Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => 'Ungültiges JSON — erwartet {"steps":[...]}.']);
        }
        try {
            app(WorkflowService::class)->assertDefinition($definition);
        } catch (HttpException $e) {
            return Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => $e->getMessage()]);
        }
        WorkflowDefinition::create(['user_id' => $request->user()->id, 'name' => $data['name'], 'version' => 1, 'status' => 'active', 'definition' => $definition]);

        return Redirect::route('admin.page', 'workflows')->with('status', 'Workflow gespeichert.');
    }

    public function startWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        if ($workflowDefinition->status !== 'active') {
            return $this->workflowError($request, 'Deaktivierter Workflow kann nicht gestartet werden.');
        }
        $sandbox = (bool) $request->boolean('sandbox');
        $svc = app(WorkflowService::class);
        $run = $svc->advance($svc->createRun($workflowDefinition, [], null, $sandbox));
        $label = $run->sandbox ? 'Sandbox-Lauf gestartet (simuliert).' : 'Workflow-Lauf gestartet.';

        return $request->wantsJson()
            ? response()->json(['message' => $label, 'run' => ['id' => $run->id, 'public_id' => $run->public_id, 'status' => $run->status, 'sandbox' => $run->sandbox]])
            : Redirect::route('admin.page', ['page' => 'workflows', 'run' => $run->id])->with('status', $label);
    }

    public function exportWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        $payload = ['format' => 'luczor-workflow', 'format_version' => 1, 'name' => $workflowDefinition->name, 'definition' => $workflowDefinition->definition];

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'workflow-'.$workflowDefinition->id.'.json',
            ['Content-Type' => 'application/json']
        );
    }

    public function importWorkflow(Request $request)
    {
        $this->ensureAdmin($request);
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $parsed = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($parsed) || ! is_array($parsed['definition'] ?? null)) {
            return Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => 'Ungültige Workflow-Datei.']);
        }
        try {
            app(WorkflowService::class)->assertDefinition($parsed['definition']);
        } catch (HttpException $e) {
            return Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => $e->getMessage()]);
        }
        WorkflowDefinition::create([
            'user_id' => $request->user()->id,
            'name' => (string) ($parsed['name'] ?? 'Importierter Workflow'),
            'version' => 1, 'status' => 'active', 'definition' => $parsed['definition'],
        ]);

        return Redirect::route('admin.page', 'workflows')->with('status', 'Workflow importiert.');
    }

    public function deleteWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        if ($workflowDefinition->is_edit_locked) {
            return Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => 'Eingebundener/gesperrter Workflow kann nicht gelöscht werden.']);
        }
        $workflowDefinition->delete();

        return Redirect::route('admin.page', 'workflows')->with('status', 'Workflow gelöscht.');
    }

    public function planWorkflow(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'goal' => ['required', 'string', 'max:500'],
            'include_research' => ['nullable', 'boolean'],
        ]);
        try {
            $definition = app(WorkflowPlanner::class)->plan(
                $request->user()->id,
                $data['goal'],
                (bool) ($data['include_research'] ?? false),
            );
        } catch (HttpException $e) {
            return Redirect::route('admin.page', 'optimizer')->withErrors(['skill' => $e->getMessage()]);
        }

        return Redirect::route('admin.page', ['page' => 'workflows', 'wf' => $definition->id])
            ->with('status', 'Workflow-Entwurf aus Ziel erzeugt.');
    }

    public function createWorkflowFromTemplate(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['template' => ['required', 'string', 'max:60']]);
        $definition = app(WorkflowTemplateService::class)->create($request->user()->id, $data['template']);

        return Redirect::route('admin.page', ['page' => 'workflows', 'wf' => $definition->id])
            ->with('status', 'Vorlage „'.$definition->name.'" angelegt.');
    }

    public function updateWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        if ($workflowDefinition->is_edit_locked) {
            return $this->workflowError($request, 'Eingebundener/gesperrter Workflow kann nicht bearbeitet werden.');
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'definition_json' => ['required', 'string', 'max:200000']]);
        $definition = json_decode($data['definition_json'], true);
        if (! is_array($definition) || ! isset($definition['steps'])) {
            return $this->workflowError($request, 'Ungültiges JSON — erwartet {"steps":[...]}.');
        }
        try {
            app(WorkflowService::class)->assertDefinition($definition);
        } catch (HttpException $e) {
            return $this->workflowError($request, $e->getMessage());
        }
        $workflowDefinition->update(['name' => $data['name'], 'definition' => $definition, 'version' => $workflowDefinition->version + 1]);

        return $request->wantsJson()
            ? response()->json(['message' => 'Workflow gespeichert (v'.$workflowDefinition->version.').', 'workflow' => ['id' => $workflowDefinition->id, 'version' => $workflowDefinition->version]])
            : Redirect::route('admin.page', ['page' => 'workflows', 'wf' => $workflowDefinition->id])->with('status', 'Workflow gespeichert (v'.$workflowDefinition->version.').');
    }

    public function duplicateWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        $copy = WorkflowDefinition::create([
            'user_id' => $request->user()->id,
            'name' => Str::limit($workflowDefinition->name, 148, '').' (Kopie)',
            'version' => 1,
            'status' => $workflowDefinition->status,
            'definition' => $workflowDefinition->definition,
        ]);

        return Redirect::route('admin.page', ['page' => 'workflows', 'wf' => $copy->id])->with('status', 'Workflow dupliziert.');
    }

    public function toggleWorkflow(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        $workflowDefinition->update(['status' => $workflowDefinition->status === 'active' ? 'disabled' : 'active']);

        return Redirect::route('admin.page', 'workflows')
            ->with('status', $workflowDefinition->status === 'active' ? 'Workflow aktiviert.' : 'Workflow deaktiviert.');
    }

    public function toggleWorkflowLock(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->ensureAdmin($request);
        $workflowDefinition->update(['is_locked' => ! $workflowDefinition->is_locked]);

        return Redirect::route('admin.page', 'workflows')
            ->with('status', $workflowDefinition->is_locked ? 'Workflow gesperrt (Edit-Lock).' : 'Workflow entsperrt.');
    }

    public function workflowRunStatus(Request $request, WorkflowRun $workflowRun)
    {
        $this->ensureAdmin($request);
        $workflowRun->load(['definition', 'steps' => fn ($q) => $q->orderBy('position'), 'artifacts' => fn ($q) => $q->latest()->limit(100)]);

        return response()->json([
            'run' => [
                'id' => $workflowRun->id,
                'public_id' => $workflowRun->public_id,
                'workflow' => $workflowRun->definition?->name,
                'workflow_definition_id' => $workflowRun->workflow_definition_id,
                'status' => $workflowRun->status,
                'started_at' => $workflowRun->started_at?->toIso8601String(),
                'finished_at' => $workflowRun->finished_at?->toIso8601String(),
                'duration_ms' => $workflowRun->duration_ms,
                'current_workflow_step_id' => $workflowRun->current_workflow_step_id,
                'output' => $workflowRun->output,
            ],
            'steps' => $workflowRun->steps->map(fn ($s) => [
                'id' => $s->id,
                'key' => $s->step_key,
                'type' => $s->type,
                'status' => $s->status,
                'position' => $s->position,
                'attempts' => $s->attempts,
                'max_attempts' => $s->max_attempts,
                'depends_on' => $s->depends_on ?? [],
                'requires_approval' => (bool) $s->requires_approval,
                'title' => is_string($s->payload['title'] ?? null) ? $s->payload['title'] : null,
                'error' => $s->error,
                'duration_ms' => $s->duration_ms,
                'started_at' => $s->started_at?->toIso8601String(),
                'finished_at' => $s->finished_at?->toIso8601String(),
                'logs' => $s->logs,
                'output' => $s->output,
            ])->values(),
            'artifacts' => $workflowRun->artifacts->map(fn ($a) => [
                'id' => $a->id,
                'step_key' => $a->step_key,
                'phase' => $a->phase,
                'artifact_type' => $a->artifact_type,
                'label' => $a->label,
                'status' => $a->status,
                'error_message' => $a->error_message,
                'metadata' => $a->metadata,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function cancelWorkflowRun(Request $request, WorkflowRun $workflowRun)
    {
        $this->ensureAdmin($request);
        app(WorkflowService::class)->cancel($workflowRun);

        return $request->wantsJson()
            ? response()->json(['message' => 'Lauf abgebrochen.', 'status' => $workflowRun->fresh()->status])
            : Redirect::route('admin.page', ['page' => 'workflows', 'run' => $workflowRun->id])->with('status', 'Lauf abgebrochen.');
    }

    public function approveWorkflowStep(Request $request, WorkflowStep $workflowStep)
    {
        $this->ensureAdmin($request);
        if ($workflowStep->status !== 'awaiting_approval') {
            return $this->workflowError($request, 'Dieser Schritt wartet nicht auf Freigabe.');
        }
        app(WorkflowService::class)->complete($workflowStep, ['approved' => true, 'approved_by' => $request->user()->id]);

        return $request->wantsJson()
            ? response()->json(['message' => 'Schritt freigegeben.'])
            : Redirect::route('admin.page', ['page' => 'workflows', 'run' => $workflowStep->workflow_run_id])->with('status', 'Schritt freigegeben.');
    }

    private function workflowError(Request $request, string $message)
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message], 422)
            : Redirect::route('admin.page', 'workflows')->withErrors(['workflow' => $message]);
    }
}
