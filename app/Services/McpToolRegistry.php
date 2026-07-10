<?php

namespace App\Services;

/** Declarative MCP surface. All execution stays in the Laravel ownership boundary. */
class McpToolRegistry
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->byKey());
    }

    /** @return array<string,mixed> */
    public function find(string $server, string $tool): array
    {
        $descriptor = $this->byKey()[$server.'.'.$tool] ?? null;
        abort_unless($descriptor, 422, 'Unknown MCP tool.');

        return $descriptor;
    }

    /** @return array<string,array<string,mixed>> */
    private function byKey(): array
    {
        return [
            'github.repositories' => $this->tool('github', 'repositories', 'brain.read', 'low', 'laravel'),
            'github.branches' => $this->tool('github', 'branches', 'brain.write', 'normal', 'laravel'),
            'github.pull_requests' => $this->tool('github', 'pull_requests', 'brain.write', 'normal', 'laravel'),
            'repository.files' => $this->tool('repository', 'files', 'brain.read', 'sensitive', 'laravel'),
            'repository.git_diff' => $this->tool('repository', 'git_diff', 'brain.read', 'low', 'laravel'),
            'browser.navigate' => $this->tool('browser', 'navigate', 'device.jobs.write', 'normal', 'signed_device_job'),
            'database.query_readonly' => $this->tool('database', 'query_readonly', 'brain.read', 'sensitive', 'fixed_readonly_queries'),
            'ssh.run_profile' => $this->tool('ssh', 'run_profile', 'brain.write', 'critical', 'registered_sandbox_profile'),
            'desktop.device_job' => $this->tool('desktop', 'device_job', 'device.jobs.write', 'critical', 'signed_device_job'),
            'tests.run_suite' => $this->tool('tests', 'run_suite', 'brain.write', 'normal', 'registered_workflow'),
        ];
    }

    /** @return array<string,mixed> */
    private function tool(string $server, string $tool, string $ability, string $risk, string $execution): array
    {
        return compact('server', 'tool', 'ability', 'risk', 'execution');
    }
}
