<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class RoutingPolicyException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct('The external routing policy rejected this request.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'External routing is unavailable under the active policy.',
            'code' => $this->reasonCode,
        ], $this->httpStatus);
    }
}
