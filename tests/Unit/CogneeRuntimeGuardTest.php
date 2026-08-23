<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CogneeRuntimeGuardTest extends TestCase
{
    public function test_wrapper_persists_launch_idempotency_and_exposes_exact_run_status(): void
    {
        $path = dirname(__DIR__, 2).'/services/cognee/luczor_cognee_app.py';
        $wrapper = file_get_contents($path);

        if ($wrapper === false) {
            self::fail('Unable to read '.$path.'.');
        }

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS luczor_cognify_idempotency', $wrapper);
        $this->assertStringContainsString("state = 'inflight'", $wrapper);
        $this->assertStringContainsString('if row["state"] == "completed"', $wrapper);
        $this->assertStringNotContainsString('_release_failed_claim', $wrapper);
        $this->assertStringContainsString('X-Luczor-Cognee-Launch-Instance', $wrapper);
        $this->assertStringContainsString(
            '@app.get("/api/v1/luczor/pipeline-runs/{pipeline_run_id}")',
            $wrapper,
        );
        $this->assertStringContainsString('PipelineRun.pipeline_run_id == pipeline_run_id', $wrapper);
        $this->assertStringContainsString('PipelineRun.dataset_id == dataset_id', $wrapper);
        $this->assertStringContainsString('.order_by(PipelineRun.created_at.desc())', $wrapper);
    }
}
