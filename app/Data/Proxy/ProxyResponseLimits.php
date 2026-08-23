<?php

namespace App\Data\Proxy;

final readonly class ProxyResponseLimits
{
    private const DEFAULT_BODY_BYTES = 16 * 1024 * 1024;

    private const DEFAULT_STREAM_BYTES = 64 * 1024 * 1024;

    private const DEFAULT_STREAM_FRAME_BYTES = 1024 * 1024;

    public function __construct(
        public int $bodyBytes,
        public int $streamBytes,
        public int $streamFrameBytes,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            bodyBytes: max(1, (int) config('luczor.proxy.max_response_bytes', self::DEFAULT_BODY_BYTES)),
            streamBytes: max(1, (int) config('luczor.proxy.max_stream_bytes', self::DEFAULT_STREAM_BYTES)),
            streamFrameBytes: max(1, (int) config('luczor.proxy.max_stream_frame_bytes', self::DEFAULT_STREAM_FRAME_BYTES)),
        );
    }
}
