<?php

namespace App\Exceptions;

use App\Models\MemoryLink;
use App\Services\MemoryDlp;
use App\Services\MemorySyncService;
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
        $conflict = null;
        $current = MemoryLink::query()->whereKey($this->currentMemoryId)
            ->where('user_id', $request->user()?->id)->first();
        $base = MemoryLink::query()->whereKey($request->input('expected_previous_id'))
            ->where('user_id', $request->user()?->id)->first();
        if ($current && $base && $current->dataset === $base->dataset
            && $current->logicalExternalId() === $base->logicalExternalId()
            && $current->content_hash === $base->content_hash
            && app(MemorySyncService::class)->payload($current) !== null
            && ! MemoryDlp::containsSecretInMemoryPayload(['meta' => $base->meta])
            && ! MemoryDlp::containsLocalOnlySourceInMemoryPayload(['meta' => $base->meta])) {
            $before = $base->meta['memory_metadata'] ?? [];
            $now = $current->meta['memory_metadata'] ?? [];
            $incoming = $request->input('meta.memory_metadata');
            $fields = array_unique([...array_keys($before), ...array_keys($now), ...array_keys(is_array($incoming) ? $incoming : [])]);
            $conflicting = array_values(array_filter($fields, fn ($field) => ($before[$field] ?? null) !== ($now[$field] ?? null)
                && ($before[$field] ?? null) !== ($incoming[$field] ?? null) && ($now[$field] ?? null) !== ($incoming[$field] ?? null)));
            $safe = is_array($incoming) && $conflicting === [] && $request->input('content') === $base->summary
                && ($base->meta['tags'] ?? []) === ($current->meta['tags'] ?? []) && $base->importance === $current->importance;
            $conflict = ['base_version_id' => $base->id, 'current_version_id' => $current->id,
                'base_metadata' => $base->meta['memory_metadata'] ?? null,
                'current_metadata' => $current->meta['memory_metadata'] ?? null,
                'current_memory' => app(MemorySyncService::class)->payload($current),
                'base_tags' => $base->meta['tags'] ?? [], 'current_tags' => $current->meta['tags'] ?? [],
                'base_importance' => (float) $base->importance, 'current_importance' => (float) $current->importance,
                'conflicting_fields' => $conflicting, 'can_rebase' => $safe];
        }

        return response()->json([
            'message' => $this->getMessage(),
            'current_memory_id' => $this->currentMemoryId,
            'current_memory_link_id' => $this->currentMemoryId,
            'source_record_id' => $this->currentMemoryId,
            'metadata_conflict' => $conflict,
        ], 409);
    }
}
