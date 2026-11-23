<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\ClaudeAccessService;
use App\Services\ProviderHttpClientFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Throwable;

/** Personal Claude keys: write-only secret, per-user isolation, validated before saving. */
class ProviderKeyApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fake-claude-key-AAAA1111BBBB2222';

    private const OTHER_KEY = 'fake-claude-key-CCCC3333DDDD4444';

    private const ORG_KEY = 'fake-org-claude-key-EEEE5555FFFF';

    private const URL = '/api/v1/provider-keys/anthropic';

    public function test_status_requires_the_provider_keys_ability_and_starts_disconnected(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
        [, $proxyOnly] = $this->token(['proxy.use']);
        $this->withHeader('X-Api-Key', $proxyOnly)->getJson(self::URL)->assertStatus(403);

        [, $token] = $this->token();
        $this->withHeader('X-Api-Key', $token)->getJson(self::URL)->assertOk()->assertExactJson([
            'source' => null,
            'connected' => false,
            'masked_key' => null,
            'last_tested_at' => null,
            'last_test_status' => null,
            'allowed_sources' => ['user'],
            'provider_fallback' => false,
        ]);
        $this->assertContains('provider.keys', ApiKey::DEVICE_ABILITIES);
        $this->assertContains('provider.keys', ApiKey::ABILITIES);
    }

    public function test_valid_key_is_checked_against_the_model_list_then_stored_encrypted(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(200, function (string $method, string $url, array $options): void {
            $this->assertSame('GET', $method);
            $this->assertSame('https://api.anthropic.com/v1/models', $url);
            $this->assertSame(self::KEY, $options['headers']['x-api-key']);
            $this->assertSame('2026-10-21', $options['headers']['anthropic-version']);
            $this->assertFalse($options['http_errors']);
        });

        $response = $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY]);

        $response->assertOk()
            ->assertJsonPath('source', 'user')
            ->assertJsonPath('connected', true)
            ->assertJsonPath('masked_key', 'fake...2222')
            ->assertJsonPath('last_test_status', 'ok')
            ->assertJsonPath('allowed_sources', ['user'])
            ->assertJsonPath('provider_fallback', false);
        $this->assertNotNull($response->json('last_tested_at'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        $credential = ProviderCredential::query()->where('user_id', $user->id)->sole();
        $this->assertSame('anthropic', $credential->provider);
        $this->assertSame('messages', $credential->request_format);
        $this->assertTrue($credential->active);
        $this->assertSame(self::KEY, $credential->api_key);
        // Encrypted at rest and hidden from serialization.
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('provider_credentials')->where('id', $credential->id)->value('api_key'));
        $this->assertStringNotContainsString(self::KEY, $credential->toJson());

        $this->withHeader('X-Api-Key', $token)->getJson(self::URL)
            ->assertOk()->assertJsonPath('connected', true)->assertDontSee(self::KEY);

        $audit = AuditEvent::query()->where('event_type', 'provider_key.set')->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($audit->getAttributes()));
    }

    public function test_replacing_the_key_keeps_exactly_one_personal_credential(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::OTHER_KEY])
            ->assertOk()->assertJsonPath('masked_key', 'fake...4444');

        $this->assertSame(self::OTHER_KEY, ProviderCredential::query()->where('user_id', $user->id)->sole()->api_key);
    }

    public function test_invalid_key_is_rejected_and_never_stored(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(401);

        $response = $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'claude_key_invalid');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertSame(0, ProviderCredential::query()->count());

        // A rejected replacement leaves the working key untouched.
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();
        $this->fakeProbe(403);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::OTHER_KEY])->assertStatus(422);
        $this->assertSame(self::KEY, ProviderCredential::query()->where('user_id', $user->id)->sole()->api_key);
    }

    public function test_unreachable_provider_returns_502_without_storing_or_leaking_the_key(): void
    {
        [, $token] = $this->token();
        $this->fakeProbe(new ConnectException('Connection refused for key '.self::KEY, new PsrRequest('GET', 'https://api.anthropic.com/v1/models')));

        $response = $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY]);

        $response->assertStatus(502)->assertJsonPath('error_code', 'claude_unreachable');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertSame(0, ProviderCredential::query()->count());

        $this->fakeProbe(503);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])
            ->assertStatus(502)->assertJsonPath('error_code', 'claude_unreachable');
        $this->assertSame(0, ProviderCredential::query()->count());
    }

    public function test_malformed_key_fails_validation_before_any_provider_call(): void
    {
        [, $token] = $this->token();
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldNotReceive('make');
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors('api_key');
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => "bad key\nwith spaces"])
            ->assertStatus(422)->assertJsonValidationErrors('api_key');
    }

    public function test_users_cannot_see_test_or_delete_each_others_keys(): void
    {
        [$owner, $ownerToken] = $this->token();
        [, $otherToken] = $this->token();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $ownerToken)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();

        $this->withHeader('X-Api-Key', $otherToken)->getJson(self::URL)
            ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('masked_key', null)->assertJsonPath('source', null);
        // No provider call: the other user has no resolvable credential.
        $this->forbidProviderCalls();
        $this->withHeader('X-Api-Key', $otherToken)->postJson(self::URL.'/test')
            ->assertOk()->assertExactJson(['ok' => false, 'error_code' => 'claude_access_missing']);
        $this->withHeader('X-Api-Key', $otherToken)->deleteJson(self::URL)->assertOk()->assertJsonPath('connected', false);

        $this->assertSame(self::KEY, ProviderCredential::query()->where('user_id', $owner->id)->sole()->api_key);
        $this->withHeader('X-Api-Key', $ownerToken)->getJson(self::URL)->assertOk()->assertJsonPath('connected', true);
    }

    public function test_delete_revokes_the_personal_key_immediately(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();

        $this->withHeader('X-Api-Key', $token)->deleteJson(self::URL)
            ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('masked_key', null)->assertJsonPath('source', null);

        $this->assertSame(0, ProviderCredential::query()->where('user_id', $user->id)->count());
        $this->assertNull(app(ClaudeAccessService::class)->resolve($user->id)['credential']);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'provider_key.deleted')->count());
    }

    public function test_deleting_the_user_removes_the_personal_key(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();
        $global = $this->organizationCredential();

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertSame([$global->id], ProviderCredential::query()->pluck('id')->all());
    }

    public function test_test_endpoint_records_the_outcome_for_the_resolved_credential(): void
    {
        [$user, $token] = $this->token();
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk();

        $this->fakeProbe(401, fn (string $method, string $url, array $options) => $this->assertSame(self::KEY, $options['headers']['x-api-key']));
        $failed = $this->withHeader('X-Api-Key', $token)->postJson(self::URL.'/test');
        $failed->assertOk()->assertExactJson(['ok' => false, 'error_code' => 'claude_key_invalid']);
        $credential = ProviderCredential::query()->where('user_id', $user->id)->sole();
        $this->assertSame('failed', $credential->last_test_status);
        $this->assertSame('claude_key_invalid', $credential->last_error_code);

        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->postJson(self::URL.'/test')
            ->assertOk()->assertExactJson(['ok' => true, 'error_code' => null]);
        $credential->refresh();
        $this->assertSame('ok', $credential->last_test_status);
        $this->assertNull($credential->last_error_code);
        $this->assertNotNull($credential->last_tested_at);
    }

    public function test_source_must_be_allowed_and_organization_key_is_never_exposed(): void
    {
        [$user, $token] = $this->token();
        $claude = app(ClaudeAccessService::class);

        // Organization access is neither released nor configured yet.
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/source', ['source' => 'organization'])
            ->assertStatus(422)->assertJsonPath('error_code', 'claude_source_not_allowed');
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/source', ['source' => 'elsewhere'])
            ->assertStatus(422)->assertJsonValidationErrors('source');

        $this->organizationCredential();
        // A global credential alone is not enough; the admin must release it.
        $this->withHeader('X-Api-Key', $token)->getJson(self::URL)->assertJsonPath('allowed_sources', ['user'])->assertJsonPath('connected', false);
        $claude->putSetting(ClaudeAccessService::SETTING_ORG_KEY_FOR_USERS, true);

        // Without a personal key the released organization access applies.
        $status = $this->withHeader('X-Api-Key', $token)->getJson(self::URL);
        $status->assertOk()->assertJsonPath('source', 'organization')->assertJsonPath('connected', true)
            ->assertJsonPath('masked_key', null)->assertJsonPath('allowed_sources', ['user', 'organization']);
        $this->assertStringNotContainsString('EEEE', $status->getContent());

        // A personal key wins by default; the explicit choice switches back.
        $this->fakeProbe(200);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::KEY])->assertOk()->assertJsonPath('source', 'user');
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/source', ['source' => 'organization'])
            ->assertOk()->assertJsonPath('source', 'organization')->assertJsonPath('masked_key', 'fake...2222');
        $this->assertSame(self::ORG_KEY, $claude->resolve($user->id)['credential']->api_key);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/source', ['source' => 'user'])
            ->assertOk()->assertJsonPath('source', 'user');
        $this->assertSame(self::KEY, $claude->resolve($user->id)['credential']->api_key);

        // Withdrawing personal keys blocks new keys and ignores stored ones.
        $claude->putSetting(ClaudeAccessService::SETTING_USER_KEYS_ALLOWED, false);
        $this->withHeader('X-Api-Key', $token)->getJson(self::URL)
            ->assertJsonPath('source', 'organization')->assertJsonPath('allowed_sources', ['organization']);
        $this->forbidProviderCalls();
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL, ['api_key' => self::OTHER_KEY])
            ->assertStatus(403)->assertJsonPath('error_code', 'claude_user_keys_disabled');
    }

    public function test_provider_fallback_option_is_stored_per_user_outside_the_generic_preferences(): void
    {
        [$user, $token] = $this->token(['provider.keys', 'settings.read', 'sync.write']);
        [$other, $otherToken] = $this->token();

        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/options', ['provider_fallback' => true])
            ->assertOk()->assertJsonPath('provider_fallback', true);
        $this->withHeader('X-Api-Key', $token)->putJson(self::URL.'/options', ['provider_fallback' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('provider_fallback');

        $this->assertTrue(app(ClaudeAccessService::class)->providerFallback($user->id));
        $this->assertFalse(app(ClaudeAccessService::class)->providerFallback($other->id));
        $this->withHeader('X-Api-Key', $otherToken)->getJson(self::URL)->assertJsonPath('provider_fallback', false);

        // Server-owned keys are invisible to and immutable through /preferences.
        $this->assertNotContains(ClaudeAccessService::PREFERENCE_PROVIDER_FALLBACK, UserPreference::ALLOWLIST);
        $this->assertNotContains(ClaudeAccessService::PREFERENCE_SOURCE, UserPreference::ALLOWLIST);
        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/preferences')->assertOk()->assertExactJson(['preferences' => []]);
        $this->withHeader('X-Api-Key', $token)->putJson('/api/v1/preferences', ['preferences' => [
            ['key' => ClaudeAccessService::PREFERENCE_SOURCE, 'value' => 'organization'],
        ]])->assertOk()->assertJsonPath('skipped', [ClaudeAccessService::PREFERENCE_SOURCE]);
        $this->assertNull(app(ClaudeAccessService::class)->preferredSource($user->id));
    }

    private function organizationCredential(): ProviderCredential
    {
        return ProviderCredential::create([
            'provider' => 'anthropic', 'label' => 'Organisation', 'api_key' => self::ORG_KEY,
            'request_format' => 'messages', 'active' => true,
        ]);
    }

    /** @param  int|Throwable  $result  HTTP status of the model-list probe, or a transport failure */
    private function fakeProbe(int|Throwable $result, ?callable $assert = null): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $expectation = $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) use ($assert): bool {
            if ($assert) {
                $assert($method, $url, $options);
            }

            return true;
        });
        $result instanceof Throwable
            ? $expectation->andThrow($result)
            : $expectation->andReturn(new Response($result, [], '{"data":[]}'));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    private function forbidProviderCalls(): void
    {
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldNotReceive('make');
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    /**
     * @param  array<int,string>  $abilities
     * @return array{0: User, 1: string}
     */
    private function token(array $abilities = ['provider.keys']): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Desktop', 'abilities' => $abilities, 'active' => true]);

        return [$user, $minted['plain']];
    }
}
