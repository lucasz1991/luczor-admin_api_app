<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonaTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_prompt_reflects_the_selected_persona(): void
    {
        Persona::create(['slug' => 'knapp', 'name' => 'Knapp', 'prompt' => 'Antworte knapp.', 'active' => true]);
        Setting::putValue('active_persona', 'knapp', ['group' => 'client', 'type' => 'string']);
        $this->assertSame('Antworte knapp.', Persona::activePrompt());

        Setting::putValue('active_persona', '', ['group' => 'client', 'type' => 'string']);
        $this->assertNull(Persona::activePrompt());
    }

    public function test_admin_creates_and_activates_a_persona(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('dashboard.personas.store'), ['name' => 'Sachlich Knapp', 'prompt' => 'Sei sachlich.'])
            ->assertRedirect();
        $persona = Persona::where('slug', 'sachlich-knapp')->firstOrFail();

        $this->actingAs($admin)->post(route('dashboard.personas.activate', $persona))->assertRedirect();
        $this->assertTrue($persona->fresh()->active);
        $this->assertSame('sachlich-knapp', Setting::getValue('active_persona'));

        $this->actingAs($admin)->post(route('dashboard.personas.deactivate'))->assertRedirect();
        $this->assertSame('', Setting::getValue('active_persona'));
        $this->assertFalse($persona->fresh()->active);
    }

    public function test_non_admin_cannot_manage_personas(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->post(route('dashboard.personas.store'), ['name' => 'x', 'prompt' => 'y'])->assertForbidden();
    }
}
