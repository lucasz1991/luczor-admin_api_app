<?php

namespace App\Services\Proxy;

use App\Data\Proxy\PreparedProxyRequest;
use App\Data\Proxy\ProxyDispatchResult;
use App\Data\Proxy\ProxyResponseLimits;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Services\Llm\ProviderDriver;
use App\Services\LlmTelemetryService;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ProxyResponseFactory
{
    public function __construct(
        private LlmTelemetryService $telemetry,
        private BoundedBodyReader $bodyReader,
    ) {}

    public function make(
        PreparedProxyRequest $prepared,
        ProxyDispatchResult $dispatch,
        LlmRun $run,
        ProxyResponseLimits $limits,
    ): Response {
        $upstream = $dispatch->upstream;
        $attempt = $dispatch->attempt;
        $profile = $dispatch->profile;
        $credential = $dispatch->credential;
        $driver = $dispatch->driver;
        $startedAt = $dispatch->startedAt;
        $connectMs = $dispatch->connectMs;
        if (! $upstream || ! $attempt || ! $profile || ! $credential || ! $driver || $startedAt === null || $connectMs === null) {
            throw new LogicException('A successful proxy dispatch is incomplete.');
        }

        $headers = [
            'X-Luczor-Use-Case' => $prepared->useCase->slug ?? '',
            'X-Luczor-Model-Profile' => $profile->slug,
            'X-Luczor-Model-Id' => $profile->model_id,
            'X-Luczor-Provider' => $credential->provider ?: 'openrouter',
            'X-Luczor-Review-Enabled' => $prepared->useCase?->review_enabled ? '1' : '0',
        ];

        if ($upstream->getStatusCode() >= 400 || ! (bool) ($prepared->payload['stream'] ?? false)) {
            return $this->jsonResponse(
                $upstream,
                $driver,
                $attempt,
                $run,
                $startedAt,
                $connectMs,
                $headers,
                $limits,
            );
        }

        $declaredLength = $this->declaredLength($upstream);
        if ($declaredLength !== null && $declaredLength > $limits->streamBytes) {
            $upstream->getBody()->close();

            return $this->gatewayFailureJson(
                attempt: $attempt,
                run: $run,
                providerStatus: $upstream->getStatusCode(),
                startedAt: $startedAt,
                connectMs: $connectMs,
                headers: $headers,
                code: 'upstream_stream_too_large',
                message: 'Provider stream exceeded the gateway size limit.',
                limitBytes: $limits->streamBytes,
            );
        }

        return $this->streamResponse(
            $upstream,
            $driver,
            $attempt,
            $run,
            $startedAt,
            $connectMs,
            $headers,
            $limits,
        );
    }

    /** @param array<string,string> $headers */
    private function jsonResponse(
        ResponseInterface $upstream,
        ProviderDriver $driver,
        LlmAttempt $attempt,
        LlmRun $run,
        float $startedAt,
        int $connectMs,
        array $headers,
        ProxyResponseLimits $limits,
    ): Response {
        $status = $upstream->getStatusCode();

        if (in_array($status, [401, 403], true)) {
            $upstream->getBody()->close();
            $attempt = $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), [], [
                'generation_id' => $upstream->getHeaderLine('X-Generation-Id') ?: null,
                'connect_ms' => $connectMs,
                'error_type' => 'provider_auth_failed',
                'error_message' => 'Provider rejected the configured credential.',
            ]);
            $run = $this->telemetry->finishRun($run, $attempt);

            return response()->json([
                'message' => 'Provider authentication failed. Bitte den Provider-Key im Adminbereich prüfen oder neu speichern.',
                'code' => 'provider_auth_failed',
                'provider_status' => $status,
                'request_id' => $run->request_id,
            ], 502)->withHeaders($headers)->header('X-Luczor-Request-Id', $run->request_id);
        }

        $declaredLength = $this->declaredLength($upstream);
        if ($declaredLength !== null && $declaredLength > $limits->bodyBytes) {
            $upstream->getBody()->close();

            return $this->oversizedJsonResponse($attempt, $run, $status, $startedAt, $connectMs, $headers, $limits->bodyBytes);
        }

        $read = $this->bodyReader->read($upstream->getBody(), $limits->bodyBytes);
        if ($read->limitExceeded) {
            return $this->oversizedJsonResponse($attempt, $run, $status, $startedAt, $connectMs, $headers, $limits->bodyBytes);
        }
        if ($read->readFailed) {
            return $this->gatewayFailureJson(
                attempt: $attempt,
                run: $run,
                providerStatus: $status,
                startedAt: $startedAt,
                connectMs: $connectMs,
                headers: $headers,
                code: 'upstream_response_read_failed',
                message: 'Provider response could not be read safely.',
            );
        }

        $json = json_decode($read->contents, true) ?: [];
        $normalize = $status < 400 && ! $driver->passthrough();
        $canonical = $normalize ? $driver->normalizeResponse($json) : $json;
        $usage = is_array($canonical['usage'] ?? null) ? $canonical['usage'] : [];
        $attempt = $this->telemetry->finishAttempt($attempt, $status, $this->elapsedMs($startedAt), $usage, [
            'generation_id' => $canonical['id'] ?? ($upstream->getHeaderLine('X-Generation-Id') ?: null),
            'connect_ms' => $connectMs,
            'finish_reason' => $canonical['choices'][0]['finish_reason'] ?? null,
            'response_hash' => $read->sha256,
        ]);
        $run = $this->telemetry->finishRun($run, $attempt);
        $contents = $normalize ? json_encode($canonical, JSON_UNESCAPED_UNICODE) : $read->contents;

        return response($contents, $status)
            ->header('Content-Type', 'application/json')
            ->withHeaders($headers)
            ->header('X-Luczor-Request-Id', $run->request_id);
    }

    /** @param array<string,string> $headers */
    private function oversizedJsonResponse(
        LlmAttempt $attempt,
        LlmRun $run,
        int $providerStatus,
        float $startedAt,
        int $connectMs,
        array $headers,
        int $limitBytes,
    ): Response {
        return $this->gatewayFailureJson(
            attempt: $attempt,
            run: $run,
            providerStatus: $providerStatus,
            startedAt: $startedAt,
            connectMs: $connectMs,
            headers: $headers,
            code: 'upstream_response_too_large',
            message: 'Provider response exceeded the gateway size limit.',
            limitBytes: $limitBytes,
        );
    }

    /** @param array<string,string> $headers */
    private function gatewayFailureJson(
        LlmAttempt $attempt,
        LlmRun $run,
        int $providerStatus,
        float $startedAt,
        int $connectMs,
        array $headers,
        string $code,
        string $message,
        ?int $limitBytes = null,
    ): Response {
        $attempt = $this->telemetry->finishAttempt($attempt, $providerStatus, $this->elapsedMs($startedAt), [], [
            'connect_ms' => $connectMs,
            'error_type' => $code,
            'error_message' => $message,
        ]);
        $run = $this->telemetry->finishRun($run, $attempt);
        $payload = [
            'message' => $message,
            'code' => $code,
            'provider_status' => $providerStatus,
            'request_id' => $run->request_id,
        ];
        if ($limitBytes !== null) {
            $payload['limit_bytes'] = $limitBytes;
        }

        return response()->json($payload, 502)
            ->withHeaders($headers)
            ->header('X-Luczor-Request-Id', $run->request_id);
    }

    /** @param array<string,string> $headers */
    private function streamResponse(
        ResponseInterface $upstream,
        ProviderDriver $driver,
        LlmAttempt $attempt,
        LlmRun $run,
        float $startedAt,
        int $connectMs,
        array $headers,
        ProxyResponseLimits $limits,
    ): StreamedResponse {
        $body = $upstream->getBody();
        $status = $upstream->getStatusCode();
        $headerGenerationId = $upstream->getHeaderLine('X-Generation-Id') ?: null;

        return new StreamedResponse(function () use ($body, $status, $driver, $attempt, $run, $headerGenerationId, $startedAt, $connectMs, $limits): void {
            $usage = [];
            $finishReason = null;
            $generationId = $headerGenerationId;
            $ttftMs = null;
            $emittedBytes = 0;
            $hash = hash_init('sha256');
            $pendingDone = null;

            $absorb = function (string $frame) use (&$usage, &$finishReason, &$generationId, &$ttftMs, $startedAt): void {
                $lines = preg_split('/\r?\n/', trim($frame)) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $event = json_decode($data, true);
                    if (! is_array($event)) {
                        continue;
                    }
                    if ($generationId === null && is_string($event['id'] ?? null) && $event['id'] !== '') {
                        $generationId = $event['id'];
                    }
                    if (is_array($event['usage'] ?? null)) {
                        $usage = $event['usage'];
                    }
                    $finishReason = $event['choices'][0]['finish_reason'] ?? $finishReason;
                    $content = $event['choices'][0]['delta']['content'] ?? null;
                    if ($ttftMs === null && is_string($content) && $content !== '') {
                        $ttftMs = $this->elapsedMs($startedAt);
                    }
                }
            };

            $write = function (string $frame, bool $canonical = false) use (&$emittedBytes, $hash, $absorb, $limits): ?string {
                $wire = $canonical ? $frame."\n\n" : $frame;
                if (strlen($wire) > $limits->streamFrameBytes) {
                    return 'upstream_stream_frame_too_large';
                }
                if ($emittedBytes + strlen($wire) > $limits->streamBytes) {
                    return 'upstream_stream_too_large';
                }

                echo $wire;
                @ob_flush();
                @flush();
                $emittedBytes += strlen($wire);
                hash_update($hash, $wire);
                $absorb($frame);

                return null;
            };
            $emit = function (string $frame, bool $canonical = false) use (&$pendingDone, $write): ?string {
                if ($this->isDoneFrame($frame)) {
                    $pendingDone = ['frame' => $frame, 'canonical' => $canonical];

                    return null;
                }

                return $write($frame, $canonical);
            };

            if ($driver->passthrough()) {
                $limitError = $this->consumeStreamFrames($body, $limits, fn (string $frame): ?string => $emit($frame));
            } else {
                $adapter = $driver->streamAdapter();
                $limitError = $this->consumeStreamFrames(
                    $body,
                    $limits,
                    function (string $rawFrame) use ($adapter, $emit): ?string {
                        $lines = preg_split('/\r?\n/', trim($rawFrame)) ?: [];
                        foreach ($lines as $line) {
                            foreach ($adapter->feed($line) as $canonicalFrame) {
                                $error = $emit($canonicalFrame, true);
                                if ($error !== null) {
                                    return $error;
                                }
                            }
                        }

                        return null;
                    },
                );
                if ($limitError === null) {
                    foreach ($adapter->finish() as $canonicalFrame) {
                        $limitError = $emit($canonicalFrame, true);
                        if ($limitError !== null) {
                            break;
                        }
                    }
                }
            }

            if ($limitError === null && is_array($pendingDone)) {
                $limitError = $write($pendingDone['frame'], $pendingDone['canonical']);
            }

            if ($limitError !== null) {
                $limitMessage = $limitError === 'upstream_stream_read_failed'
                    ? 'Provider stream could not be read safely.'
                    : 'Provider stream exceeded the gateway size limit.';
                $errorFrame = 'data: '.json_encode([
                    'error' => [
                        'message' => $limitMessage,
                        'code' => $limitError,
                        'status' => 502,
                        'request_id' => $run->request_id,
                    ],
                    'request_id' => $run->request_id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\ndata: [DONE]\n\n";
                echo $errorFrame;
                @ob_flush();
                @flush();
                hash_update($hash, $errorFrame);
            }

            $attemptMeta = [
                'generation_id' => $generationId,
                'connect_ms' => $connectMs,
                'finish_reason' => $finishReason,
                'ttft_ms' => $ttftMs,
                'response_hash' => hash_final($hash),
            ];
            if ($limitError !== null) {
                $attemptMeta['error_type'] = $limitError;
                $attemptMeta['error_message'] = $limitError === 'upstream_stream_read_failed'
                    ? 'Provider stream could not be read safely.'
                    : 'Provider stream exceeded the gateway size limit.';
            }
            $attempt = $this->telemetry->finishAttempt(
                $attempt,
                $status,
                $this->elapsedMs($startedAt),
                $usage,
                $attemptMeta,
            );
            $this->telemetry->finishRun($run, $attempt);
        }, $status, array_merge($headers, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Luczor-Request-Id' => $run->request_id,
        ]));
    }

    /**
     * @param  callable(string): (?string)  $consume
     */
    private function consumeStreamFrames(
        StreamInterface $body,
        ProxyResponseLimits $limits,
        callable $consume,
    ): ?string {
        $buffer = '';
        $readBytes = 0;

        while (true) {
            $remaining = $limits->streamBytes - $readBytes;
            try {
                $chunk = $body->read(min(8192, $remaining + 1));
            } catch (Throwable) {
                $body->close();

                return 'upstream_stream_read_failed';
            }
            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                $body->close();

                return 'upstream_stream_read_failed';
            }

            $overflow = strlen($chunk) > $remaining;
            if ($overflow) {
                $chunk = $remaining > 0 ? substr($chunk, 0, $remaining) : '';
            }
            $readBytes += strlen($chunk);
            $buffer .= $chunk;

            foreach ($this->extractCompleteFrames($buffer) as $frame) {
                if (strlen($frame) > $limits->streamFrameBytes) {
                    $body->close();

                    return 'upstream_stream_frame_too_large';
                }
                $error = $consume($frame);
                if ($error !== null) {
                    $body->close();

                    return $error;
                }
            }
            if (strlen($buffer) > $limits->streamFrameBytes) {
                $body->close();

                return 'upstream_stream_frame_too_large';
            }
            if ($overflow) {
                $body->close();

                return 'upstream_stream_too_large';
            }
            if ($body->eof()) {
                break;
            }
        }

        if ($buffer === '') {
            $body->close();

            return null;
        }
        if (strlen($buffer) > $limits->streamFrameBytes) {
            $body->close();

            return 'upstream_stream_frame_too_large';
        }

        $error = $consume($buffer);
        $body->close();

        return $error;
    }

    /** @return array<int,string> */
    private function extractCompleteFrames(string &$buffer): array
    {
        $frames = [];
        while (preg_match('/\r?\n\r?\n/', $buffer, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $delimiter = $matches[0][0];
            $offset = $matches[0][1];
            $length = $offset + strlen($delimiter);
            $frames[] = substr($buffer, 0, $length);
            $buffer = substr($buffer, $length);
        }

        return $frames;
    }

    private function isDoneFrame(string $frame): bool
    {
        $data = [];
        foreach (preg_split('/\r?\n/', trim($frame)) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $data[] = trim(substr($line, 5));
            }
        }

        return $data === ['[DONE]'];
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function declaredLength(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Content-Length'));

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
