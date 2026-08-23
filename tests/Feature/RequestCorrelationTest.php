<?php

namespace Tests\Feature;

use App\Jobs\ExecuteWorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_a_valid_client_correlation_id(): void
    {
        $correlationId = (string) Str::uuid();

        $this->withHeader('X-Luczor-Correlation-Id', $correlationId)
            ->getJson('/api/v1/version')
            ->assertSuccessful()
            ->assertHeader('X-Luczor-Correlation-Id', $correlationId);
    }

    public function test_it_replaces_an_invalid_client_correlation_id(): void
    {
        $response = $this->withHeader('X-Luczor-Correlation-Id', "invalid\r\nreflected")
            ->getJson('/api/v1/version')
            ->assertSuccessful();

        $correlationId = (string) $response->headers->get('X-Luczor-Correlation-Id');

        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertNotSame('invalid reflected', $correlationId);
    }

    public function test_rendered_exceptions_keep_the_correlation_id(): void
    {
        Route::middleware('api')->get('/api/_test/correlation-error', static function (): never {
            throw new RuntimeException('Synthetic failure without sensitive data.');
        });

        $correlationId = (string) Str::uuid();

        $this->withHeader('X-Luczor-Correlation-Id', $correlationId)
            ->getJson('/api/_test/correlation-error')
            ->assertStatus(500)
            ->assertHeader('X-Luczor-Correlation-Id', $correlationId);
    }

    public function test_correlation_id_is_propagated_into_async_queue_payloads(): void
    {
        config(['queue.default' => 'database']);

        Route::middleware('api')->post('/api/_test/correlation-queue', static function () {
            ExecuteWorkflowStep::dispatch(123);

            return response()->noContent();
        });

        $correlationId = (string) Str::uuid();

        $this->withHeader('X-Luczor-Correlation-Id', $correlationId)
            ->postJson('/api/_test/correlation-queue')
            ->assertNoContent()
            ->assertHeader('X-Luczor-Correlation-Id', $correlationId);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $serializedCorrelationId = $payload['illuminate:log:context']['data']['correlation_id'] ?? null;

        $this->assertIsString($serializedCorrelationId);
        $this->assertSame($correlationId, unserialize($serializedCorrelationId, ['allowed_classes' => false]));
        $this->assertTrue(Context::missing('correlation_id'));
    }
}
