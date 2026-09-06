<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\Speech\SharedSpeechException;
use App\Services\Speech\SharedSpeechTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedSpeechApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'synthetic-luczor-service-token-not-a-real-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['shared_speech.enabled' => true, 'shared_speech.token' => self::TOKEN,
            'shared_speech.token_file' => '', 'shared_speech.url' => 'http://127.0.0.1:8092',
            'shared_speech.client_id' => 'luczor', 'shared_speech.requests_per_minute' => 30]);
    }

    public function test_only_authenticated_proxy_keys_can_request_speech(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])->assertUnauthorized();
        $this->withHeader('X-Api-Key', $this->key(['settings.read']))
            ->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])->assertForbidden();
        $this->getJson('/api/v1/voice/tts/status')->assertForbidden();
        $this->getJson('/api/v1/voice/voices')->assertForbidden();
    }

    public function test_v2_catalog_exposes_only_public_voice_fields_for_the_luczor_client(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn (...$args) => $args[2] === 'luczor' && $args[4] === null && $args[8] === '/v2/voices')
            ->andReturn($this->upstream(200, json_encode(['voices' => [
                ['id' => 'piper', 'name' => 'Piper Standard'],
                ['id' => 'benni', 'name' => 'Benni', 'reference_path' => '/private/voice.wav', 'clients' => ['luczor']],
            ]]), 'application/json'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $response = $this->withHeader('X-Api-Key', $this->key())->getJson('/api/v1/voice/voices')->assertOk();
        $response->assertExactJson(['voices' => [
            ['id' => 'piper', 'name' => 'Piper Standard', 'provider' => 'piper', 'language' => 'de'],
            ['id' => 'benni', 'name' => 'Benni', 'provider' => 'pocket', 'language' => 'de'],
        ]]);
        $this->assertStringNotContainsString('/private/', $response->getContent());
    }

    public function test_selected_voice_uses_v2_wav_without_switching_legacy_requests(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn (...$args) => $args[4] === ['text' => 'Hallo.', 'speed' => 1.0, 'voice_id' => 'benni', 'format' => 'wav'] && $args[8] === '/v2/speech')
            ->andReturn($this->upstream(200, $this->wav(), 'audio/wav'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.', 'voice_id' => 'benni'])
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');
    }

    public function test_missing_voice_fails_without_fallback_or_exposing_upstream_details(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->andReturn($this->upstream(404, 'private-service-token /private/reference', 'application/json'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $response = $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.', 'voice_id' => 'missing'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'tts_voice_unavailable');
        $this->assertStringNotContainsString('private-service-token', $response->getContent());
    }

    public function test_speech_uses_only_server_credentials_and_exact_shared_contract(): void
    {
        $audio = $this->wav();
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(function (...$args): bool {
            $this->assertSame('http://127.0.0.1:8092', $args[0]);
            $this->assertSame(self::TOKEN, $args[1]);
            $this->assertSame('luczor', $args[2]);
            $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $args[3]);
            $this->assertSame(['text' => 'Grüße aus Luczor.', 'speed' => 1.2], $args[4]);
            $this->assertSame([2, 150, 16777216], array_slice($args, 5));

            return true;
        })->andReturn($this->upstream(200, $audio, 'audio/wav'));
        $this->app->instance(SharedSpeechTransport::class, $transport);

        $response = $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', [
            'text' => 'Grüße aus Luczor.', 'language' => 'de-DE', 'speed' => 1.2,
        ])->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($audio, $response->getContent());
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_never_reaches_shared_service(array $payload): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'tts_invalid_input');
    }

    public static function invalidPayloads(): array
    {
        return [
            [['text' => '   ']], [['text' => str_repeat('ä', 4001)]], [['text' => ['nested']]],
            [['text' => 'Hallo', 'language' => 'en']], [['text' => 'Hallo', 'speed' => 0.49]],
            [['text' => 'Hallo', 'speed' => 2.01]], [['text' => 'Hallo', 'speed' => null]],
            [['text' => 'Hallo', 'url' => 'https://attacker.invalid']],
            [['text' => 'Hallo', 'token' => self::TOKEN]],
            [['text' => 'Hallo', 'voice_id' => '../secret']], [['text' => 'Hallo', 'voice_id' => ['benni']]],
            [['text' => 'Hallo', 'voice_id' => null]], [['text' => 'Hallo', 'voice_id' => str_repeat('a', 65)]],
        ];
    }

    public function test_v2_speed_must_be_applied_at_playback(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.', 'voice_id' => 'benni', 'speed' => 1.5])->assertUnprocessable();
    }

    #[DataProvider('invalidConfiguration')]
    public function test_unconfigured_or_non_loopback_service_fails_closed(array $settings): void
    {
        config($settings);
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])
            ->assertStatus(503)->assertJsonPath('error.code', 'tts_not_configured');
    }

    public static function invalidConfiguration(): array
    {
        return [
            [['shared_speech.enabled' => false]], [['shared_speech.token' => '']],
            [['shared_speech.url' => 'http://example.invalid:8092']],
            [['shared_speech.url' => 'http://127.0.0.1:8092@evil.invalid']],
            [['shared_speech.url' => 'http://127.0.0.1:8092/redirect']],
            [['shared_speech.url' => 'http://127.0.0.1:65536']],
            [['shared_speech.client_id' => "luczor\r\nX-Injected: header"]],
            [['shared_speech.token' => self::TOKEN."\r\nInjected: yes"]],
            [['shared_speech.token_file' => '/missing-luczor-speech-token']],
            [['shared_speech.token_file' => 'relative-secret-file']],
        ];
    }

    #[DataProvider('upstreamErrors')]
    public function test_upstream_errors_are_sanitized(int $upstreamStatus, int $expectedStatus, string $code): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->andReturn($this->upstream($upstreamStatus,
            'SECRET '.self::TOKEN.' text=vertraulich url=http://internal', 'application/json', 1));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $response = $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])
            ->assertStatus($expectedStatus)->assertJsonPath('error.code', $code);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('vertraulich', $response->getContent());
        if ($upstreamStatus === 503 || $upstreamStatus === 429) {
            $response->assertHeader('Retry-After', '1');
        }
    }

    public static function upstreamErrors(): array
    {
        return [[302, 502, 'tts_upstream_failed'], [401, 503, 'tts_service_auth_failed'],
            [403, 503, 'tts_service_auth_failed'], [429, 503, 'tts_unavailable'],
            [503, 503, 'tts_unavailable'], [504, 504, 'tts_timeout'], [500, 502, 'tts_upstream_failed']];
    }

    public function test_invalid_audio_is_not_returned_to_desktop(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->andReturn($this->upstream(200, '<html>not audio</html>', 'audio/wav'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])
            ->assertStatus(502)->assertJsonPath('error.code', 'tts_invalid_audio');
    }

    public function test_transport_timeout_remains_a_safe_504(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new SharedSpeechException('tts_timeout', 504, 'Zeitlimit erreicht.'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Hallo.'])
            ->assertStatus(504)->assertJsonPath('error.code', 'tts_timeout');
    }

    public function test_status_uses_only_piper_readiness_and_does_not_expose_raw_service_information(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn (...$args) => $args[4] === null && $args[6] === 5 && $args[7] === 65536)
            ->andReturn($this->upstream(200, json_encode(['status' => 'degraded',
                'engines' => ['piper' => 'ready', 'whisper' => 'unavailable'], 'token' => self::TOKEN]), 'application/json'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $response = $this->withHeader('X-Api-Key', $this->key())->getJson('/api/v1/voice/tts/status')
            ->assertOk()->assertJsonPath('configured', true)->assertJsonPath('ready', true)
            ->assertJsonPath('language', 'de')->assertJsonPath('max_text_chars', 4000);
        $this->assertStringNotContainsString('whisper', $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_rate_limit_is_shared_by_account_keys_but_not_by_other_accounts(): void
    {
        config(['shared_speech.requests_per_minute' => 1]);
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->twice()->andReturn($this->upstream(200, $this->wav(), 'audio/wav'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $account = User::factory()->create();
        $this->withHeader('X-Api-Key', $this->key(user: $account))->postJson('/api/v1/voice/tts', ['text' => 'Eins.'])->assertOk();
        $this->withHeader('X-Api-Key', $this->key(user: $account))->postJson('/api/v1/voice/tts', ['text' => 'Zwei.'])->assertTooManyRequests();
        $this->withHeader('X-Api-Key', $this->key())->postJson('/api/v1/voice/tts', ['text' => 'Drei.'])->assertOk();
    }

    public function test_diagnostic_smoke_validates_audio_without_saving_it(): void
    {
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn (...$args) => $args[4] === null)
            ->andReturn($this->upstream(200, '{"engines":{"piper":"ready"}}', 'application/json'));
        $transport->shouldReceive('send')->once()->withArgs(fn (...$args) => $args[4] === ['text' => 'Dies ist der Luczor Sprachtest.', 'speed' => 1.0])
            ->andReturn($this->upstream(200, $this->wav(), 'audio/wav'));
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->artisan('luczor:speech-service-status', ['--smoke' => true])->expectsOutput('Shared TTS: ready')->assertExitCode(0);
    }

    public function test_diagnostic_requires_real_configuration(): void
    {
        config(['shared_speech.enabled' => false]);
        $transport = Mockery::mock(SharedSpeechTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(SharedSpeechTransport::class, $transport);
        $this->artisan('luczor:speech-service-status', ['--smoke' => true])
            ->expectsOutput('Shared TTS: tts_not_configured')->assertExitCode(1);
    }

    private function key(array $abilities = ['proxy.use'], ?User $user = null): string
    {
        return ApiKey::mint(['user_id' => ($user ?? User::factory()->create())->id,
            'name' => 'Synthetic speech test', 'abilities' => $abilities, 'active' => true])['plain'];
    }

    private function upstream(int $status, string $body, string $contentType, ?int $retryAfter = null): array
    {
        return ['status' => $status, 'body' => $body, 'content_type' => $contentType, 'retry_after' => $retryAfter];
    }

    private function wav(): string
    {
        $pcm = str_repeat("\0", 320);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }
}
