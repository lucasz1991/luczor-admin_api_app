<?php

namespace Tests\Feature;

use App\Exceptions\LocalModelManifestConfigurationException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\LocalModelManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocalModelManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_metadata_only_contract_keeps_orca_as_default_and_flash_as_explicit_experiment(): void
    {
        $payload = $this->assertManifestCase('metadata_only_default');

        $this->assertSame('orcarouter-qwen3.8-27b-uncensored-q4-k-m', $payload['routing']['default_model_id']);
        $this->assertSame(['qwen3.8-flash-next'], $payload['routing']['experimental_model_ids']);
        $this->assertFalse($payload['models'][0]['enabled']);
        $this->assertNull($payload['models'][0]['artifact']);
    }

    public function test_enabled_unpromoted_flash_is_valid_only_as_an_explicit_experiment(): void
    {
        $payload = $this->assertManifestCase('explicit_experiment');

        $this->assertTrue($payload['models'][0]['enabled']);
        $this->assertFalse($payload['models'][0]['promoted']);
        $this->assertSame('orcarouter-qwen3.8-27b-uncensored-q4-k-m', $payload['routing']['default_model_id']);
        $this->assertTrue($payload['routing']['experimental_opt_in_required']);
    }

    public function test_promoted_flash_becomes_the_default_and_leaves_the_experiment_list(): void
    {
        $payload = $this->assertManifestCase('promoted_preferred');

        $this->assertTrue($payload['models'][0]['promoted']);
        $this->assertSame('stable', $payload['models'][0]['release_channel']);
        $this->assertSame('qwen3.8-flash-next', $payload['routing']['default_model_id']);
        $this->assertSame([], $payload['routing']['experimental_model_ids']);
    }

    public function test_promotion_and_release_channel_change_atomically(): void
    {
        $payload = $this->fixtureCase('explicit_experiment');
        $payload['models'][0]['promoted'] = true;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_promotion_state_invalid');

        $payload = $this->fixtureCase('metadata_only_default');
        $payload['models'][1]['promoted'] = false;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_promotion_state_invalid');
    }

    public function test_php_reproduces_the_cross_layer_golden_envelope_and_tampering_breaks_verification(): void
    {
        $payload = $this->configureFromFixture('explicit_experiment');
        config()->set('local_models.signing.key_id', 'luczor-local-model-test-2026-01');
        config()->set('local_models.signing.private_key', '');
        config()->set(
            'local_models.signing.private_key_file',
            base_path('tests/Fixtures/local-model-manifest-test-private.pem'),
        );
        config()->set(
            'local_models.signing.expected_public_key_sha256',
            $this->fixturePublicKeyFingerprint(),
        );
        [, $token] = $this->deviceToken(['settings.read']);
        $expectedWire = trim((string) file_get_contents(
            base_path('tests/Fixtures/local-model-manifest-golden-envelope.json'),
        ));

        $response = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/local-model/manifest');
        $response->assertOk();
        $this->assertSame($expectedWire, $response->getContent());
        $envelope = json_decode($expectedWire, true, 512, JSON_THROW_ON_ERROR);
        $canonical = app(LocalModelManifestService::class)->canonicalJson($payload);
        $this->assertSame(
            trim((string) file_get_contents(base_path('tests/Fixtures/local-model-manifest-golden-canonical.json'))),
            $canonical,
        );
        $this->assertSame('1ca6b2f8aedc633ec7f1685d8cc0c89485ffaa2d9e79224e32627d962eba1e0c', hash('sha256', $canonical));

        $publicKey = openssl_pkey_get_public((string) file_get_contents(
            base_path('tests/Fixtures/local-model-manifest-test-public.pem'),
        ));
        $this->assertNotFalse($publicKey);
        $signature = base64_decode($envelope['signature'], true);
        $this->assertIsString($signature);
        $this->assertSame(1, openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256));

        $tampered = $payload;
        $tampered['models'][0]['enabled'] = false;
        $this->assertSame(0, openssl_verify(
            app(LocalModelManifestService::class)->canonicalJson($tampered),
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function test_manifest_requires_settings_read_and_fails_safely_without_a_signing_key(): void
    {
        [, $wrongAbility] = $this->deviceToken(['sync.read']);
        $this->withHeader('X-Api-Key', $wrongAbility)
            ->getJson('/api/v1/local-model/manifest')
            ->assertForbidden();

        [, $token] = $this->deviceToken(['settings.read']);
        config()->set('local_models.signing.private_key', '');
        config()->set('local_models.signing.private_key_file', '');

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'local_model_signing_key_invalid')
            ->assertJsonMissingPath('exception');
    }

    public function test_bootstrap_discovers_the_manifest_and_separates_local_from_external_routing(): void
    {
        $this->configureFromFixture('metadata_only_default');
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('local_model_manifest.url', '/api/v1/local-model/manifest')
            ->assertJsonPath('local_model_manifest.schema_version', 1)
            ->assertJsonPath('local_model_manifest.catalog_version', 2026083001)
            ->assertJsonPath('local_model_manifest.policy_version', 2026083001)
            ->assertJsonPath('local_model_manifest.key_id', 'test-local-model-key')
            ->assertJsonPath('local_model_manifest.available', true)
            ->assertJsonPath('routing.legacy_scope', 'external_provider')
            ->assertJsonPath('routing.external_routing_managed_by', 'server')
            ->assertJsonPath('routing.external_client_model_selection', false)
            ->assertJsonPath('routing.local_routing_managed_by', 'desktop_signed_policy')
            ->assertJsonPath('routing.local_model_manifest_required', true);
    }

    public function test_enabled_model_with_incomplete_artifact_metadata_is_rejected(): void
    {
        $payload = $this->fixtureCase('metadata_only_default');
        $payload['models'][0]['enabled'] = true;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_enabled_metadata_incomplete');
    }

    public function test_enabled_model_requires_a_signed_positive_vram_threshold(): void
    {
        $payload = $this->fixtureCase('explicit_experiment');
        $payload['models'][0]['capacity_policy']['min_vram_bytes'] = null;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_enabled_metadata_incomplete');
    }

    public function test_v1_catalog_rejects_missing_extra_and_alternative_release_ids(): void
    {
        $base = $this->fixtureCase('metadata_only_default');
        $cases = [];

        $missing = $base;
        array_pop($missing['models']);
        $cases[] = [$missing, 'local_model_catalog_invalid'];

        $extra = $base;
        $third = $extra['models'][1];
        $third['id'] = 'third-local-model';
        $extra['models'][] = $third;
        $cases[] = [$extra, 'local_model_catalog_invalid'];

        $alternative = $base;
        $alternative['models'][1]['id'] = 'alternative-local-model';
        $cases[] = [$alternative, 'local_model_catalog_unsupported'];

        [, $token] = $this->deviceToken(['settings.read']);
        foreach ($cases as [$payload, $expectedCode]) {
            $this->configurePayload($payload);
            $this->withHeader('X-Api-Key', $token)
                ->getJson('/api/v1/local-model/manifest')
                ->assertStatus(503)
                ->assertJsonPath('code', $expectedCode);
        }
    }

    public function test_v1_model_roles_are_bound_to_the_exact_release_ids(): void
    {
        foreach ([[0, 'fallback'], [1, 'preferred']] as [$index, $role]) {
            $payload = $this->fixtureCase('metadata_only_default');
            $payload['models'][$index]['routing_role'] = $role;
            $this->configurePayload($payload);
            [, $token] = $this->deviceToken(['settings.read']);

            $this->withHeader('X-Api-Key', $token)
                ->getJson('/api/v1/local-model/manifest')
                ->assertStatus(503)
                ->assertJsonPath('code', 'local_model_routing_role_invalid');
        }
    }

    public function test_orca_fallback_remains_stable_and_promoted_when_flash_is_promoted(): void
    {
        $payload = $this->fixtureCase('promoted_preferred');
        $payload['models'][1]['promoted'] = false;
        $payload['models'][1]['release_channel'] = 'experimental';
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_fallback_state_invalid');
    }

    public function test_health_cooldown_is_at_least_one_second(): void
    {
        $payload = $this->fixtureCase('metadata_only_default');
        $payload['models'][0]['health_policy']['cooldown_ms'] = 999;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_health_policy_invalid');
    }

    public function test_configured_public_key_pin_must_match_the_signing_key(): void
    {
        $this->configureFromFixture('metadata_only_default');
        config()->set('local_models.signing.expected_public_key_sha256', str_repeat('0', 64));
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_signing_public_key_mismatch');
    }

    public function test_production_signing_key_must_be_absolute_and_outside_the_checkout(): void
    {
        $this->configureFromFixture('metadata_only_default');
        config()->set('local_models.signing.private_key', '');
        config()->set(
            'local_models.signing.private_key_file',
            base_path('tests/Fixtures/local-model-manifest-test-private.pem'),
        );
        config()->set(
            'local_models.signing.expected_public_key_sha256',
            $this->fixturePublicKeyFingerprint(),
        );

        try {
            app(LocalModelManifestService::class)->envelope(true);
            $this->fail('A production signing key inside the checkout must be rejected.');
        } catch (LocalModelManifestConfigurationException $exception) {
            $this->assertSame('local_model_signing_key_path_unsafe', $exception->reasonCode);
        }

        config()->set('local_models.signing.private_key_file', 'tests/Fixtures/local-model-manifest-test-private.pem');
        try {
            app(LocalModelManifestService::class)->envelope(true);
            $this->fail('A relative production signing-key path must be rejected.');
        } catch (LocalModelManifestConfigurationException $exception) {
            $this->assertSame('local_model_signing_key_path_unsafe', $exception->reasonCode);
        }
    }

    public function test_production_signing_requires_the_expected_public_key_pin(): void
    {
        $this->configureFromFixture('metadata_only_default');
        $externalPrivateKey = tempnam(sys_get_temp_dir(), 'luczor-local-model-key-');
        $this->assertIsString($externalPrivateKey);
        $this->assertTrue(copy(
            base_path('tests/Fixtures/local-model-manifest-test-private.pem'),
            $externalPrivateKey,
        ));

        try {
            config()->set('local_models.signing.private_key', '');
            config()->set('local_models.signing.private_key_file', $externalPrivateKey);
            config()->set('local_models.signing.expected_public_key_sha256', '');

            app(LocalModelManifestService::class)->envelope(true);
            $this->fail('A production signing key without a public-key pin must be rejected.');
        } catch (LocalModelManifestConfigurationException $exception) {
            $this->assertSame('local_model_signing_public_key_pin_missing', $exception->reasonCode);
        } finally {
            @unlink($externalPrivateKey);
        }
    }

    public function test_fractional_benchmark_numbers_are_rejected_to_keep_canonical_bytes_cross_language_safe(): void
    {
        $payload = $this->fixtureCase('explicit_experiment');
        $payload['models'][0]['capacity_policy']['benchmark_thresholds']['min_decode_tokens_per_second'] = 5.5;
        $this->configurePayload($payload);
        [, $token] = $this->deviceToken(['settings.read']);

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/local-model/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'local_model_benchmark_policy_invalid');
    }

    /** @return array<string,mixed> */
    private function assertManifestCase(string $case): array
    {
        $expected = $this->configureFromFixture($case);
        [, $token] = $this->deviceToken(['settings.read']);
        $response = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/local-model/manifest');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $envelope = $response->json();
        $this->assertSame(
            ['key_id', 'algorithm', 'payload_sha256', 'payload', 'signature'],
            array_keys($envelope),
        );
        $this->assertSame('test-local-model-key', $envelope['key_id']);
        $this->assertSame('RSA-SHA256', $envelope['algorithm']);
        $this->assertSame($expected, $envelope['payload']);

        $canonical = app(LocalModelManifestService::class)->canonicalJson($envelope['payload']);
        $this->assertSame(hash('sha256', $canonical), $envelope['payload_sha256']);
        $signature = base64_decode($envelope['signature'], true);
        $this->assertIsString($signature);
        $publicKey = openssl_pkey_get_public((string) config('local_models.testing_public_key'));
        $this->assertNotFalse($publicKey);
        $this->assertSame(1, openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256));

        return $envelope['payload'];
    }

    /** @return array<string,mixed> */
    private function configureFromFixture(string $case): array
    {
        $payload = $this->fixtureCase($case);
        $this->configurePayload($payload);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function configurePayload(array $payload): void
    {
        Carbon::setTestNow('2026-08-30T12:00:00Z');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        config()->set('local_models.schema_version', $payload['schema_version']);
        config()->set('local_models.catalog_version', $payload['catalog_version']);
        config()->set('local_models.policy_version', $payload['policy_version']);
        config()->set('local_models.ttl_seconds', 3600);
        config()->set('local_models.catalog_override_valid', true);
        config()->set('local_models.models', $payload['models']);
        config()->set('local_models.routing', $payload['routing']);
        config()->set('local_models.signing.key_id', 'test-local-model-key');
        config()->set('local_models.signing.private_key', $privateKey);
        config()->set('local_models.signing.private_key_file', '');
        config()->set('local_models.signing.expected_public_key_sha256', '');
        config()->set('local_models.testing_public_key', $details['key']);
    }

    /** @return array<string,mixed> */
    private function fixtureCase(string $case): array
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/local-model-manifest-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $fixture['cases'][$case];
    }

    private function fixturePublicKeyFingerprint(): string
    {
        $publicKey = openssl_pkey_get_public((string) file_get_contents(
            base_path('tests/Fixtures/local-model-manifest-test-public.pem'),
        ));
        $this->assertNotFalse($publicKey);
        $details = openssl_pkey_get_details($publicKey);
        $this->assertIsArray($details);

        return hash('sha256', $details['key']);
    }

    /** @param array<int,string> $abilities
     * @return array{0:User,1:string}
     */
    private function deviceToken(array $abilities): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Desktop',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }
}
