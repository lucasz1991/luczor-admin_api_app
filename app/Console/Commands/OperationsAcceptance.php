<?php

namespace App\Console\Commands;

use App\Services\OperationalAcceptanceService;
use Illuminate\Console\Command;

class OperationsAcceptance extends Command
{
    protected $signature = 'luczor:operations-acceptance
        {--workspace-root= : Root containing the app and admin_api_app repositories}
        {--evidence= : Read-only operator evidence JSON; credentials are not accepted}
        {--local-only : Pass when local preconditions pass while external checks remain pending}
        {--json : Emit machine-readable JSON without evidence values}';

    protected $description = 'Run the fail-closed, non-mutating Luczor operations acceptance gate';

    public function handle(OperationalAcceptanceService $acceptance): int
    {
        $workspaceRoot = $this->option('workspace-root');
        if (! is_string($workspaceRoot) || trim($workspaceRoot) === '') {
            $workspaceRoot = dirname(base_path());
        }

        $evidencePath = $this->option('evidence');
        $report = $acceptance->evaluate(
            $workspaceRoot,
            is_string($evidencePath) && trim($evidencePath) !== '' ? $evidencePath : null,
        );

        if ((bool) $this->option('json')) {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line(is_string($encoded) ? $encoded : '{"ready":false,"evidence_valid":false}');
        } else {
            $this->components->info('Local preconditions (read-only, no network probes)');
            $this->table(
                ['Check', 'Status', 'Missing'],
                $this->rows($report['local_checks']),
            );

            $this->newLine();
            $this->components->info('External operator evidence');
            $this->table(
                ['Check', 'Status', 'Missing evidence fields'],
                $this->rows($report['external_checks']),
            );

            foreach ($report['evidence_errors'] as $error) {
                $this->components->error($error);
            }
        }

        if (! $report['local_ready'] || ! $report['evidence_valid']) {
            if (! (bool) $this->option('json')) {
                $this->components->error('Acceptance failed: local prerequisites or evidence schema are invalid.');
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('local-only')) {
            if (! (bool) $this->option('json')) {
                $this->components->info('Local preconditions passed. External acceptance remains explicitly pending.');
            }

            return self::SUCCESS;
        }

        if (! $report['ready']) {
            if (! (bool) $this->option('json')) {
                $this->components->error('Release remains blocked until every external evidence field is verified.');
            }

            return self::FAILURE;
        }

        if (! (bool) $this->option('json')) {
            $this->components->info('All local and external acceptance checks passed.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: string, status: string, missing: list<string>}>  $checks
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function rows(array $checks): array
    {
        return collect($checks)->map(fn (array $check) => [
            $check['id'],
            strtoupper($check['status']),
            $check['missing'] === [] ? '-' : implode(', ', $check['missing']),
        ])->values()->all();
    }
}
