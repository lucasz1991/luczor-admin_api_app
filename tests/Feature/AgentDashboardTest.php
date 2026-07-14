<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_page_lists_runs_and_audit_events(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        AgentRun::create([
            'user_id' => $admin->id, 'client_id' => 'c1', 'task_type' => 'coding.fix_bug',
            'status' => 'completed', 'model_id' => 'm/x', 'goal' => 'Fix the login bug', 'started_at' => now(),
        ]);
        AuditEvent::create([
            'event_id' => (string) Str::uuid(), 'actor_user_id' => $admin->id,
            'event_type' => 'workflow.step_completed', 'tool' => 'workflow.context', 'outcome' => 'completed', 'risk_level' => 'normal',
            'payload_hash' => hash('sha256', 'x'), 'payload' => [],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.page', 'agents'))
            ->assertOk()
            ->assertSee('Agent-Läufe')
            ->assertSee('Ereignisprotokoll')
            ->assertSee('Fix the login bug')
            ->assertSee('workflow.step_completed');
    }

    public function test_non_admin_cannot_open_the_agents_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->get(route('admin.page', 'agents'))->assertForbidden();
    }
}
