<?php

namespace Tests\Feature;

use App\Data\Proxy\ProxyChatInput;
use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssistantDefaultsService;
use App\Services\AssistantProfileService;
use App\Services\InternalModelProfileService;
use App\Services\Proxy\ProxyPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalModelProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    private function profiles(): array
    {
        return [
            'standard' => ['enabled' => true, 'personality' => 'INTERNAL_PERSONALITY', 'system_prompt' => "INTERNAL_SYSTEM\nZweite Zeile"],
            'external_agents' => ['enabled' => true, 'personality' => 'INTERNAL_AGENT_PERSONALITY', 'system_prompt' => 'INTERNAL_AGENT_SYSTEM'],
        ];
    }

    public function test_profile_form_is_available_without_seeded_settings_and_reads_without_mutation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.page', 'settings'))->assertOk()
            ->assertSee('Persönlichkeit &amp; System-Prompt interner Modelle', false)
            ->assertSee('Externagentenmodus · interne Modelle')
            ->assertSee('profiles[standard][personality]', false)
            ->assertSee('profiles[external_agents][system_prompt]', false);
        $this->assertDatabaseCount('settings', 0);
        $this->assertFalse(app(InternalModelProfileService::class)->profiles()['standard']['enabled']);
    }

    public function test_admin_saves_both_profiles_atomically_without_changing_other_settings(): void
    {
        Setting::putValue('memory_inject', true, ['type' => 'bool']);
        Setting::putValue('active_persona', 'existing');
        $admin = $this->admin();
        $before = app(AssistantProfileService::class)->forUser($admin->id)['revision'];
        $this->actingAs($admin)->put(route('dashboard.internal-model-profiles.update'), ['profiles' => $this->profiles()])
            ->assertRedirect(route('admin.page', 'settings'))->assertSessionHasNoErrors();
        $this->assertSame($this->profiles(), app(InternalModelProfileService::class)->profiles());
        $this->assertTrue(Setting::getValue('memory_inject'));
        $this->assertSame('existing', Setting::getValue('active_persona'));
        $this->assertNotSame($before, app(AssistantProfileService::class)->forUser($admin->id)['revision']);
        $this->actingAs($admin)->get(route('admin.page', 'settings'))->assertOk()
            ->assertSee('INTERNAL_PERSONALITY')->assertSee('INTERNAL_AGENT_SYSTEM')
            ->assertDontSee('name="settings[internal_model_profiles]"', false);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('name="settings[internal_model_profiles]"', false);
        $this->actingAs($admin)->post(route('dashboard.settings.store'), ['settings' => ['internal_model_profiles' => 'overwrite']])->assertRedirect();
        $this->assertSame($this->profiles(), app(InternalModelProfileService::class)->profiles());
    }

    public function test_explicit_empty_and_disabled_profiles_are_preserved(): void
    {
        $profiles = $this->profiles();
        $profiles['standard'] = ['enabled' => '0', 'personality' => '', 'system_prompt' => ''];
        $profiles['external_agents'] = ['enabled' => '1', 'personality' => '', 'system_prompt' => ''];
        $this->actingAs($this->admin())->put(route('dashboard.internal-model-profiles.update'), ['profiles' => $profiles])
            ->assertSessionHasNoErrors();
        $saved = app(InternalModelProfileService::class)->profiles();
        $this->assertSame(['enabled' => false, 'personality' => '', 'system_prompt' => ''], $saved['standard']);
        $this->assertSame(['enabled' => true, 'personality' => '', 'system_prompt' => ''], $saved['external_agents']);
    }

    public function test_invalid_profiles_are_rejected_without_partial_writes(): void
    {
        app(InternalModelProfileService::class)->save($this->profiles());
        $admin = $this->admin();
        foreach ([
            ['standard.personality', str_repeat('ü', 2001)],
            ['external_agents.system_prompt', str_repeat('x', 4001)],
            ['standard.enabled', 'yes'],
            ['standard.system_prompt', ['not' => 'text']],
            ['external_agents.provider', 'external-provider'],
        ] as [$path, $invalid]) {
            $profiles = $this->profiles();
            data_set($profiles, $path, $invalid);
            $this->actingAs($admin)->putJson(route('dashboard.internal-model-profiles.update'), ['profiles' => $profiles])
                ->assertUnprocessable();
            $this->assertSame($this->profiles(), app(InternalModelProfileService::class)->profiles());
        }
        $profiles = $this->profiles();
        unset($profiles['external_agents']);
        $this->actingAs($admin)->putJson(route('dashboard.internal-model-profiles.update'), ['profiles' => $profiles])->assertUnprocessable();
        $this->assertSame($this->profiles(), app(InternalModelProfileService::class)->profiles());
    }

    public function test_form_shows_validation_errors_and_escapes_prompt_content(): void
    {
        $profiles = $this->profiles();
        $profiles['standard']['personality'] = '<script>alert("test")</script>';
        $profiles['external_agents']['system_prompt'] = str_repeat('x', 4001);
        $this->actingAs($this->admin())->from(route('admin.page', 'settings'))
            ->put(route('dashboard.internal-model-profiles.update'), ['profiles' => $profiles])
            ->assertSessionHasErrorsIn('internalModels', ['profiles.external_agents.system_prompt']);
        $this->get(route('admin.page', 'settings'))->assertOk()
            ->assertSee('darf höchstens 4000 Zeichen enthalten.')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false);
        $this->assertDatabaseMissing('settings', ['key' => InternalModelProfileService::SETTING_KEY]);
    }

    public function test_only_admins_can_manage_global_profiles(): void
    {
        $this->putJson(route('dashboard.internal-model-profiles.update'), ['profiles' => $this->profiles()])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->putJson(route('dashboard.internal-model-profiles.update'), ['profiles' => $this->profiles()])->assertForbidden();
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_profiles_are_delivered_globally_with_settings_read_and_never_injected_into_external_proxy(): void
    {
        app(AssistantDefaultsService::class)->prepare();
        app(InternalModelProfileService::class)->save($this->profiles());
        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Profile test', 'abilities' => ['settings.read'], 'active' => true]);
            $response = $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/assistant-profile')->assertOk();
            $this->assertSame($this->profiles(), $response->json('data.internal_models'));
            $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/bootstrap')->assertOk()
                ->assertJsonPath('assistant_profile.internal_models', $this->profiles());
            foreach (['chat.general', 'agent.research'] as $taskType) {
                $input = ProxyChatInput::fromValidated(['messages' => [['role' => 'user', 'content' => 'Hallo']], 'task_type' => $taskType]);
                $prepared = app(ProxyPromptBuilder::class)->prepare($input, $input->runMeta($user->id));
                $wire = json_encode($prepared->payload, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('INTERNAL_', $wire);
                $this->assertStringNotContainsString('internal_models', $wire);
                $this->assertContains(mb_substr(AssistantDefaultsService::personaPrompt(), 0, 2000), array_column($prepared->payload['messages'], 'content'));
            }
        }
    }
}
