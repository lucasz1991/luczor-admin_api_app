<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowService;
use App\Services\WorkflowTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SOLL §14 P16 — catalog-hydrated starter templates. */
class WorkflowTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    public function test_every_template_definition_passes_assert_definition(): void
    {
        $svc = app(WorkflowService::class);
        $templates = WorkflowTemplateService::templates();
        $this->assertNotEmpty($templates);

        foreach ($templates as $key => $template) {
            $steps = $svc->assertDefinition($template['definition']);
            $this->assertNotEmpty($steps, $key);
        }
    }

    public function test_template_endpoint_creates_the_workflow_and_opens_the_board(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('dashboard.workflows.template'), ['template' => 'recherche'])
            ->assertRedirect();

        $definition = WorkflowDefinition::where('name', 'Recherche & Antwort')->first();
        $this->assertNotNull($definition);
        $this->assertSame('active', $definition->status);
        $this->assertNotEmpty($definition->definition['lists']);
    }

    public function test_duplicate_template_names_get_a_numeric_suffix(): void
    {
        $admin = $this->admin();
        $svc = app(WorkflowTemplateService::class);
        $svc->create($admin->id, 'freigabe');
        $second = $svc->create($admin->id, 'freigabe');

        $this->assertSame('Freigabe-Pipeline 2', $second->name);
    }

    public function test_unknown_template_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('dashboard.workflows.template'), ['template' => 'gibt-es-nicht'])
            ->assertNotFound();
    }

    public function test_seed_missing_creates_all_templates_once(): void
    {
        $admin = $this->admin();
        $svc = app(WorkflowTemplateService::class);

        $this->assertSame(count(WorkflowTemplateService::templates()), $svc->seedMissing($admin->id));
        $this->assertSame(0, $svc->seedMissing($admin->id));
    }
}
