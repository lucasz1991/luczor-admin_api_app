<?php

namespace App\Services;

use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Services\Cognee\CogneeClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Keeps the rebuildable Cognee projection aligned with SQL validity windows. */
class MemoryProjectionReconciler
{
    public function __construct(private CogneeClient $cognee) {}

    /** @return array{upserts:int,deletes:int} */
    public function reconcile(int $limit = 250): array
    {
        if (! $this->cognee->enabled()) {
            return ['upserts' => 0, 'deletes' => 0];
        }

        $limit = max(1, min(1000, $limit));
        $now = now();
        $deletes = 0;
        $upserts = 0;

        $deleteCandidates = MemoryLink::query()
            ->whereNotNull('cognee_memory_id')
            ->orderBy('id')
            ->lazyById(250);

        foreach ($deleteCandidates as $candidate) {
            if ($deletes >= $limit) {
                break;
            }
            if (MemoryProjectionPolicy::isEligible($candidate, $now)) {
                continue;
            }
            $deletes += $this->enqueueDelete((int) $candidate->id, $now) ? 1 : 0;
        }

        $remaining = $limit - $deletes;
        if ($remaining < 1) {
            return compact('upserts', 'deletes');
        }

        $upsertIds = MemoryLink::query()
            ->where('status', 'active')
            ->where('retention', '!=', 'session')
            ->whereNull('cognee_memory_id')
            ->where('projection_status', 'deferred')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderBy('id')
            ->limit($remaining)
            ->pluck('id');

        foreach ($upsertIds as $linkId) {
            $upserts += $this->enqueueUpsert((int) $linkId, $now) ? 1 : 0;
        }

        return compact('upserts', 'deletes');
    }

    private function enqueueDelete(int $linkId, \DateTimeInterface $now): bool
    {
        return DB::transaction(function () use ($linkId, $now) {
            $link = MemoryLink::query()->whereKey($linkId)->lockForUpdate()->first();
            if (! $link || ! $link->cognee_memory_id) {
                return false;
            }
            $mustDelete = ! MemoryProjectionPolicy::isEligible($link, $now);
            if (! $mustDelete) {
                return false;
            }

            $dedupe = hash('sha256', implode('|', [
                'delete',
                $link->dataset,
                $link->id,
                $link->cognee_memory_id,
            ]));
            $outbox = MemoryProjectionOutbox::query()->firstOrCreate(['dedupe_key' => $dedupe], [
                'memory_link_id' => $link->id,
                'user_id' => $link->user_id,
                'action' => 'delete',
                'dataset' => $link->dataset,
                'payload' => [
                    'cognee_memory_id' => $link->cognee_memory_id,
                    'content_hash' => $link->content_hash,
                    'final_projection_status' => $link->projection_status === 'legacy_review_required'
                        ? 'legacy_review_required'
                        : 'not_required',
                ],
                'status' => 'pending',
            ]);

            if (! $outbox->wasRecentlyCreated && ! in_array($outbox->status, ['failed'], true)) {
                return false;
            }
            if ($outbox->status === 'failed') {
                $outbox->update(['status' => 'pending', 'next_attempt_at' => null, 'last_error' => null]);
            }
            $link->update(['projection_status' => 'delete_pending']);

            return true;
        });
    }

    private function enqueueUpsert(int $linkId, \DateTimeInterface $now): bool
    {
        return DB::transaction(function () use ($linkId, $now) {
            $link = MemoryLink::query()->whereKey($linkId)->lockForUpdate()->first();
            if (! $link
                || $link->status !== 'active'
                || $link->retention === 'session'
                || $link->cognee_memory_id
                || $link->projection_status !== 'deferred'
                || $this->outsideProjectionWindow($link, $now)) {
                return false;
            }

            if (! MemoryProjectionPolicy::passesContentPolicy($link)) {
                $link->update(['projection_status' => 'not_required']);

                return false;
            }

            // This generation intentionally differs from the initial write's
            // content-hash outbox entry, which may already be done after the
            // worker observed a future valid_from value.
            $generation = implode('|', [
                'validity-activation-v1',
                (string) $link->content_hash,
                $link->valid_from?->toIso8601String() ?? 'immediate',
            ]);
            $dedupe = hash('sha256', implode('|', ['upsert', $link->dataset, $link->id, $generation]));
            $outbox = MemoryProjectionOutbox::query()->firstOrCreate(['dedupe_key' => $dedupe], [
                'memory_link_id' => $link->id,
                'user_id' => $link->user_id,
                'action' => 'upsert',
                'dataset' => $link->dataset,
                'payload' => ['content_hash' => $link->content_hash],
                'status' => 'pending',
            ]);

            if (! $outbox->wasRecentlyCreated && ! in_array($outbox->status, ['failed'], true)) {
                return false;
            }
            if ($outbox->status === 'failed') {
                $outbox->update(['status' => 'pending', 'next_attempt_at' => null, 'last_error' => null]);
            }
            $link->update(['projection_status' => 'pending']);

            return true;
        });
    }

    private function outsideProjectionWindow(MemoryLink $link, \DateTimeInterface $now): bool
    {
        return ! MemoryProjectionPolicy::isActiveWithinValidity($link, $now);
    }
}
