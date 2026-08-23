<?php

namespace App\Console\Commands;

use App\Services\DeploymentHealthService;
use Illuminate\Console\Command;

class DeploymentCheck extends Command
{
    protected $signature = 'luczor:deployment-check
        {--production : Enforce every production safety requirement}
        {--configuration-only : Skip database and process probes}
        {--skip-reverb-probe : Do not open a socket to the internal Reverb listener}';

    protected $description = 'Verify Luczor queue, Horizon, scheduler, Reverb, migrations and security configuration';

    public function handle(DeploymentHealthService $health): int
    {
        $includeRuntime = ! $this->option('configuration-only');
        $checks = $health->checks(
            enforceProduction: (bool) $this->option('production'),
            includeRuntime: $includeRuntime,
            probeReverbServer: $includeRuntime && ! $this->option('skip-reverb-probe'),
        );

        $this->table(
            ['Check', 'Result'],
            collect($checks)->map(fn (bool $ok, string $name) => [
                $name,
                $ok ? 'OK' : 'FAILED',
            ])->values()->all()
        );

        if (in_array(false, $checks, true)) {
            $this->components->error('Luczor is not deployment-ready. Resolve every failed check before switching traffic.');

            return self::FAILURE;
        }

        $this->components->info('Luczor deployment checks passed.');

        return self::SUCCESS;
    }
}
