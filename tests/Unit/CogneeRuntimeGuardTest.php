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
        $this->assertStringContainsString('if row["state"] in {"completed", "acknowledged"}', $wrapper);
        $this->assertStringNotContainsString('_release_failed_claim', $wrapper);
        $this->assertStringContainsString('"/api/v1/improve"', $wrapper);
        $this->assertStringContainsString('A valid X-Luczor-Idempotency-Key is required', $wrapper);
        $this->assertStringContainsString('status_code == 420', $wrapper);
        $this->assertStringContainsString('status_code in {400, 401, 403, 404, 405, 413, 415, 420, 422}', $wrapper);
        $this->assertStringContainsString('X-Luczor-Cognee-Launch-Instance', $wrapper);
        $this->assertStringContainsString(
            '@app.get("/api/v1/luczor/pipeline-runs/{pipeline_run_id}")',
            $wrapper,
        );
        $this->assertStringContainsString('@app.get("/api/v1/luczor/runtime")', $wrapper);
        $this->assertStringContainsString('@app.post("/api/v1/luczor/launches/ack")', $wrapper);
        $this->assertStringContainsString('luczor_cognify_idempotency_state_updated_idx', $wrapper);
        $this->assertStringContainsString("SET state = 'acknowledged'", $wrapper);
        $this->assertStringNotContainsString('async def _cleanup_acknowledged', $wrapper);
        $this->assertStringNotContainsString('IDEMPOTENCY_ACK_RETENTION', $wrapper);
        $this->assertStringContainsString('if len(rows) != 1:', $wrapper);
        $this->assertStringContainsString('return True', $wrapper);
        $this->assertStringContainsString('PipelineRun.pipeline_run_id == pipeline_run_id', $wrapper);
        $this->assertStringContainsString('PipelineRun.dataset_id == dataset_id', $wrapper);
        $this->assertStringContainsString('.order_by(PipelineRun.created_at.desc())', $wrapper);
        $this->assertStringContainsString('async def _authenticate_guarded_principal(request):', $wrapper);
        $this->assertStringContainsString(
            '_registry_key(operation, principal_id, key)',
            $wrapper,
        );
        $this->assertStringContainsString('request_fingerprint = _request_fingerprint(await request.body())', $wrapper);
        $this->assertStringContainsString('if row["request_fingerprint"] != request_fingerprint:', $wrapper);
        $this->assertStringContainsString('The idempotency key belongs to a different request.', $wrapper);
        $this->assertStringContainsString('pg_advisory_xact_lock(hashtextextended($1, 0))', $wrapper);
        $this->assertStringContainsString('logical_identity = lock_identity(operation, principal_id, client_key_hash)', $wrapper);
        $this->assertStringContainsString('WHERE principal_id = $1 AND client_key_hash = $2', $wrapper);
        $this->assertStringContainsString('_acknowledge(key, str(user.id))', $wrapper);
        $this->assertStringContainsString('pg_try_advisory_lock', $wrapper);
        $this->assertStringContainsString('async def _assert_runtime_lease():', $wrapper);
        $this->assertStringContainsString('async def _runtime_lease_watchdog():', $wrapper);
        $this->assertStringContainsString('os._exit(70)', $wrapper);
        $this->assertStringContainsString('timeout=LEASE_PROBE_TIMEOUT_SECONDS', $wrapper);
        $this->assertStringContainsString('command_timeout=5', $wrapper);
        $this->assertStringContainsString('FOREIGN_INFLIGHT_FENCE_SECONDS', $wrapper);
        $this->assertStringContainsString(
            'install_luczor_lifespan(app, _initialize_registry, _shutdown_registry)',
            $wrapper,
        );
        $this->assertStringNotContainsString('app.add_event_handler(', $wrapper);
        $this->assertStringContainsString('if any(row["state"] == "inflight" for row in rows):', $wrapper);
        $this->assertStringContainsString('cache_body = _guarded_cache_body(operation, response.status_code, body)', $wrapper);
        $this->assertStringContainsString('response_body = cache_body if 200 <= response.status_code < 300 else body', $wrapper);
        $this->assertStringContainsString('Cognee returned an invalid guarded launch acceptance.', $wrapper);
        $this->assertStringContainsString('@app.get("/api/v1/luczor/data")', $wrapper);
        $this->assertStringContainsString('async with _exclusive_add_lookup():', $wrapper);
        $this->assertStringContainsString('stored_name = cognee_stored_memory_name(name)', $wrapper);
        $this->assertStringContainsString('Data.name == stored_name', $wrapper);
        $this->assertStringContainsString('request.url.path == "/api/v1/add"', $wrapper);
        $this->assertLessThan(
            strpos($wrapper, 'state, cached, registry_key = await _claim('),
            strpos($wrapper, 'principal_id = await _authenticate_guarded_principal(request)'),
        );
    }
}
