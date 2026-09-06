<?php

namespace Tests\Feature;

use App\Data\Proxy\ProxyChatInput;
use App\Models\ApiKey;
use App\Models\Persona;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\User;
use App\Services\AssistantDefaultsService;
use App\Services\AssistantProfileService;
use App\Services\Proxy\ProxyPromptBuilder;
use App\Services\SkillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_idempotent_and_select_only_a_pristine_persona_catalog(): void
    {
        $service = app(AssistantDefaultsService::class);
        $this->assertSame(['personas_created' => 1, 'skills_created' => 3, 'persona_selected' => true], $service->prepare());
        $this->assertSame(['personas_created' => 0, 'skills_created' => 0, 'persona_selected' => false], $service->prepare());
        $this->assertSame(1, Persona::count());
        $this->assertSame(3, Skill::count());
        $this->assertStringContainsString('deutschsprachiger Assistent', Persona::activePrompt());
        $this->assertDatabaseCount('memory_links', 0);
    }

    public function test_defaults_preserve_custom_texts_disabled_skills_and_explicit_no_persona(): void
    {
        app(AssistantDefaultsService::class)->prepare();
        Persona::first()->update(['name' => 'Mein Name', 'prompt' => 'Mein Entwurf', 'active' => false]);
        Skill::first()->update(['prompt' => 'Meine Regel', 'active' => false]);
        Setting::putValue('active_persona', '');

        app(AssistantDefaultsService::class)->prepare();

        $this->assertDatabaseHas('personas', ['name' => 'Mein Name', 'prompt' => 'Mein Entwurf', 'active' => false]);
        $this->assertDatabaseHas('skills', ['prompt' => 'Meine Regel', 'active' => false]);
        $this->assertSame('', Setting::getValue('active_persona'));
        $this->assertNull(Persona::activePrompt());
    }

    public function test_existing_personality_is_not_replaced_or_implicitly_selected(): void
    {
        Persona::create(['slug' => 'custom', 'name' => 'Custom', 'prompt' => 'Eigene Stimme', 'active' => true]);
        Setting::putValue('active_persona', 'custom');

        app(AssistantDefaultsService::class)->prepare();

        $this->assertSame('custom', Setting::getValue('active_persona'));
        $this->assertFalse(Persona::where('slug', 'luczor-klar-freundlich')->first()->active);
        $this->assertSame('Eigene Stimme', Persona::activePrompt());
    }

    public function test_profile_api_exposes_only_selected_persona_and_authorized_active_prompt_skills(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        app(AssistantDefaultsService::class)->prepare();
        Skill::create(['slug' => 'own', 'name' => 'Own', 'user_id' => $user->id, 'kind' => 'prompt', 'prompt' => 'OWN', 'active' => true]);
        Skill::create(['slug' => 'foreign', 'name' => 'Foreign', 'user_id' => $other->id, 'kind' => 'prompt', 'prompt' => 'FOREIGN', 'active' => true]);
        Skill::create(['slug' => 'disabled', 'name' => 'Disabled', 'kind' => 'prompt', 'prompt' => 'DISABLED', 'active' => false]);
        Skill::create(['slug' => 'flow', 'name' => 'Flow', 'kind' => 'workflow', 'prompt' => 'WORKFLOW', 'active' => true]);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Profile', 'abilities' => ['settings.read'], 'active' => true]);

        $response = $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/assistant-profile')
            ->assertOk()->assertJsonPath('data.persona.slug', 'luczor-klar-freundlich')
            ->assertJsonCount(4, 'data.skills');
        $this->assertContains('OWN', array_column($response->json('data.skills'), 'prompt'));
        $this->assertNotContains('FOREIGN', array_column($response->json('data.skills'), 'prompt'));
        $own = collect($response->json('data.skills'))->firstWhere('slug', 'own');
        $this->assertSame('', $own['description']);
        $this->assertSame([], $own['tags']);
        $this->assertSame($response->json('data.revision'), app(AssistantProfileService::class)->forUser($user->id)['revision']);

        $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/bootstrap')
            ->assertOk()->assertJsonPath('assistant_profile.revision', $response->json('data.revision'));
    }

    public function test_profile_requires_settings_read_and_never_leaks_owned_skills_without_an_actor(): void
    {
        $user = User::factory()->create();
        Skill::create(['slug' => 'private', 'name' => 'Private', 'user_id' => $user->id, 'kind' => 'prompt', 'prompt' => 'PRIVATE', 'active' => true]);
        $this->assertSame([], app(AssistantProfileService::class)->forUser(null)['skills']);
        $this->assertSame([], app(SkillService::class)->promptFragments(null));
        $this->getJson('/api/v1/assistant-profile')->assertUnauthorized();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'No settings', 'abilities' => ['brain.read'], 'active' => true]);
        $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/assistant-profile')->assertForbidden();
    }

    public function test_server_proxy_injects_persona_and_owned_skills_before_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        app(AssistantDefaultsService::class)->prepare();
        Skill::create(['slug' => 'own', 'name' => 'Own', 'user_id' => $user->id, 'kind' => 'prompt', 'prompt' => 'OWN', 'active' => true]);
        Skill::create(['slug' => 'foreign', 'name' => 'Foreign', 'user_id' => $other->id, 'kind' => 'prompt', 'prompt' => 'FOREIGN', 'active' => true]);
        $input = ProxyChatInput::fromValidated(['messages' => [['role' => 'user', 'content' => 'Hallo']], 'task_type' => 'chat.general']);
        $prepared = app(ProxyPromptBuilder::class)->prepare($input, $input->runMeta($user->id));
        $contents = array_column($prepared->payload['messages'], 'content');

        $this->assertSame(AssistantDefaultsService::personaPrompt(), $contents[0]);
        $this->assertContains('OWN', $contents);
        $this->assertNotContains('FOREIGN', $contents);
        $this->assertSame('Hallo', end($contents));
    }

    public function test_admin_can_edit_existing_records_without_changing_identity_scope_or_activation(): void
    {
        app(AssistantDefaultsService::class)->prepare();
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $persona = Persona::first();
        $skill = Skill::first();
        $skill->update(['active' => false]);
        $before = app(AssistantProfileService::class)->forUser($admin->id)['revision'];

        $this->actingAs($admin)->patch(route('dashboard.personas.update', $persona), ['name' => 'Umbenannt', 'prompt' => 'Neuer Ton'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('dashboard.skills.update', $skill), ['name' => 'Neuer Skillname', 'prompt' => 'Neue Regel', 'tags' => 'php, lokal'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($persona->slug, $persona->fresh()->slug);
        $this->assertSame('Neuer Ton', Persona::activePrompt());
        $this->assertSame($skill->slug, $skill->fresh()->slug);
        $this->assertNull($skill->fresh()->user_id);
        $this->assertFalse($skill->fresh()->active);
        $this->assertSame(3, Skill::count());
        $this->assertSame(['php', 'lokal'], $skill->fresh()->tags);
        $this->assertNotSame($before, app(AssistantProfileService::class)->forUser($admin->id)['revision']);
        $this->actingAs($admin)->get(route('admin.page', 'optimizer'))->assertOk()
            ->assertSee('Neuer Ton')->assertSee('Neue Regel')->assertSee('Grundentwurf ergänzen');
    }

    public function test_non_admin_cannot_edit_or_prepare_global_defaults(): void
    {
        app(AssistantDefaultsService::class)->prepare();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->post(route('dashboard.assistant-defaults.store'))->assertForbidden();
        $this->actingAs($user)->patch(route('dashboard.personas.update', Persona::first()), ['name' => 'x', 'prompt' => 'y'])->assertForbidden();
        $this->actingAs($user)->patch(route('dashboard.skills.update', Skill::first()), ['name' => 'x', 'prompt' => 'y'])->assertForbidden();
    }

    public function test_local_seed_command_is_repeatable_and_refuses_production(): void
    {
        $this->artisan('luczor:assistant-defaults')->expectsOutputToContain('1 Persönlichkeit(en), 3 Skill(s)')->assertSuccessful();
        $this->artisan('luczor:assistant-defaults')->expectsOutputToContain('0 Persönlichkeit(en), 0 Skill(s)')->assertSuccessful();
        $this->app->instance('env', 'production');
        $this->artisan('luczor:assistant-defaults')->expectsOutputToContain('Nur APP_ENV=local|testing')->assertFailed();
        $this->assertSame(3, Skill::count());
    }
}
