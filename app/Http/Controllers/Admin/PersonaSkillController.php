<?php

namespace App\Http\Controllers\Admin;

use App\Models\ModelUseCase;
use App\Models\Persona;
use App\Models\Setting;
use App\Models\Skill;
use App\Services\SkillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PersonaSkillController extends AdminController
{
    public function storePersona(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'prompt' => ['required', 'string', 'max:20000']]);
        Persona::updateOrCreate(['slug' => Str::slug($data['name'])], ['name' => $data['name'], 'prompt' => $data['prompt']]);

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Persönlichkeit gespeichert.');
    }

    public function storeSkill(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:prompt,workflow'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prompt' => ['nullable', 'string', 'max:20000'],
            'workflow_definition_id' => ['nullable', 'integer', 'exists:workflow_definitions,id'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            app(SkillService::class)->upsert($data + ['user_id' => $request->user()->id]);
        } catch (HttpException $e) {
            return Redirect::route('admin.page', 'optimizer')->withErrors(['skill' => $e->getMessage()]);
        }

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Skill gespeichert.');
    }

    public function toggleSkill(Request $request, Skill $skill)
    {
        $this->ensureAdmin($request);
        $skill->update(['active' => ! $skill->active]);

        return Redirect::route('admin.page', 'optimizer')->with('status', $skill->active ? 'Skill aktiviert.' : 'Skill deaktiviert.');
    }

    public function runSkill(Request $request, Skill $skill)
    {
        $this->ensureAdmin($request);
        try {
            $result = app(SkillService::class)->apply($skill);
        } catch (HttpException $e) {
            return Redirect::route('admin.page', 'optimizer')->withErrors(['skill' => $e->getMessage()]);
        }
        if (($result['kind'] ?? null) === 'workflow') {
            return Redirect::route('admin.page', ['page' => 'workflows', 'run' => $result['workflow_run_id']])
                ->with('status', 'Skill-Workflow gestartet.');
        }

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Prompt-Skill „'.$skill->name.'" bereit (siehe Prompt).');
    }

    public function deleteSkill(Request $request, Skill $skill)
    {
        $this->ensureAdmin($request);
        $skill->delete();

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Skill gelöscht.');
    }

    public function updateUseCaseReview(Request $request, ModelUseCase $modelUseCase)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'review_enabled' => ['nullable', 'boolean'],
            'review_use_case_id' => ['nullable', 'integer', 'different:'.$modelUseCase->id, 'exists:model_use_cases,id'],
        ]);
        $modelUseCase->update([
            'review_enabled' => (bool) ($data['review_enabled'] ?? false),
            'review_use_case_id' => $data['review_use_case_id'] ?? null,
        ]);

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Review-Policy gespeichert: '.$modelUseCase->name);
    }

    public function activatePersona(Request $request, Persona $persona)
    {
        $this->ensureAdmin($request);
        Persona::query()->update(['active' => false]);
        $persona->update(['active' => true]);
        Setting::putValue('active_persona', $persona->slug, ['group' => 'client', 'label' => 'Aktive KI-Persönlichkeit', 'type' => 'string']);

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Persönlichkeit aktiviert: '.$persona->name);
    }

    public function deactivatePersonas(Request $request)
    {
        $this->ensureAdmin($request);
        Persona::query()->update(['active' => false]);
        Setting::putValue('active_persona', '', ['group' => 'client', 'label' => 'Aktive KI-Persönlichkeit', 'type' => 'string']);

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Keine Persönlichkeit aktiv.');
    }
}
