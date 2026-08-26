<?php

namespace App\Jobs;

use App\Services\MemoryProjectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProcessMemoryProjection implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** Keep worst-case provider recovery below Redis' 90-second boundary. */
    public int $timeout = 85;

    public bool $failOnTimeout = true;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $outboxId) {}

    public function handle(MemoryProjectionService $projections): void
    {
        $projections->process($this->outboxId);
    }
}
