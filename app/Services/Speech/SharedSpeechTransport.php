<?php

namespace App\Services\Speech;

/** Memory-only, bounded transport. cURL's total timeout includes the entire body. */
class SharedSpeechTransport
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{status: int, body: string, content_type: string, retry_after: ?int}
     */
    public function send(
        string $baseUrl,
        #[\SensitiveParameter] string $token,
        string $clientId,
        string $requestId,
        ?array $payload,
        int $connectTimeout,
        int $timeout,
        int $maxBytes,
        ?string $path = null,
    ): array {
        $path ??= $payload === null ? '/v1/status' : '/v1/speech';
        if (! in_array($path, ['/v1/status', '/v1/speech', '/v2/voices', '/v2/speech'], true)) {
            throw new SharedSpeechException('tts_invalid_input', 422, 'Ungültiger Sprachdienst-Endpunkt.');
        }
        if (! extension_loaded('curl')) {
            throw new SharedSpeechException('tts_not_configured', 503, 'Der Server-Sprachdienst ist nicht eingerichtet.');
        }

        $handle = curl_init($baseUrl.$path);
        if ($handle === false) {
            throw new SharedSpeechException('tts_unavailable', 503, 'Der Server-Sprachdienst ist nicht erreichbar.');
        }

        $body = '';
        $contentType = '';
        $retryAfter = null;
        $tooLarge = false;
        $headers = [
            'Authorization: Bearer '.$token,
            'X-Client-ID: '.$clientId,
            'X-Request-ID: '.$requestId,
            'Accept: '.($payload === null ? 'application/json' : 'audio/wav'),
            'Accept-Encoding: identity',
            'Expect:',
        ];

        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                // The loopback credential must never reach an environment HTTP proxy.
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$contentType, &$retryAfter, &$tooLarge, $maxBytes): int {
                    if (str_starts_with(strtolower($line), 'content-length:')) {
                        $length = trim(substr($line, 15));
                        if (ctype_digit($length) && (float) $length > $maxBytes) {
                            $tooLarge = true;

                            return 0;
                        }
                    } elseif (str_starts_with(strtolower($line), 'content-type:')) {
                        $contentType = strtolower(trim(explode(';', substr($line, 13), 2)[0]));
                    } elseif (str_starts_with(strtolower($line), 'retry-after:')) {
                        $value = trim(substr($line, 12));
                        $retryAfter = ctype_digit($value) ? min(30, max(1, (int) $value)) : null;
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;

                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt_array($handle, [
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            }

            $result = curl_exec($handle);
            if ($tooLarge) {
                throw new SharedSpeechException('tts_invalid_audio', 502, 'Die Antwort des Server-Sprachdienstes überschreitet das Audiolimit.');
            }
            if ($result === false) {
                if (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT) {
                    throw new SharedSpeechException('tts_timeout', 504, 'Der Server-Sprachdienst hat nicht rechtzeitig geantwortet.');
                }

                throw new SharedSpeechException('tts_unavailable', 503, 'Der Server-Sprachdienst ist nicht erreichbar.');
            }

            return [
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => $body,
                'content_type' => $contentType,
                'retry_after' => $retryAfter,
            ];
        } finally {
            curl_close($handle);
        }
    }
}
