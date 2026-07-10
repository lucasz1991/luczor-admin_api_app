<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\EvaluationResult;
use App\Models\LlmRun;
use App\Models\OAuthConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\WorkflowDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Executes MCP requests through fixed integrations only. No request can select a shell, binary or SQL statement. */
class McpToolService
{
    public function __construct(
        private ApiActor $actor,
        private GithubService $github,
        private GitWritePolicy $gitPolicy,
        private DeviceJobService $deviceJobs,
        private WorkflowService $workflows,
    ) {
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    public function execute(Request $request, array $descriptor, array $input): array
    {
        return match ($descriptor['server'].'.'.$descriptor['tool']) {
            'github.repositories' => $this->repositories($request, $input),
            'github.branches' => $this->branch($request, $input),
            'github.pull_requests' => $this->pullRequest($request, $input),
            'repository.files' => $this->file($request, $input),
            'repository.git_diff' => $this->diff($request, $input),
            'browser.navigate' => $this->navigate($request, $input),
            'database.query_readonly' => $this->projectOverview($request, $input),
            'desktop.device_job' => $this->deviceJob($request, $input),
            'tests.run_suite' => $this->testWorkflow($request, $input),
            'ssh.run_profile' => abort(409, 'No registered sandboxed SSH profile is available for this project.'),
            default => abort(422, 'Unknown MCP tool.'),
        };
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function repositories(Request $request, array $input): array
    {
        $project = $this->project($request, $input);
        $query = Repository::query()->where('user_id', $this->actor->userId($request))->orderBy('full_name');
        if ($project) {
            $query->where('project_id', $project->id);
        }
        return $this->completed(['repositories' => $query->get(['id', 'project_id', 'provider', 'full_name', 'default_branch', 'last_commit_sha'])->all()], $project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function branch(Request $request, array $input): array
    {
        $data = Validator::make($input, ['repository_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:160'], 'from' => ['nullable', 'string', 'max:160']])->validate();
        $repository = $this->repository($request, (int) $data['repository_id']);
        $result = $this->github->createBranch($this->connection($request), $repository, $data['name'], $data['from'] ?? $repository->default_branch, $this->gitPolicy);
        $repository->branches()->updateOrCreate(['name' => $data['name']], ['head_sha' => $result['object']['sha'] ?? null, 'last_seen_at' => now()]);
        return $this->completed(['branch' => $result], $repository->project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function pullRequest(Request $request, array $input): array
    {
        $data = Validator::make($input, ['repository_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'head' => ['required', 'string', 'max:160'], 'base' => ['nullable', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:16000']])->validate();
        $repository = $this->repository($request, (int) $data['repository_id']);
        $result = $this->github->createPullRequest($this->connection($request), $repository, $data['title'], $data['head'], $data['base'] ?? $repository->default_branch, $data['body'] ?? null);
        return $this->completed(['pull_request' => $result], $repository->project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function file(Request $request, array $input): array
    {
        $data = Validator::make($input, ['repository_id' => ['required', 'integer'], 'path' => ['required', 'string', 'max:1000'], 'branch' => ['nullable', 'string', 'max:160']])->validate();
        $repository = $this->repository($request, (int) $data['repository_id']);
        return $this->completed(['file' => $this->github->file($this->connection($request), $repository, $data['path'], $data['branch'] ?? null)], $repository->project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function diff(Request $request, array $input): array
    {
        $data = Validator::make($input, ['repository_id' => ['required', 'integer'], 'base' => ['required', 'string', 'max:160'], 'head' => ['required', 'string', 'max:160']])->validate();
        $repository = $this->repository($request, (int) $data['repository_id']);
        return $this->completed(['diff' => $this->github->compare($this->connection($request), $repository, $data['base'], $data['head'])], $repository->project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function navigate(Request $request, array $input): array
    {
        $data = Validator::make($input, ['device_id' => ['required', 'string', 'max:120'], 'project_id' => ['nullable', 'string', 'max:120'], 'url' => ['required', 'url', 'max:2048']])->validate();
        $job = $this->deviceJobs->create($request, ['device_id' => $data['device_id'], 'project_id' => $data['project_id'] ?? null, 'tool_profile' => 'desktop.open_url', 'payload' => ['url' => $data['url']]]);
        return $this->queued(['device_job' => $job->only(['public_id', 'status', 'risk_level', 'requires_local_approval'])], $job->project_id, $job->id);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function deviceJob(Request $request, array $input): array
    {
        $data = Validator::make($input, ['device_id' => ['required', 'string', 'max:120'], 'project_id' => ['nullable', 'string', 'max:120'], 'tool_profile' => ['required', 'string', 'max:120'], 'payload' => ['nullable', 'array']])->validate();
        $job = $this->deviceJobs->create($request, $data);
        return $this->queued(['device_job' => $job->only(['public_id', 'status', 'risk_level', 'requires_local_approval'])], $job->project_id, $job->id);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function projectOverview(Request $request, array $input): array
    {
        Validator::make($input, ['query' => ['nullable', 'in:project_overview'], 'project_id' => ['nullable', 'string', 'max:120']])->validate();
        $userId = $this->actor->userId($request);
        $project = $this->project($request, $input);
        $projectId = $project?->id;
        return $this->completed(['project_overview' => [
            'projects' => $project ? 1 : Project::query()->where('user_id', $userId)->count(),
            'repositories' => Repository::query()->where('user_id', $userId)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->count(),
            'devices' => Device::query()->where('user_id', $userId)->count(),
            'device_jobs' => DeviceJob::query()->where('user_id', $userId)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->count(),
            'llm_runs' => LlmRun::query()->where('user_id', $userId)->when($projectId, fn ($q) => $q->where('project_ref_id', $projectId))->count(),
            'evaluations' => EvaluationResult::query()->where('user_id', $userId)->when($projectId, fn ($q) => $q->where('project_ref_id', $projectId))->count(),
        ]], $project);
    }

    /** @param array<string,mixed> $input @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function testWorkflow(Request $request, array $input): array
    {
        $data = Validator::make($input, ['workflow_definition_id' => ['required', 'integer'], 'input' => ['nullable', 'array']])->validate();
        $definition = WorkflowDefinition::findOrFail($data['workflow_definition_id']);
        $this->actor->assertOwned($request, $definition);
        $hasEvaluator = collect($this->workflows->assertDefinition($definition->definition))->contains(fn (array $step) => $step['type'] === 'evaluator');
        abort_unless($hasEvaluator, 422, 'The registered test workflow requires an evaluator step.');
        $run = $this->workflows->advance($this->workflows->createRun($definition, $data['input'] ?? []));
        return $this->queued(['workflow_run' => $run->public_id, 'status' => $run->status], $run->project_id);
    }

    private function connection(Request $request): OAuthConnection
    {
        return OAuthConnection::query()->where('user_id', $this->actor->userId($request))->where('provider', 'github')->firstOrFail();
    }

    private function repository(Request $request, int $id): Repository
    {
        $repository = Repository::findOrFail($id);
        $this->actor->assertOwned($request, $repository);
        return $repository;
    }

    /** @param array<string,mixed> $input */
    private function project(Request $request, array $input): ?Project
    {
        $externalId = $input['project_id'] ?? null;
        $project = $this->actor->project($request, is_string($externalId) ? $externalId : null);
        abort_if($externalId && ! $project, 404, 'Project was not found.');
        return $project;
    }

    /** @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function completed(array $output, ?Project $project): array
    {
        return ['status' => 'completed', 'output' => $output, 'project_id' => $project?->id, 'device_job_id' => null];
    }

    /** @return array{status:string,output:array<string,mixed>,project_id:?int,device_job_id:?int} */
    private function queued(array $output, ?int $projectId, ?int $deviceJobId = null): array
    {
        return ['status' => 'queued', 'output' => $output, 'project_id' => $projectId, 'device_job_id' => $deviceJobId];
    }
}
