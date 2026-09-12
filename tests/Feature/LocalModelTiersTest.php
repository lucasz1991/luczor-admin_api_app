<?php

namespace Tests\Feature;

use App\Exceptions\LocalModelManifestConfigurationException;
use App\Models\LocalModelCatalog;
use App\Models\User;
use App\Services\LocalModelManifestService;
use App\Services\LocalModelTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalModelTiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_secondary_platform_cannot_replace_the_published_catalog(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $draft, 'revision' => 1]);
        $modified = $draft['models'];
        $modified[3]['platform_profiles'] = ['linux-x86_64' => ['runtime' => array_replace($modified[3]['runtime'], ['sha256' => 'invalid'])]];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/admin/local-model-tiers', ['revision' => 1, 'models' => array_map(fn ($model) => json_encode($model), $modified), 'publish' => true])
            ->assertRedirect()->assertSessionHasErrors('models');
        $stored = LocalModelCatalog::find(1);
        $this->assertSame($draft, $stored->published);
        $this->assertSame(1, $stored->revision);
    }

    public function test_platform_projection_rejects_malformed_model_lists_with_a_configuration_error(): void
    {
        config(['local_models.models' => ['malformed']]);
        $this->expectException(LocalModelManifestConfigurationException::class);
        (new LocalModelManifestService)->forPlatform('windows-x86_64');
    }

    public function test_replacing_weights_discards_previous_platform_evidence(): void
    {
        $this->configureExistingModel();
        $tiers = app(LocalModelTierService::class);
        $slot = $tiers->defaults()['models'][0];
        $slot['platform_profiles'] = ['windows-x86_64' => ['runtime' => $slot['runtime']]];
        $laptop = $tiers->laptopProfile($slot);
        $this->assertSame([], $laptop['platform_profiles']);
        $this->assertNull($laptop['runtime']);
        $this->assertFalse($laptop['enabled']);
    }

    public function test_platform_profiles_are_saved_and_projected_without_changing_weights(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'revision' => 1]);
        $profiles = array_fill(0, 5, null);
        $windows = $draft['models'][3]['runtime'];
        $linux = array_replace($windows, ['version' => 'test-linux-runtime']);
        $profiles[3] = json_encode(['windows-x86_64' => ['runtime' => $windows], 'linux-x86_64' => ['runtime' => $linux]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/admin/local-model-tiers', ['revision' => 1, 'models' => array_map(fn ($model) => json_encode($model), $draft['models']), 'platform_profiles' => $profiles, 'publish' => false])
            ->assertRedirect()->assertSessionHasNoErrors();
        $saved = LocalModelCatalog::find(1)->draft;
        $this->assertSame($linux, $saved['models'][3]['platform_profiles']['linux-x86_64']['runtime']);
        LocalModelCatalog::find(1)->update(['published' => $saved]);
        $manifest = new LocalModelManifestService;
        $linuxPayload = $manifest->forPlatform('linux-x86_64')->payload();
        $windowsPayload = $manifest->forPlatform('windows-x86_64')->payload();
        $this->assertSame('test-linux-runtime', $linuxPayload['models'][3]['runtime']['version']);
        $this->assertSame($windows['version'], $windowsPayload['models'][3]['runtime']['version']);
        $this->assertSame($linuxPayload['models'][3]['artifact'], $windowsPayload['models'][3]['artifact']);
        $this->assertArrayNotHasKey('platform_profiles', $linuxPayload['models'][3]);
    }

    public function test_laptop_profile_is_a_disabled_pinned_draft_and_keeps_active_models(): void
    {
        $this->configureExistingModel();
        $tiers = app(LocalModelTierService::class);
        $draft = $tiers->defaults();
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $draft, 'revision' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/local-model-tiers/laptop', ['revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $catalog = LocalModelCatalog::find(1);
        $this->assertSame($draft, $catalog->published);
        $model = $catalog->draft['models'][0];
        $this->assertSame($draft['models'][0]['id'], $model['id']);
        $this->assertFalse($model['enabled']);
        $this->assertNull($model['runtime']);
        $this->assertNull($model['evaluation_report_hash']);
        $this->assertSame(2497280256, $model['artifact']['size_bytes']);
        $this->assertSame(0, $model['capacity_policy']['min_vram_bytes']);
        $this->post('/admin/local-model-tiers/laptop', ['revision' => 1])->assertStatus(409);
    }

    public function test_preparing_laptop_again_preserves_or_restores_published_evidence(): void
    {
        $this->configureExistingModel();
        $tiers = app(LocalModelTierService::class);
        $published = $tiers->defaults();
        $published['models'][0] = $tiers->laptopProfile($published['models'][0]);
        $verified = $published['models'][0];
        $this->assertSame($verified, $tiers->laptopProfile($verified));
        $draft = $published;
        $draft['models'][0]['runtime'] = null;
        $draft['models'][0]['enabled'] = false;
        $draft['models'][2]['display_name'] = 'Keep my other edits';
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $published, 'revision' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/local-model-tiers/laptop', ['revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $catalog = LocalModelCatalog::find(1);
        $this->assertSame($verified, $catalog->draft['models'][0]);
        $this->assertSame('Keep my other edits', $catalog->draft['models'][2]['display_name']);
        $this->assertSame($published, $catalog->published);
    }

    public function test_regular_user_cannot_prepare_laptop_profile(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->post('/admin/local-model-tiers/laptop', ['revision' => 2])->assertForbidden();
    }

    private function configureExistingModel(): void
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/local-model-manifest-v1.json')), true);
        $legacy = $fixtures['cases']['explicit_experiment'];
        config(['local_models.schema_version' => 1, 'local_models.models' => $legacy['models'], 'local_models.routing' => $legacy['routing'], 'local_models.catalog_override_valid' => true]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        config(['local_models.signing.private_key' => $private, 'local_models.signing.private_key_file' => '', 'local_models.signing.expected_public_key_sha256' => '']);
    }

    public function test_five_tiers_preserve_existing_model_and_draft_does_not_change_active_catalog(): void
    {
        $this->configureExistingModel();
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = app(LocalModelTierService::class)->defaults();
        $this->assertCount(5, $draft['models']);
        $this->assertSame([
            'alxis955-qwe2.5-coder-uncensored',
            'darkmaniac7-qwen3.5-4b-uncensored-mnn',
            'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'thebloke-wizardlm-uncensored-falcon-40b-gptq',
        ], array_column($draft['models'], 'id'));
        $this->assertSame([
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
            'darkmaniac7-qwen3.5-4b-uncensored-mnn',
            'alxis955-qwe2.5-coder-uncensored',
        ], $draft['routing']['fallback_model_ids']);
        $existing = collect(config('local_models.models'))->firstWhere('id', config('local_models.routing.default_model_id'));
        $this->assertSame($existing['artifact'], $draft['models'][3]['artifact']);
        $this->assertTrue($draft['models'][3]['enabled']);
        $this->assertTrue(collect($draft['models'])->except(3)->every(fn ($model) => $model['artifact'] === null && $model['enabled'] === false));
        $this->actingAs($admin)->get('/admin/local-model-tiers')->assertOk()->assertSee('fünf Leistungsstufen');
        $this->put('/admin/local-model-tiers', ['revision' => 0, 'models' => array_map('json_encode', $draft['models'])])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(LocalModelCatalog::find(1)->published);
        $this->assertSame(1, app(LocalModelManifestService::class)->payload()['schema_version']);
    }

    public function test_publish_signs_five_tiers_and_rejects_stale_edits_or_incomplete_enabled_files(): void
    {
        $this->configureExistingModel();
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = app(LocalModelTierService::class)->defaults();
        $body = ['revision' => 0, 'models' => array_map('json_encode', $draft['models']), 'publish' => true];
        $this->actingAs($admin)->put('/admin/local-model-tiers', $body)->assertRedirect()->assertSessionHasNoErrors();
        $payload = app(LocalModelManifestService::class)->envelope()['payload'];
        $this->assertSame(2, $payload['schema_version']);
        $this->assertCount(5, $payload['models']);
        $this->assertSame([], $payload['routing']['experimental_model_ids']);
        $this->put('/admin/local-model-tiers', $body)->assertStatus(409);
        $draft['models'][3]['artifact'] = null;
        $this->put('/admin/local-model-tiers', ['revision' => LocalModelCatalog::find(1)->revision, 'models' => array_map('json_encode', $draft['models'])])->assertSessionHasErrors('models');
        $this->assertStringContainsString('local_model_enabled_metadata_incomplete', session('errors')->first('models'));
        $this->assertNotNull(LocalModelCatalog::find(1)->published['models'][3]['artifact']);
    }

    public function test_regular_user_cannot_edit_or_publish_local_models(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get('/admin/local-model-tiers')->assertForbidden();
        $this->put('/admin/local-model-tiers', [])->assertForbidden();
    }

    public function test_five_scope_controls_preserve_thresholds_omission_and_the_published_catalog(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        $published = $draft;
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $published, 'revision' => 1]);
        $draft['models'][3]['capacity_policy']['accelerator_memory_scope'] = 'compatible_group';
        $profiles = $this->profiles($draft);
        $profiles[0]['accelerator_memory_scope'] = 'compatible_group';
        $profiles[1]['accelerator_memory_scope'] = 'single_device';
        $profiles[2]['accelerator_memory_scope'] = null;
        $profiles[3]['accelerator_memory_scope'] = 'compatible_group';
        // An older client omitting the new field must preserve the submitted model's existing scope.
        $profiles[4]['accelerator_memory_scope'] = '';
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/admin/local-model-tiers', ['revision' => 1, 'models' => array_map('json_encode', $draft['models']), 'profiles' => $profiles])
            ->assertRedirect()->assertSessionHasNoErrors();

        $catalog = LocalModelCatalog::find(1);
        $this->assertSame($published, $catalog->published);
        $models = $catalog->draft['models'];
        $this->assertSame('compatible_group', $models[0]['capacity_policy']['accelerator_memory_scope']);
        $this->assertSame('single_device', $models[1]['capacity_policy']['accelerator_memory_scope']);
        $this->assertArrayNotHasKey('accelerator_memory_scope', $models[2]['capacity_policy']);
        $this->assertSame('compatible_group', $models[3]['capacity_policy']['accelerator_memory_scope']);
        $this->assertArrayNotHasKey('accelerator_memory_scope', $models[4]['capacity_policy']);

        $response = $this->get('/admin/local-model-tiers')->assertOk()->assertSee('Kompatible GPUs gemeinsam');
        foreach (range(0, 4) as $index) {
            $response->assertSee('name="profiles['.$index.'][accelerator_memory_scope]"', false);
        }
        $validated = app(LocalModelManifestService::class)->validateCatalog($catalog->draft);
        $this->assertSame('compatible_group', $validated['models'][3]['capacity_policy']['accelerator_memory_scope']);
        $this->assertArrayNotHasKey('accelerator_memory_scope', $validated['models'][4]['capacity_policy']);
    }

    public function test_invalid_profile_scope_and_invalid_raw_catalog_scope_leave_no_catalog_changes(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        $profiles = $this->profiles($draft);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['all', false, ['compatible_group']] as $scope) {
            $profiles[0]['accelerator_memory_scope'] = $scope;
            $this->put('/admin/local-model-tiers', ['revision' => 0, 'models' => array_map('json_encode', $draft['models']), 'profiles' => $profiles])
                ->assertSessionHasErrors('profiles.0.accelerator_memory_scope');
            $this->assertNull(LocalModelCatalog::find(1));
        }
        $draft['models'][0]['capacity_policy']['accelerator_memory_scope'] = null;
        $this->put('/admin/local-model-tiers', ['revision' => 0, 'models' => array_map('json_encode', $draft['models']), 'publish' => true])
            ->assertSessionHasErrors('models');
        $this->assertNull(LocalModelCatalog::find(1));
    }

    private function profiles(array $draft): array
    {
        return array_map(fn (array $model) => [
            'name' => $model['display_name'],
            'enabled' => $model['enabled'],
            'context' => $model['context_limit'] ?? 32768,
            'total_ram' => max(1, ($model['capacity_policy']['min_total_ram_bytes'] ?? 0) / 1024 ** 3),
            'free_ram' => max(0.5, ($model['capacity_policy']['min_available_ram_bytes'] ?? 0) / 1024 ** 3),
            'vram' => max(0, ($model['capacity_policy']['min_vram_bytes'] ?? 0) / 1024 ** 3),
        ], $draft['models']);
    }

    public function test_upgrade_fills_only_untouched_old_proposals_and_keeps_published_catalog(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        $published = $draft;
        $legacyModel = $draft['models'][3];
        $draft['models'][0]['id'] = 'local-tier-light';
        $draft['models'][1]['id'] = 'local-tier-compact';
        $draft['models'][2]['id'] = 'local-tier-balanced';
        $draft['models'][3]['id'] = 'local-tier-performance';
        $draft['models'][4] = $legacyModel;
        $draft['models'][4]['id'] = 'orcarouter-qwen3.8-27b-uncensored-q4-k-m';
        $draft['models'][4]['routing_role'] = 'preferred';
        $draft['models'][4]['enabled'] = true;
        for ($index = 0; $index < 4; $index++) {
            $draft['models'][$index]['enabled'] = $draft['models'][4]['enabled'];
            $draft['models'][$index]['artifact'] = $draft['models'][4]['artifact'];
            $draft['models'][$index]['runtime'] = $draft['models'][4]['runtime'];
            $draft['models'][$index]['capacity_policy'] = $draft['models'][4]['capacity_policy'];
        }
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $published, 'revision' => 1]);
        $migration = require database_path('migrations/2026_09_12_160000_configure_uncensored_model_ladder.php');
        $migration->up();
        $catalog = LocalModelCatalog::find(1);
        $this->assertSame($published, $catalog->published);
        $this->assertGreaterThan(1, $catalog->revision);
        $this->assertSame([
            'alxis955-qwe2.5-coder-uncensored',
            'darkmaniac7-qwen3.5-4b-uncensored-mnn',
            'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'thebloke-wizardlm-uncensored-falcon-40b-gptq',
        ], array_column($catalog->draft['models'], 'id'));
        $this->assertSame($draft['models'][4]['artifact'], $catalog->draft['models'][3]['artifact']);
        $this->assertTrue($catalog->draft['models'][3]['enabled']);
        $this->assertTrue(collect($catalog->draft['models'])->except(3)->every(fn ($model) => $model['artifact'] === null && $model['enabled'] === false));
        $revision = $catalog->revision;
        $migration->up();
        $this->assertSame($revision, $catalog->fresh()->revision);
        $this->assertNull(app(LocalModelTierService::class)->configureRequestedLadder($catalog->draft));
    }

    public function test_missing_signer_and_non_object_models_leave_catalog_unpublished(): void
    {
        $this->configureExistingModel();
        config(['local_models.signing.private_key' => '']);
        $draft = app(LocalModelTierService::class)->defaults();
        $body = ['revision' => 0, 'models' => array_map('json_encode', $draft['models']), 'publish' => true];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/admin/local-model-tiers', $body)->assertSessionHasErrors('models');
        $this->assertNull(LocalModelCatalog::find(1));
        $body['models'][0] = '42';
        $this->put('/admin/local-model-tiers', $body)->assertSessionHasErrors('models');
        $this->assertNull(LocalModelCatalog::find(1));
    }
}
