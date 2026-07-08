<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderCredential;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provider proxy: the desktop client sends chat requests here (authenticated
 * with its device key) and the server injects the encrypted OpenRouter key and
 * forwards the request. This keeps provider keys off the client device.
 *
 * Streaming (SSE) responses are passed through chunk-by-chunk.
 */
class ProxyController extends Controller
{
    public function chat(Request $request)
    {
        $cred = ProviderCredential::query()
            ->where('provider', 'openrouter')
            ->where('active', true)
            ->latest()
            ->first();

        if (! $cred || ! $cred->api_key) {
            return response()->json(
                ['message' => 'Kein aktiver OpenRouter-Provider im Server konfiguriert.'],
                400
            );
        }

        $base = rtrim($cred->base_url ?: 'https://openrouter.ai/api/v1', '/');
        $url = $base.'/chat/completions';
        $payload = $request->all();
        $stream = (bool) ($payload['stream'] ?? false);

        $client = new Client(['timeout' => 0]);

        $upstream = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer '.$cred->api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => 'https://luczor.local',
                'X-Title' => 'Luczor',
                'Accept' => $stream ? 'text/event-stream' : 'application/json',
            ],
            'json' => $payload,
            'stream' => $stream,
            'http_errors' => false,
        ]);

        $status = $upstream->getStatusCode();

        if (! $stream) {
            return response($upstream->getBody()->getContents(), $status)
                ->header('Content-Type', 'application/json');
        }

        $body = $upstream->getBody();

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                @ob_flush();
                @flush();
            }
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function elevenCredential(): ?ProviderCredential
    {
        return ProviderCredential::query()
            ->where('provider', 'elevenlabs')
            ->where('active', true)
            ->latest()
            ->first();
    }

    public function elevenTts(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string'],
            'voice_id' => ['required', 'string'],
            'model_id' => ['nullable', 'string'],
            'output_format' => ['nullable', 'string'],
        ]);

        $cred = $this->elevenCredential();
        if (! $cred || ! $cred->api_key) {
            return response()->json(['message' => 'Kein aktiver ElevenLabs-Provider im Server konfiguriert.'], 400);
        }

        $model = $data['model_id'] ?? 'eleven_multilingual_v2';
        $fmt = $data['output_format'] ?? 'mp3_44100_128';
        $base = rtrim($cred->base_url ?: 'https://api.elevenlabs.io', '/');
        $url = $base.'/v1/text-to-speech/'.$data['voice_id'].'?output_format='.$fmt;

        $resp = (new Client(['timeout' => 60]))->post($url, [
            'headers' => ['xi-api-key' => $cred->api_key, 'Content-Type' => 'application/json'],
            'json' => ['text' => $data['text'], 'model_id' => $model],
            'http_errors' => false,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return response()->json(['message' => 'ElevenLabs TTS Fehler', 'detail' => (string) $resp->getBody()], $resp->getStatusCode());
        }

        $mime = str_starts_with($fmt, 'pcm_') ? 'audio/wav' : (str_starts_with($fmt, 'opus_') ? 'audio/ogg' : 'audio/mpeg');

        return response()->json(['base64' => base64_encode((string) $resp->getBody()), 'mime' => $mime]);
    }

    public function elevenStt(Request $request)
    {
        $data = $request->validate([
            'base64' => ['required', 'string'],
            'mime' => ['nullable', 'string'],
            'model_id' => ['nullable', 'string'],
            'language_code' => ['nullable', 'string'],
        ]);

        $cred = $this->elevenCredential();
        if (! $cred || ! $cred->api_key) {
            return response()->json(['message' => 'Kein aktiver ElevenLabs-Provider im Server konfiguriert.'], 400);
        }

        $b64 = $data['base64'];
        if (str_contains($b64, ',')) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        $bytes = base64_decode($b64) ?: '';
        $mime = $data['mime'] ?? 'audio/wav';
        $ext = str_contains($mime, 'wav') ? 'wav' : (str_contains($mime, 'mp3') ? 'mp3' : (str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'ogg') ? 'ogg' : 'bin')));

        $multipart = [
            ['name' => 'file', 'contents' => $bytes, 'filename' => 'audio.'.$ext],
            ['name' => 'model_id', 'contents' => $data['model_id'] ?? 'scribe_v2'],
        ];
        if (! empty($data['language_code'])) {
            $multipart[] = ['name' => 'language_code', 'contents' => $data['language_code']];
        }

        $base = rtrim($cred->base_url ?: 'https://api.elevenlabs.io', '/');
        $resp = (new Client(['timeout' => 120]))->post($base.'/v1/speech-to-text', [
            'headers' => ['xi-api-key' => $cred->api_key],
            'multipart' => $multipart,
            'http_errors' => false,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return response()->json(['message' => 'ElevenLabs STT Fehler', 'detail' => (string) $resp->getBody()], $resp->getStatusCode());
        }

        $json = json_decode((string) $resp->getBody(), true) ?: [];

        return response()->json(['text' => $json['text'] ?? '', 'language_code' => $json['language_code'] ?? null]);
    }
}
