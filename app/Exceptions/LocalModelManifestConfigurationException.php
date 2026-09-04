<?php

namespace App\Exceptions;

use RuntimeException;

final class LocalModelManifestConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('The local-model manifest is not safely configured.');
    }
}
