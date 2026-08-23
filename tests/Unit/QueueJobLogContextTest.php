<?php

namespace Tests\Unit;

use App\Services\QueueJobLogContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QueueJobLogContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::flush();

        parent::tearDown();
    }

    public function test_queue_events_add_and_clear_a_safe_job_identifier(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('payload')->willReturn([]);
        $job->method('uuid')->willReturn('01991d4c-4458-7298-a117-d51d5d848487');
        $job->method('getJobId')->willReturn('42');

        Event::dispatch(new JobProcessing('database', $job));

        $this->assertSame(
            '01991d4c-4458-7298-a117-d51d5d848487',
            Context::get(QueueJobLogContext::JOB_ID_KEY),
        );

        Event::dispatch(new JobProcessed('database', $job));

        $this->assertTrue(Context::missing(QueueJobLogContext::JOB_ID_KEY));
    }

    public function test_malformed_job_identifiers_are_not_added_to_log_context(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('uuid')->willReturn("unsafe\r\nidentifier");
        $job->method('getJobId')->willReturn(str_repeat('a', 129));

        (new QueueJobLogContext)->apply($job);

        $this->assertTrue(Context::missing(QueueJobLogContext::JOB_ID_KEY));
    }
}
