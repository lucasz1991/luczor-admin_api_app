<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A compare-and-swap conflict with the exact current canonical version. */
class MemoryVersionConflictException extends ConflictHttpException
{
    public function __construct(private readonly ?int $currentMemoryId)
    {
        parent::__construct('The active memory version changed before this write could be applied.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_memory_id' => $this->currentMemoryId,
            'current_memory_link_id' => $this->currentMemoryId,
            'source_record_id' => $this->currentMemoryId,
        ], 409);
    }
}
