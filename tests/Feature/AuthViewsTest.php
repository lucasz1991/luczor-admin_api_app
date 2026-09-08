<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_register_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Dein Luczor wartet.');
        $this->get('/register')->assertOk()->assertSee('Mit Luczor starten.');
    }

    public function test_verified_user_sees_own_workspace_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-role="customer"', false)
            ->assertSee('Mein Luczor')
            ->assertSee('href="'.route('account.workspace').'"', false)
            ->assertSee('data-ui-tabs="member-dashboard"', false)
            ->assertDontSee('cloud queue bereit')
            ->assertSee('data-luczor-topbar', false)
            ->assertSee('id="app-sidebar"', false)
            ->assertSee('aria-label="Navigation öffnen oder schließen"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Meine Geräte')
            ->assertDontSee('data-admin-command-center', false)
            ->assertDontSee('data-dashboard-action="advanced-configuration"', false)
            ->assertDontSee('Provider & Preise')
            ->assertDontSee('Modell-Fallbacks pro Use-Case');
    }

    public function test_dashboard_navigation_anchors_target_existing_tab_panels(): void
    {
        foreach (['user', 'admin'] as $role) {
            $this->flushSession();
            $user = User::factory()->create(['email_verified_at' => now(), 'role' => $role]);
            $response = $this->actingAs($user)->get('/dashboard')->assertOk();
            $content = $response->getContent();
            preg_match_all('/href="'.preg_quote(route('dashboard'), '/').'#([^"\s]+)"/', $content, $matches);

            $this->assertNotEmpty($matches[1], 'The dashboard navigation must target its overview tab.');
            foreach ($matches[1] as $anchor) {
                $response->assertSee('id="'.$anchor.'"', false);
            }
            if ($role === 'user') {
                $response->assertDontSee('GitHub / Cloud')
                    ->assertSee('href="'.route('dashboard').'#member-dashboard-panel-projects"', false)
                    ->assertSee('href="'.route('dashboard').'#member-dashboard-panel-connect"', false);
            }
        }
    }

    public function test_member_dashboard_lists_only_owned_devices(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        Device::create(['user_id' => $member->id, 'device_id' => 'member-owned-device', 'name' => 'Eigener Arbeitsrechner', 'status' => 'online', 'last_seen_at' => now()]);
        Device::create(['user_id' => $other->id, 'device_id' => 'other-private-device', 'name' => 'Fremder Privatrechner', 'status' => 'online', 'last_seen_at' => now()]);

        $this->actingAs($member)->get('/dashboard')
            ->assertOk()
            ->assertSee('Eigener Arbeitsrechner')
            ->assertDontSee('Fremder Privatrechner')
            ->assertDontSee('other-private-device')
            ->assertSee('Kürzlich aktiv');
    }

    public function test_admin_sees_system_dashboard(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-role="admin"', false)
            ->assertSee('data-admin-command-center', false)
            ->assertSee('data-dashboard-metric="devices-online"', false)
            ->assertSee('data-dashboard-metric="success-rate"', false)
            ->assertSee('data-dashboard-action="advanced-configuration"', false)
            ->assertSee('Luczor Admin Control')
            ->assertSee('data-luczor-sidebar', false)
            ->assertSee('aria-label="Navigation öffnen oder schließen"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Provider & Preise')
            ->assertSee('Server Settings')
            ->assertSee('Modell-Fallbacks pro Use-Case');

        foreach ([
            'dashboard.api-keys.store',
            'dashboard.provider-prices.store',
            'dashboard.model-use-cases.store',
            'dashboard.context-strategies.store',
            'dashboard.network-policies.store',
            'dashboard.llm-experiments.store',
            'dashboard.agent-profiles.store',
        ] as $routeName) {
            $response->assertSee('action="'.route($routeName).'"', false);
        }
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

    public function test_admin_overview_distinguishes_usable_keys_and_running_attempts(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        ApiKey::mint([
            'user_id' => $admin->id,
            'name' => 'Usable',
            'active' => true,
            'abilities' => ['all'],
        ]);
        ApiKey::mint([
            'user_id' => $admin->id,
            'name' => 'Expired',
            'active' => true,
            'expires_at' => now()->subMinute(),
            'abilities' => ['all'],
        ]);

        $run = LlmRun::create([
            'request_id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'client_id' => 'dashboard-test',
            'task_type' => 'chat.general',
            'model_id' => 'provider/model',
            'provider_id' => 'provider',
            'status' => 'running',
            'success' => false,
        ]);
        LlmAttempt::create([
            'llm_run_id' => $run->id,
            'attempt_no' => 1,
            'provider_id' => 'provider',
            'model_id' => 'provider/model',
            'status' => 'started',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('1 aktive Keys')
            ->assertSee('data-attempt-status="started"', false)
            ->assertSee('ui-badge--info', false);
    }

    public function test_admin_validation_error_opens_only_the_relevant_tool_group(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->from('/dashboard')
            ->post(route('dashboard.provider-credentials.store'), [
                '_dashboard_tool_group' => 'access',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['provider', 'label', 'api_key']);

        $content = $this->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("initial: 'configuration'", $content);
        $this->assertStringContainsString("initial: 'access'", $content);
        $this->assertStringContainsString('data-ui-tabs="dashboard-configuration"', $content);
        $this->assertStringContainsString('data-ui-tab-panel="access"', $content);
        $this->assertStringContainsString('action="'.route('dashboard.provider-credentials.store').'"', $content);

    }
}
