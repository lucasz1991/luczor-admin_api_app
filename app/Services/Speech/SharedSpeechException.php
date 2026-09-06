<?php

namespace App\Services\Speech;

use RuntimeException;

/** Only public, fixed messages belong here; never attach a transport exception. */
class SharedSpeechException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
