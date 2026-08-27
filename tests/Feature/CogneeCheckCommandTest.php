<?php

namespace Tests\Feature;

use App\Services\Cognee\CogneeClient;
use Tests\TestCase;

class CogneeCheckCommandTest extends TestCase
{
    public function test_command_passes_for_an_authenticated_runtime(): void
    {
        $this->app->instance(CogneeClient::class, new class extends CogneeClient
        {
            public function __construct()
            {
                parent::__construct('http://127.0.0.1:8010', 'service-key');
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
            }
        });

        $this->artisan('luczor:cognee-check')
            ->expectsOutputToContain('Cognee authentication and runtime probe passed.')
            ->assertSuccessful();
    }

    public function test_command_fails_without_configuration(): void
    {
        $this->app->instance(CogneeClient::class, new CogneeClient);

        $this->artisan('luczor:cognee-check')
            ->expectsOutputToContain('Cognee is disabled.')
            ->assertFailed();
    }
}
