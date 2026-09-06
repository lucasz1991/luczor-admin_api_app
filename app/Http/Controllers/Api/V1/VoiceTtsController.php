<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Speech\SharedSpeechException;
use App\Services\Speech\SharedSpeechService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VoiceTtsController extends Controller
{
    public function synthesize(Request $request, SharedSpeechService $speech): Response
    {
        if (! $request->isJson()) {
            return $this->error('tts_invalid_input', 'Sprachausgabe benötigt eine JSON-Anfrage.', 415);
        }
        if (strlen($request->getContent()) > 32 * 1024) {
            return $this->error('tts_invalid_input', 'Die Sprachanfrage ist zu groß.', 413);
        }
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), ['text', 'language', 'speed', 'voice_id']) !== []) {
            return $this->error('tts_invalid_input', 'Die Sprachanfrage enthält unbekannte Felder.', 422);
        }
        $validator = Validator::make($payload, [
            'text' => ['required', 'string', 'max:'.SharedSpeechService::MAX_TEXT_CHARACTERS],
            'language' => ['sometimes', 'string', Rule::in(['de', 'de-DE'])],
            'speed' => ['sometimes', 'numeric', 'between:0.5,2'],
            'voice_id' => ['sometimes', 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D'],
        ]);
        if ($validator->fails()) {
            return $this->error('tts_invalid_input', 'Erlaubt sind bis zu 4000 Zeichen, Deutsch und eine Sprechgeschwindigkeit von 0,5 bis 2.', 422);
        }

        $input = $validator->validated();
        try {
            $audio = $speech->synthesize($input['text'], (float) ($input['speed'] ?? 1.0), $input['voice_id'] ?? null);

            return response($audio, 200, [
                'Content-Type' => 'audio/wav',
                'Content-Length' => (string) strlen($audio),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (SharedSpeechException $exception) {
            $response = $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
            if ($exception->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfter);
            }

            return $response;
        }
    }

    public function status(SharedSpeechService $speech): JsonResponse
    {
        return response()->json($speech->status(), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function voices(SharedSpeechService $speech): JsonResponse
    {
        try {
            return response()->json($speech->voices(), 200, ['Cache-Control' => 'private, no-store']);
        } catch (SharedSpeechException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message], 'message' => $message], $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
