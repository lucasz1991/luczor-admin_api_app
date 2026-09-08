<?php

namespace Tests\Feature;

use App\Models\LocalModelCatalog;
use App\Models\User;
use App\Services\LocalModelManifestService;
use App\Services\LocalModelTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalModelTiersTest extends TestCase
{
    use RefreshDatabase;

    private function configureExistingModel(): void
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/local-model-manifest-v1.json')), true);
        $legacy = $fixtures['cases']['explicit_experiment'];
        config(['local_models.models' => $legacy['models'], 'local_models.routing' => $legacy['routing'], 'local_models.catalog_override_valid' => true]);
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
        $existing = collect(config('local_models.models'))->firstWhere('id', config('local_models.routing.default_model_id'));
        $this->assertSame($existing['artifact'], $draft['models'][4]['artifact']);
        foreach ($draft['models'] as $model) {
            $this->assertSame($existing['artifact'], $model['artifact']);
            $this->assertSame($existing['capacity_policy'], $model['capacity_policy']);
            $this->assertSame($existing['runtime'], $model['runtime']);
            $this->assertSame($existing['enabled'], $model['enabled']);
        }
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
        $draft['models'][0]['artifact'] = null;
        $this->put('/admin/local-model-tiers', ['revision' => LocalModelCatalog::find(1)->revision, 'models' => array_map('json_encode', $draft['models'])])->assertSessionHasErrors('models');
        $this->assertNotNull(LocalModelCatalog::find(1)->published['models'][0]['artifact']);
    }

    public function test_regular_user_cannot_edit_or_publish_local_models(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get('/admin/local-model-tiers')->assertForbidden();
        $this->put('/admin/local-model-tiers', [])->assertForbidden();
    }

    public function test_upgrade_fills_only_untouched_old_proposals_and_keeps_published_catalog(): void
    {
        $this->configureExistingModel();
        $draft = app(LocalModelTierService::class)->defaults();
        $published = $draft;
        for ($index = 0; $index < 4; $index++) {
            $draft['models'][$index]['enabled'] = false;
            $draft['models'][$index]['artifact'] = null;
        }
        LocalModelCatalog::create(['id' => 1, 'draft' => $draft, 'published' => $published, 'revision' => 1]);
        $migration = require database_path('migrations/2026_09_08_000003_fill_starter_model_tiers.php');
        $migration->up();
        $catalog = LocalModelCatalog::find(1);
        $this->assertSame($published, $catalog->published);
        $this->assertGreaterThan(1, $catalog->revision);
        foreach ($catalog->draft['models'] as $model) {
            $this->assertSame($draft['models'][4]['artifact'], $model['artifact']);
            $this->assertSame($draft['models'][4]['enabled'], $model['enabled']);
        }
        $revision = $catalog->revision;
        $migration->up();
        $this->assertSame($revision, $catalog->fresh()->revision);
        $draft['models'][0]['artifact'] = $published['models'][0]['artifact'];
        $this->assertNull(app(LocalModelTierService::class)->upgradeStarterDraft($draft));
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
