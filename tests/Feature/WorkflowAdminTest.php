<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    public function test_admin_page_lists_the_task_library_and_workflows(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.page', 'workflows'))
            ->assertOk()
            ->assertSee('Freigegebene Task-Bibliothek')
            ->assertSee('agent.dispatch');
    }

    public function test_admin_creates_a_workflow_from_json(): void
    {
        $json = json_encode(['steps' => [['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'end']]]]]);

        $this->actingAs($this->admin())
            ->post(route('dashboard.workflows.store'), ['name' => 'Demo', 'definition_json' => $json])
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_definitions', ['name' => 'Demo', 'status' => 'active']);
    }

    public function test_invalid_definition_is_rejected_with_an_error(): void
    {
        $json = json_encode(['steps' => [['key' => 'a', 'type' => 'shell.exec']]]); // not in the catalog

        $this->actingAs($this->admin())
            ->post(route('dashboard.workflows.store'), ['name' => 'Bad', 'definition_json' => $json])
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseMissing('workflow_definitions', ['name' => 'Bad']);
    }

    public function test_embedded_workflow_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $child = WorkflowDefinition::create(['user_id' => $admin->id, 'name' => 'Child', 'version' => 1, 'status' => 'active', 'definition' => ['steps' => [['key' => 'c', 'type' => 'manual']]]]);
        WorkflowDefinition::create(['user_id' => $admin->id, 'name' => 'Parent', 'version' => 1, 'status' => 'active', 'definition' => ['steps' => [['key' => 'w', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]]]]);

        $this->actingAs($admin)
            ->delete(route('dashboard.workflows.destroy', $child))
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseHas('workflow_definitions', ['id' => $child->id]);
    }

    public function test_non_admin_cannot_open_the_workflow_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->get(route('admin.page', 'workflows'))->assertForbidden();
    }
}
