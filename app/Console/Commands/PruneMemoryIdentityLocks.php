<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMemoryIdentityLocks extends Command
{
    protected $signature = 'luczor:prune-memory-identity-locks {--days=7 : Minimum idle age} {--limit=5000 : Maximum rows per run}';

    protected $description = 'Remove idle, reproducible memory identity lock rows';

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $limit = max(1, min(50000, (int) $this->option('limit')));
        $hashes = DB::table('memory_identity_locks')
            ->where('updated_at', '<', now()->subDays($days))
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('identity_hash');
        if ($hashes->isEmpty()) {
            $this->info('No idle memory identity locks found.');

            return self::SUCCESS;
        }

        // Rows contain only reproducible hashes. A concurrent writer either
        // holds and refreshes the row (the DELETE predicate is rechecked) or
        // recreates it in lockMemoryIdentity's bounded acquisition loop.
        $deleted = DB::table('memory_identity_locks')
            ->whereIn('identity_hash', $hashes)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
        $this->info("Pruned {$deleted} idle memory identity locks.");

        return self::SUCCESS;
    }
}
