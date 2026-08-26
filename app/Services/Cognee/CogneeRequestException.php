<?php

namespace App\Services\Cognee;

use RuntimeException;
use Throwable;

/** Preserve the HTTP contract of a failed Cognee request for safe recovery. */
class CogneeRequestException extends RuntimeException
{
    /** @param array<mixed> $response */
    public function __construct(
        private readonly string $requestPath,
        private readonly int $statusCode,
        private readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Cognee request {$requestPath} failed with HTTP {$statusCode}.",
            $statusCode,
            $previous,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<mixed> */
    public function response(): array
    {
        return $this->response;
    }

    /** These client rejections prove that no background task was accepted. */
    public function isDeterministicLaunchRejection(): bool
    {
        return in_array($this->statusCode, [400, 401, 403, 404, 405, 413, 415, 422], true);
    }

    /** Content/validation failures cannot be repaired by credentials or routing. */
    public function isPermanentAddRejection(): bool
    {
        return in_array($this->statusCode, [400, 413, 422], true);
    }

    /** Cognee 1.4 uses 420 for a synchronously reported terminal Improve run. */
    public function isTerminalImproveFailure(): bool
    {
        return $this->statusCode === 420;
    }
}
