<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryProjectionOutbox;
use App\Services\MemoryProjectionReconciler;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DispatchMemoryProjections extends Command
{
    protected $signature = 'luczor:dispatch-memory-projections {--limit=100}';

    protected $description = 'Dispatch pending or abandoned Cognee memory projection outbox entries';

    public function handle(MemoryProjectionReconciler $reconciler): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $reconciled = $reconciler->reconcile($limit);
        $rows = MemoryProjectionOutbox::query()
            ->where(function (Builder $query) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn (Builder $stale) => $stale
                        ->where('status', 'queued')
                        ->where('updated_at', '<=', now()->subMinutes(5)))
                    ->orWhere(fn (Builder $stale) => $stale
                        ->where('status', 'processing')
                        ->where('updated_at', '<=', now()->subMinutes(15)));
            })
            ->where(fn (Builder $query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $claimed = MemoryProjectionOutbox::query()
                ->whereKey($row->id)
                ->where('updated_at', $row->updated_at)
                ->update(['status' => 'queued', 'next_attempt_at' => null]);
            if ($claimed === 1) {
                ProcessMemoryProjection::dispatch($row->id);
            }
        }

        $this->info(sprintf(
            'Reconciled %d upsert(s) and %d delete(s); dispatched %d memory projection(s).',
            $reconciled['upserts'],
            $reconciled['deletes'],
            $rows->count()
        ));

        return self::SUCCESS;
    }
}
