<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_register_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Einloggen');
        $this->get('/register')->assertOk()->assertSee('Registrieren');
    }

    public function test_verified_user_sees_terminal_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('luczor terminal')
            ->assertDontSee('Modell-Fallbacks pro Use-Case');
    }

    public function test_admin_sees_system_dashboard(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Luczor Admin API')
            ->assertSee('Modell-Fallbacks pro Use-Case');
    }

    public function test_user_cannot_store_model_profile(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/dashboard/model-profiles', [
                'name' => 'Blocked',
                'provider' => 'openrouter',
                'model_id' => 'openai/gpt-5.1',
                'temperature' => 0.2,
                'max_tokens' => 1200,
            ])
            ->assertForbidden();
    }
}
