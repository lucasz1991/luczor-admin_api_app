<?php

namespace Tests\Feature;

use App\Models\LlmRun;
use App\Models\ModelRanking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelemetryChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_telemetry_page_renders_chart_and_benchmark(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        LlmRun::create([
            'request_id' => (string) Str::uuid(), 'user_id' => $admin->id, 'client_id' => 'c1',
            'task_type' => 'chat.general', 'model_id' => 'm/x', 'provider_id' => 'openrouter',
            'status' => 'ok', 'success' => true, 'latency_ms' => 120, 'cost_total' => 0.002,
        ]);
        ModelRanking::create([
            'task_type' => 'chat.general', 'model_id' => 'm/x', 'provider_id' => 'openrouter',
            'sample_count' => 7, 'success_rate' => 0.9, 'avg_latency_ms' => 120, 'score' => 0.812,
            'cost_per_success' => 0.001,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.page', 'telemetry'))
            ->assertOk()
            ->assertSee('Modell-Benchmark')
            ->assertSee('<polyline', false)
            ->assertSee('m/x');
    }
}
