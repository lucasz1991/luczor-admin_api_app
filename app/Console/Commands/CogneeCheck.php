<?php

namespace App\Console\Commands;

use App\Services\Cognee\CogneeClient;
use Illuminate\Console\Command;

class CogneeCheck extends Command
{
    protected $signature = 'luczor:cognee-check';

    protected $description = 'Verify the configured authenticated Cognee runtime without writing memory';

    public function handle(CogneeClient $cognee): int
    {
        if (! $cognee->enabled()) {
            $this->components->error('Cognee is disabled. Configure its base URL and service credential.');

            return self::FAILURE;
        }

        try {
            $instanceId = $cognee->probeRuntime(true);
        } catch (\Throwable) {
            $this->components->error('Cognee authentication or runtime probe failed. Check the local endpoint, key file and Cognee logs.');

            return self::FAILURE;
        }

        if (! is_string($instanceId) || $instanceId === '') {
            $this->components->error('Cognee returned no valid runtime identity.');

            return self::FAILURE;
        }

        $this->components->info('Cognee authentication and runtime probe passed.');

        return self::SUCCESS;
    }
}
