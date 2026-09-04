<?php

namespace Tests\Feature;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Services\DeploymentHealthService;
use App\Services\ProviderPolicyService;
use App\Services\RedisHostKernelInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CapabilityRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_filter_drops_profiles_lacking_a_required_capability(): void
    {
        $case = ModelUseCase::create(['name' => 'Vision', 'slug' => 'vision', 'active' => true]);
        $vision = ModelProfile::create(['name' => 'V', 'slug' => 'v', 'provider' => 'openrouter', 'model_id' => 'm/v', 'capabilities' => ['vision', 'tools']]);
        $textOnly = ModelProfile::create(['name' => 'T', 'slug' => 't', 'provider' => 'openrouter', 'model_id' => 'm/t', 'capabilities' => ['tools']]);
        $unknown = ModelProfile::create(['name' => 'U', 'slug' => 'u', 'provider' => 'openrouter', 'model_id' => 'm/u']); // no capabilities
        foreach ([$vision, $textOnly, $unknown] as $i => $p) {
            ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $p->id, 'sort_order' => $i + 1, 'active' => true]);
        }

        $svc = new ProviderPolicyService;
        $ids = collect($svc->candidates(null, 'vision.describe', ['vision']))->pluck('slug')->all();

        $this->assertContains('v', $ids);     // has vision
        $this->assertNotContains('u', $ids);  // unknown capabilities fail closed
        $this->assertNotContains('t', $ids);  // declares caps but lacks vision
    }

    public function test_version_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/version')
            ->assertOk()
            ->assertJsonStructure(['api', 'app', 'server_time'])
            ->assertJsonMissingPath('laravel');
    }

    public function test_readiness_endpoint_is_public_without_leaking_runtime_versions(): void
    {
        $this->getJson('/api/v1/ready')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.database', true)
            ->assertJsonMissingPath('laravel')
            ->assertJsonMissingPath('php');
    }

    public function test_production_configuration_can_pass_the_static_deployment_gate(): void
    {
        Config::set('app.debug', false);
        Config::set('app.url', 'https://luczor.example.test');
        Config::set('session.secure', true);
        Config::set('cors.allowed_origins', ['https://luczor.example.test', 'https://tauri.localhost']);
        Config::set('cache.default', 'redis');
        Config::set('queue.default', 'redis');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.host', '127.0.0.1');
        Config::set('database.redis.default.password', str_repeat('r', 48));
        Config::set('database.redis.cache.url', null);
        Config::set('database.redis.cache.host', '127.0.0.1');
        Config::set('database.redis.cache.password', str_repeat('r', 48));
        Config::set('database.redis.horizon.url', null);
        Config::set('database.redis.horizon.host', '127.0.0.1');
        Config::set('database.redis.horizon.password', str_repeat('r', 48));
        Config::set('luczor.notifications.queue', 'notifications');
        Config::set('horizon.defaults.supervisor-1.queue', ['notifications', 'default']);
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb', [
            'key' => 'public-key',
            'secret' => 'secret-key',
            'app_id' => 'luczor',
            'options' => [
                'host' => 'luczor.example.test',
                'port' => 443,
                'scheme' => 'https',
            ],
        ]);
        Config::set('reverb.apps.apps.0.allowed_origins', [
            'luczor.example.test',
            'tauri.localhost',
        ]);
        $externalPrivateKey = tempnam(sys_get_temp_dir(), 'luczor-local-model-key-');
        $this->assertIsString($externalPrivateKey);
        $this->assertTrue(copy(
            base_path('tests/Fixtures/local-model-manifest-test-private.pem'),
            $externalPrivateKey,
        ));
        $publicKey = openssl_pkey_get_public((string) file_get_contents(
            base_path('tests/Fixtures/local-model-manifest-test-public.pem'),
        ));
        $this->assertNotFalse($publicKey);
        $publicDetails = openssl_pkey_get_details($publicKey);
        $this->assertIsArray($publicDetails);

        try {
            Config::set('local_models.signing.key_id', 'deployment-test-key');
            Config::set('local_models.signing.private_key', '');
            Config::set('local_models.signing.private_key_file', $externalPrivateKey);
            Config::set('local_models.signing.expected_public_key_sha256', hash('sha256', $publicDetails['key']));

            $this->artisan('luczor:deployment-check', [
                '--production' => true,
                '--configuration-only' => true,
            ])->assertSuccessful();
        } finally {
            @unlink($externalPrivateKey);
        }
    }

    public function test_local_origin_defaults_are_explicit_and_never_wildcarded(): void
    {
        $this->assertNotEmpty(config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotEmpty(config('reverb.apps.apps.0.allowed_origins'));
        $this->assertNotContains('*', config('reverb.apps.apps.0.allowed_origins'));
        foreach (config('reverb.apps.apps.0.allowed_origins') as $origin) {
            $this->assertStringNotContainsString('://', $origin);
        }
    }

    public function test_production_gate_accepts_explicit_internal_reverb_http_transport(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb', [
            'key' => 'public-key',
            'secret' => 'secret-key',
            'app_id' => 'luczor',
            'options' => [
                'host' => 'reverb',
                'port' => 8080,
                'scheme' => 'http',
            ],
        ]);
        Config::set('reverb.apps.apps.0.allowed_origins', ['https://luczor.example.test']);
        Config::set('luczor.realtime.allow_internal_http', true);
        Config::set('luczor.realtime.internal_host', 'reverb');

        $checks = app(DeploymentHealthService::class)->checks(
            enforceProduction: true,
            includeRuntime: false,
        );

        $this->assertTrue($checks['reverb_configured']);
    }

    public function test_production_deployment_gate_fails_closed_for_unsafe_defaults(): void
    {
        Config::set('app.debug', true);
        Config::set('app.url', 'http://localhost');
        Config::set('session.secure', false);
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cache.default', 'file');
        Config::set('queue.default', 'sync');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.host', 'redis.example.test');
        Config::set('database.redis.default.password', 'change-me');
        Config::set('database.redis.cache.url', null);
        Config::set('database.redis.cache.host', 'redis.example.test');
        Config::set('database.redis.cache.password', 'change-me');
        Config::set('database.redis.horizon.url', null);
        Config::set('database.redis.horizon.host', 'redis.example.test');
        Config::set('database.redis.horizon.password', 'change-me');
        Config::set('luczor.notifications.queue', 'notifications');
        Config::set('horizon.defaults.supervisor-1.queue', ['default']);
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb', [
            'key' => 'replace-with-public-reverb-key',
            'secret' => 'replace-with-reverb-secret',
            'app_id' => 'luczor',
            'options' => [
                'host' => 'localhost',
                'port' => 443,
                'scheme' => 'https',
            ],
        ]);
        Config::set('reverb.apps.apps.0.allowed_origins', ['*']);
        Config::set('luczor.memory.namespace_key', 'replace-with-stable-32-byte-memory-secret');
        Config::set('luczor.memory.ledger_key', 'replace-with-stable-32-byte-ledger-secret');

        $checks = app(DeploymentHealthService::class)->checks(
            enforceProduction: true,
            includeRuntime: false,
        );

        $this->assertNotEmpty($checks);
        foreach (array_keys($checks) as $name) {
            if ($name !== 'cognee_projection_timeout_budget') {
                $this->assertFalse($checks[$name], $name.' must fail closed.');
            }
        }
        $this->assertTrue($checks['cognee_projection_timeout_budget']);

        Config::set('luczor.cognee.timeout', 60);
        $unsafeBudget = app(DeploymentHealthService::class)->checks(
            enforceProduction: true,
            includeRuntime: false,
        );
        $this->assertFalse($unsafeBudget['cognee_projection_timeout_budget']);
    }

    public function test_production_runtime_gate_includes_the_host_redis_kernel_probe(): void
    {
        $kernel = new class extends RedisHostKernelInspector
        {
            public function overcommitMemoryEnabled(): bool
            {
                return true;
            }
        };

        $checks = (new DeploymentHealthService($kernel))->checks(
            enforceProduction: true,
            includeRuntime: true,
            probeReverbServer: false,
        );

        $this->assertArrayHasKey('redis_host_overcommit_memory', $checks);
        $this->assertTrue($checks['redis_host_overcommit_memory']);
    }
}
