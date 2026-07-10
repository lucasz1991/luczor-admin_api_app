<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\GithubWebhookDelivery;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GithubAndVoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        Config::set('luczor.device_jobs.private_key_file', '');
        Config::set('luczor.device_jobs.private_key', $this->privateKey);
    }

    public function test_signed_voice_manifest_never_uses_client_paths_or_keys(): void
    {
        Config::set('luczor.voice.manifest', [
            'version' => '2026.07.09',
            'assets' => [['id' => 'whisper', 'kind' => 'stt_binary', 'platform' => 'windows-x86_64', 'url' => 'https://releases.example/whisper.exe', 'sha256' => str_repeat('a', 64), 'file_name' => 'whisper.exe']],
        ]);
        [, $token] = $this->token(['settings.read']);
        $response = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/voice/manifest')->assertOk();
        $payload = $response->json('payload_json');
        $signature = base64_decode($response->json('signature'));

        $private = openssl_pkey_get_private($this->privateKey);
        $public = openssl_pkey_get_details($private)['key'];
        $this->assertSame(1, openssl_verify($payload, $signature, $public, OPENSSL_ALGO_SHA256));
        $this->assertStringNotContainsString('binary_path', $payload);
        $this->assertStringNotContainsString('api_key', $payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(rtrim((string) config('app.url'), '/').'/api/v1/voice/releases/2026.07.09/whisper.exe', $decoded['assets'][0]['url']);
    }

    public function test_only_manifest_listed_voice_assets_are_publicly_downloadable(): void
    {
        $root = storage_path('framework/testing/voice-release-'.uniqid());
        File::ensureDirectoryExists($root.'/2026.07.10');
        File::put($root.'/2026.07.10/voice.bin', 'signed-voice-asset');
        File::put($root.'/manifest.json', json_encode([
            'version' => '2026.07.10',
            'assets' => [['file_name' => 'voice.bin']],
        ], JSON_THROW_ON_ERROR));
        Config::set('luczor.voice.manifest_file', $root.'/manifest.json');
        Config::set('luczor.voice.release_root', $root);

        try {
            $response = $this->get('/api/v1/voice/releases/2026.07.10/voice.bin')
                ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame('voice.bin', basename($response->baseResponse->getFile()->getPathname()));
            $this->get('/api/v1/voice/releases/2026.07.10/not-listed.bin')->assertNotFound();
            $this->get('/api/v1/voice/releases/2026.07.10/..%2F.env')->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_github_webhook_requires_signature_and_updates_only_registered_repository(): void
    {
        $user = User::factory()->create();
        $repository = Repository::create(['user_id' => $user->id, 'provider' => 'github', 'full_name' => 'luczor/demo', 'default_branch' => 'main']);
        Config::set('services.github.webhook_secret', 'webhook-secret');
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => str_repeat('a', 40),
            'repository' => ['full_name' => 'luczor/demo'],
            'head_commit' => [
                'id' => str_repeat('a', 40), 'message' => 'Fix', 'timestamp' => now()->toIso8601String(),
                'author' => ['name' => 'Dev'], 'added' => ['src/New.php'], 'modified' => ['src/App.php'], 'removed' => [],
            ],
        ];
        $raw = json_encode($payload);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret'),
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-1',
            'HTTP_X_GITHUB_EVENT' => 'push',
        ];

        $this->call('POST', '/api/v1/github/webhook', [], [], [], $headers, $raw)->assertOk();
        $this->assertDatabaseHas('repository_branches', ['repository_id' => $repository->id, 'name' => 'main', 'head_sha' => str_repeat('a', 40)]);
        $this->assertDatabaseHas('repository_commits', ['repository_id' => $repository->id, 'sha' => str_repeat('a', 40)]);
        $this->assertSame(1, GithubWebhookDelivery::count());

        $this->call('POST', '/api/v1/github/webhook', [], [], [], $headers, $raw)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, GithubWebhookDelivery::count());
        $this->postJson('/api/v1/github/webhook', $payload)->assertUnauthorized();
    }

    public function test_voice_release_command_hashes_and_signs_all_required_assets(): void
    {
        $assets = storage_path('framework/testing/voice-assets-'.uniqid());
        $output = $assets.'/release.json';
        $relativeOutput = 'storage/framework/testing/'.basename($assets).'/release.json';
        File::ensureDirectoryExists($assets);
        foreach (['whisper.exe', 'whisper.bin', 'piper.exe', 'voice.onnx'] as $file) {
            File::put($assets.'/'.$file, 'fixture-'.$file);
        }

        try {
            $this->artisan('luczor:voice-release', [
                '--release-version' => '2026.07.09',
                '--base-url' => 'https://releases.example/luczor/voice/2026.07.09',
                '--stt-binary' => $assets.'/whisper.exe',
                '--stt-model' => $assets.'/whisper.bin',
                '--tts-binary' => $assets.'/piper.exe',
                '--tts-model' => $assets.'/voice.onnx',
                '--manifest-output' => $relativeOutput,
            ])->assertExitCode(0);

            $envelope = json_decode(File::get($output), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($envelope['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(4, $payload['assets']);
            $this->assertSame(hash_file('sha256', $assets.'/whisper.exe'), $payload['assets'][0]['sha256']);
            $this->assertStringStartsWith('https://', $payload['assets'][0]['url']);

            $private = openssl_pkey_get_private($this->privateKey);
            $public = openssl_pkey_get_details($private)['key'];
            $this->assertSame(1, openssl_verify($envelope['payload_json'], base64_decode($envelope['signature']), $public, OPENSSL_ALGO_SHA256));
        } finally {
            File::deleteDirectory($assets);
        }
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'abilities' => $abilities, 'active' => true]);
        return [$user, $minted['plain']];
    }
}
