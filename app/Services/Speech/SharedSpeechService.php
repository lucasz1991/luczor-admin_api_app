<?php

namespace App\Services\Speech;

use Illuminate\Support\Str;

class SharedSpeechService
{
    public const MAX_TEXT_CHARACTERS = 4000;

    public const MAX_AUDIO_BYTES = 16 * 1024 * 1024;

    public function __construct(private readonly SharedSpeechTransport $transport) {}

    public function synthesize(string $text, float $speed = 1.0): string
    {
        if (trim($text) === '' || mb_strlen($text) > self::MAX_TEXT_CHARACTERS || ! is_finite($speed) || $speed < 0.5 || $speed > 2.0) {
            throw new SharedSpeechException('tts_invalid_input', 422, 'Ungültiger Vorlesetext oder ungültige Sprechgeschwindigkeit.');
        }

        $response = $this->request(['text' => $text, 'speed' => $speed]);
        $this->assertSuccess($response);
        $audio = $response['body'];
        if (! in_array($response['content_type'], ['audio/wav', 'audio/x-wav', 'audio/wave'], true)
            || strlen($audio) < 44 || strlen($audio) > self::MAX_AUDIO_BYTES
            || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
            throw new SharedSpeechException('tts_invalid_audio', 502, 'Der Server-Sprachdienst hat keine gültige WAV-Ausgabe geliefert.');
        }

        return $audio;
    }

    /** @return array{configured: bool, ready: bool, status: string, language: string, max_text_chars: int, max_audio_bytes: int} */
    public function status(): array
    {
        $result = [
            'configured' => false,
            'ready' => false,
            'status' => 'tts_not_configured',
            'language' => 'de',
            'max_text_chars' => self::MAX_TEXT_CHARACTERS,
            'max_audio_bytes' => self::MAX_AUDIO_BYTES,
        ];

        try {
            $this->configuration();
            $result['configured'] = true;
            $response = $this->request(null);
            $this->assertSuccess($response);
            $data = json_decode($response['body'], true);
            if ($response['content_type'] !== 'application/json' || ! is_array($data) || ! is_array($data['engines'] ?? null)
                || ! in_array($data['engines']['piper'] ?? null, ['ready', 'unavailable'], true)) {
                throw new SharedSpeechException('tts_invalid_status', 502, 'Der Server-Sprachdienst hat keinen gültigen Status geliefert.');
            }
            // Whisper/ffmpeg may be degraded independently; only TTS readiness matters here.
            $result['ready'] = $data['engines']['piper'] === 'ready';
            $result['status'] = $result['ready'] ? 'ready' : 'tts_unavailable';
        } catch (SharedSpeechException $exception) {
            $result['status'] = $exception->errorCode;
        }

        return $result;
    }

    /** @return array{url: string, token: string, client_id: string} */
    private function configuration(): array
    {
        $url = rtrim((string) config('shared_speech.url'), '/');
        $clientId = (string) config('shared_speech.client_id');
        $tokenFile = (string) config('shared_speech.token_file');
        $token = (string) config('shared_speech.token');
        if ($tokenFile !== '') {
            // A configured file is authoritative, including when it is missing/unreadable.
            $absolute = str_starts_with($tokenFile, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $tokenFile) === 1;
            $token = $absolute && is_file($tokenFile) && is_readable($tokenFile)
                ? trim((string) file_get_contents($tokenFile, false, null, 0, 4097)) : '';
        }
        if (! config('shared_speech.enabled') || ! extension_loaded('curl')
            || preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $url, $port) !== 1
            || (int) $port[1] > 65535
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $clientId) !== 1
            || strlen($token) < 32 || strlen($token) > 4096 || preg_match('/[^\x21-\x7E]/', $token)) {
            throw new SharedSpeechException('tts_not_configured', 503, 'Der Server-Sprachdienst ist nicht eingerichtet.');
        }

        return ['url' => $url, 'token' => $token, 'client_id' => $clientId];
    }

    /**
     * @param  array{text: string, speed: float}|null  $payload
     * @return array{status: int, body: string, content_type: string, retry_after: ?int}
     */
    private function request(?array $payload): array
    {
        $config = $this->configuration();

        return $this->transport->send(
            $config['url'], $config['token'], $config['client_id'], (string) Str::uuid(), $payload,
            max(1, min(5, (int) config('shared_speech.connect_timeout_seconds', 2))),
            $payload === null ? max(1, min(10, (int) config('shared_speech.status_timeout_seconds', 5)))
                : max(1, min(150, (int) config('shared_speech.timeout_seconds', 150))),
            $payload === null ? 64 * 1024 : self::MAX_AUDIO_BYTES,
        );
    }

    /** @param array{status: int, body: string, content_type: string, retry_after: ?int} $response */
    private function assertSuccess(array $response): void
    {
        if ($response['status'] === 200) {
            return;
        }
        if (in_array($response['status'], [408, 504], true)) {
            throw new SharedSpeechException('tts_timeout', 504, 'Der Server-Sprachdienst hat nicht rechtzeitig geantwortet.');
        }
        if (in_array($response['status'], [429, 503], true)) {
            throw new SharedSpeechException('tts_unavailable', 503, 'Der Server-Sprachdienst ist momentan nicht verfügbar.', $response['retry_after']);
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new SharedSpeechException('tts_service_auth_failed', 503, 'Die Server-Anmeldung am Sprachdienst muss eingerichtet werden.');
        }

        // Never forward upstream bodies, internal addresses, redirects or headers.
        throw new SharedSpeechException('tts_upstream_failed', 502, 'Der Server-Sprachdienst konnte die Ausgabe nicht erstellen.');
    }
}
