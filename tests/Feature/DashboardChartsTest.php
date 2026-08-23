<?php

namespace Tests\Feature;

use App\Models\LlmRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_renders_inline_svg_charts(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        // A couple of runs so a bar has non-zero height.
        foreach (['openrouter', 'openai'] as $p) {
            LlmRun::create([
                'request_id' => (string) Str::uuid(),
                'user_id' => $admin->id, 'client_id' => 'c1', 'task_type' => 'chat.general',
                'model_id' => 'm/'.$p, 'provider_id' => $p, 'status' => 'ok', 'success' => true,
                'latency_ms' => 100, 'input_tokens' => 10, 'output_tokens' => 20, 'cost_total' => 0.001,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.page', 'overview'))
            ->assertOk()
            ->assertSee('Läufe / Tag')
            ->assertSee('Provider (30 T)')
            ->assertSee('<svg', false)
            ->assertSee('<rect', false);
    }
}
