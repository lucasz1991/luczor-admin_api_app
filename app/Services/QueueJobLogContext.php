<?php

namespace App\Services;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Context;

final class QueueJobLogContext
{
    public const JOB_ID_KEY = 'queue_job_id';

    /**
     * Add a bounded, log-safe queue identifier to the current worker scope.
     */
    public function apply(Job $job): void
    {
        $this->clear();

        $identifier = $this->normalizeIdentifier($job->uuid())
            ?? $this->normalizeIdentifier($job->getJobId());

        if ($identifier !== null) {
            Context::add(self::JOB_ID_KEY, $identifier);
        }
    }

    public function clear(): void
    {
        Context::forget(self::JOB_ID_KEY);
    }

    private function normalizeIdentifier(mixed $identifier): ?string
    {
        if (! is_int($identifier) && ! is_string($identifier)) {
            return null;
        }

        $identifier = trim((string) $identifier);

        if ($identifier === '' || strlen($identifier) > 128) {
            return null;
        }

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $identifier) === 1
            ? $identifier
            : null;
    }
}
