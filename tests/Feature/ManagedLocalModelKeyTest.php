<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalModelManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ManagedLocalModelKeyTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/luczor-key-test-'.bin2hex(random_bytes(12));
        config([
            'local_models.signing.auto_generate' => true,
            'local_models.signing.managed_directory' => $this->directory,
            'local_models.signing.private_key' => '',
            'local_models.signing.private_key_file' => '',
            'local_models.signing.expected_public_key_sha256' => '',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_public_endpoint_creates_and_reuses_a_key_that_verifies_the_manifest(): void
    {
        $descriptor = $this->getJson('/api/v1/local-model/signing-key')->assertOk()
            ->assertJsonStructure(['key_id', 'algorithm', 'public_key_b64', 'public_key_sha256'])
            ->assertJsonMissingPath('private_key')->json();
        $this->assertSame($descriptor, $this->getJson('/api/v1/local-model/signing-key')->assertOk()->json());
        $pem = base64_decode($descriptor['public_key_b64'], true);
        $this->assertSame(hash('sha256', $pem), $descriptor['public_key_sha256']);
        $this->assertSame(3072, openssl_pkey_get_details(openssl_pkey_get_public($pem))['bits']);
        $manifest = app(LocalModelManifestService::class);
        $envelope = $manifest->envelope(true);
        $this->assertSame(1, openssl_verify($manifest->canonicalJson($envelope['payload']),
            base64_decode($envelope['signature']), $pem, OPENSSL_ALGO_SHA256));
        $this->assertSame(0600, fileperms($this->directory.'/local-model-private.pem') & 0777);
    }

    public function test_corrupt_managed_key_is_not_replaced(): void
    {
        $this->getJson('/api/v1/local-model/signing-key')->assertOk();
        $path = $this->directory.'/local-model-private.pem';
        file_put_contents($path, 'corrupt');
        $this->getJson('/api/v1/local-model/signing-key')->assertStatus(503);
        $this->assertSame('corrupt', file_get_contents($path));
    }

    public function test_admin_page_displays_public_key_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/local-model-tiers')->assertOk()
            ->assertSee('Modellsignatur:')->assertSee('Bereit')
            ->assertSee(config('local_models.signing.key_id'))
            ->assertDontSee('BEGIN PRIVATE KEY');
    }

    public function test_explicit_missing_key_is_not_replaced_by_managed_key(): void
    {
        config(['local_models.signing.private_key_file' => $this->directory.'/missing.pem']);
        $this->getJson('/api/v1/local-model/signing-key')->assertStatus(503)
            ->assertJsonPath('code', 'local_model_signing_key_unreadable');
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_pin_mismatch_remains_an_error(): void
    {
        config(['local_models.signing.expected_public_key_sha256' => str_repeat('0', 64)]);
        $this->getJson('/api/v1/local-model/signing-key')->assertStatus(503)
            ->assertJsonPath('code', 'local_model_signing_public_key_mismatch');
    }

    public function test_managed_key_directory_inside_checkout_is_rejected(): void
    {
        config(['local_models.signing.managed_directory' => base_path()]);
        $this->getJson('/api/v1/local-model/signing-key')->assertStatus(503)
            ->assertJsonPath('code', 'local_model_managed_key_unavailable');
    }

    public function test_symlink_key_is_rejected(): void
    {
        $this->getJson('/api/v1/local-model/signing-key')->assertOk();
        $path = $this->directory.'/local-model-private.pem';
        rename($path, $path.'.original');
        symlink($path.'.original', $path);
        $this->getJson('/api/v1/local-model/signing-key')->assertStatus(503)
            ->assertJsonPath('code', 'local_model_managed_key_unavailable');
    }
}
