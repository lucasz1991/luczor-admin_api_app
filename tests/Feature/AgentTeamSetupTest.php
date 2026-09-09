<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\ModelUseCase;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\AgentTeamDefaultsService;
use App\Services\AgentTeamPolicyService;
use App\Services\AgentTeamSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentTeamSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(10, 0));
        Http::preventStrayRequests();
    }

    public function test_admin_inspection_explains_missing_routes_and_credentials_without_writing_or_calling_providers(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)->get(route('admin.page', 'agents'))->assertOk()
            ->assertSee('0 von 3 externen Rollen konfiguriert')
            ->assertSee('Kein aktiver OpenRouter-Zugang mit Chat Completions vorhanden.')
            ->assertSee('Der Team-Schalter allein legt noch keine Rollenmodelle an.');
        $this->assertDatabaseCount('agent_profiles', 0);
        $this->assertDatabaseCount('model_use_cases', 0);
        $this->assertDatabaseCount('llm_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_preparing_a_planning_only_catalog_reports_all_three_still_missing_specialists(): void
    {
        $catalog = config('agent_teams');
        $catalog['models'] = array_values(array_filter($catalog['models'], fn (array $model): bool => $model['roles'] === ['planning']));
        AgentProfile::create(['key' => AgentTeamPolicyService::CATALOG_KEY, 'name' => 'Partial research', 'type' => 'model_catalog', 'status' => 'draft', 'config' => $catalog]);
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $credential = $this->credential();
        $response = $this->actingAs($admin)->post(route('dashboard.agent-teams.prepare'), ['provider_credential_id' => $credential->id]);
        $response->assertRedirect(route('admin.page', 'agents'))
            ->assertSessionHas('agent_team_setup_result.tone', 'warning')
            ->assertSessionHas('agent_team_setup_result.message', fn (string $message): bool => str_contains($message, 'Einrichtung noch offen für: Recherche, Codeentwurf, Prüfung.'))
            ->assertSessionMissing('status');
        $this->assertDatabaseCount('model_use_cases', 1);
        $this->assertDatabaseMissing('model_use_cases', ['slug' => 'agent-research']);
        $this->assertDatabaseMissing('model_use_cases', ['slug' => 'agent-coding']);
        $this->assertDatabaseMissing('model_use_cases', ['slug' => 'agent-review']);
        $this->get(route('admin.page', 'agents'))->assertOk()
            ->assertSee('Für Rollen mit 0 Kandidaten')
            ->assertSee('Einrichtung noch offen für: Recherche, Codeentwurf, Prüfung.')
            ->assertDontSee('secret-test-provider-key');
        Http::assertNothingSent();
    }

    public function test_saving_enabled_alone_reports_incomplete_setup_and_does_not_create_provider_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)->put(route('dashboard.agent-teams.update'), ['enabled' => true, 'default_preset' => 'free', 'max_parallel' => 2])
            ->assertRedirect()
            ->assertSessionHas('agent_team_setup_result.tone', 'warning');
        $this->assertDatabaseCount('model_use_cases', 0);
        $this->assertDatabaseCount('model_profiles', 0);
        $this->assertSame(['Recherche', 'Codeentwurf', 'Prüfung'], app(AgentTeamSetupService::class)->inspect()['unavailable_labels']);
    }

    public function test_complete_free_team_reports_configuration_without_claiming_provider_execution(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $credential = $this->credential();
        $this->actingAs($admin)->post(route('dashboard.agent-teams.prepare'), ['provider_credential_id' => $credential->id])
            ->assertRedirect()
            ->assertSessionHas('agent_team_setup_result.tone', 'success')
            ->assertSessionHas('agent_team_setup_result.message', fn (string $message): bool => str_contains($message, 'Eine echte Provideranfrage wurde dabei nicht ausgeführt.'));
        ModelUseCase::where('slug', 'agent-planning')->update(['active' => false]);
        $setup = app(AgentTeamSetupService::class)->inspect();
        $this->assertSame(3, $setup['configured_count']);
        $this->assertSame([], $setup['unavailable_labels']);
        $this->assertDatabaseCount('llm_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_policy_stays_disabled_when_adding_models(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $credential = $this->credential();
        app(AgentTeamDefaultsService::class)->prepare($credential->id);
        AgentProfile::where('key', AgentTeamPolicyService::POLICY_KEY)->update(['status' => 'disabled']);
        $this->actingAs($admin)->post(route('dashboard.agent-teams.prepare'), ['provider_credential_id' => $credential->id])
            ->assertRedirect()
            ->assertSessionHas('agent_team_setup_result.tone', 'info')
            ->assertSessionHas('agent_team_setup_result.message', fn (string $message): bool => str_contains($message, 'Externe Teams bleiben deaktiviert.'));
        $this->assertFalse(app(AgentTeamPolicyService::class)->payload()['enabled']);
        Http::assertNothingSent();
    }

    public function test_inspection_marks_stale_catalog_and_does_not_count_incompatible_credentials(): void
    {
        $this->credential()->update(['request_format' => 'responses']);
        $this->travel(20)->days();
        $setup = app(AgentTeamSetupService::class)->inspect();
        $this->assertFalse($setup['catalog_current']);
        $this->assertSame(0, $setup['credential_count']);
        $this->assertGreaterThan(0, $setup['roles']['research']['catalog_candidates']);
        $this->assertFalse($setup['roles']['research']['ready']);
    }

    #[DataProvider('catalogDates')]
    public function test_catalog_current_requires_a_nonempty_valid_date_in_the_current_window(mixed $date, bool $expected): void
    {
        $catalog = config('agent_teams');
        $catalog['researched_at'] = $date;
        AgentProfile::create(['key' => AgentTeamPolicyService::CATALOG_KEY, 'name' => 'Research', 'type' => 'model_catalog', 'status' => 'draft', 'config' => $catalog]);
        $this->assertSame($expected, app(AgentTeamSetupService::class)->inspect()['catalog_current']);
        $this->assertDatabaseCount('model_use_cases', 0);
        $this->assertDatabaseCount('model_profiles', 0);
        Http::assertNothingSent();
    }

    /** @return array<string,array{mixed,bool}> */
    public static function catalogDates(): array
    {
        return [
            'current' => ['2026-09-08T10:00:00Z', true],
            'stale' => ['2026-08-20T10:00:00Z', false],
            'future' => ['2050-09-09T10:00:00Z', false],
            'invalid' => ['not-a-date', false],
            'null' => [null, false],
            'empty' => ['', false],
            'whitespace' => ['   ', false],
            'non-string' => [123, false],
        ];
    }

    private function credential(): ProviderCredential
    {
        return ProviderCredential::create(['provider' => 'openrouter', 'label' => 'Agent tests', 'api_key' => 'secret-test-provider-key', 'request_format' => 'chat_completions', 'active' => true]);
    }
}
