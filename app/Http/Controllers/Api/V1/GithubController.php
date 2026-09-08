<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GithubWebhookDelivery;
use App\Models\OAuthConnection;
use App\Models\Repository;
use App\Models\RepositoryCommit;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\ContextCache;
use App\Services\GithubService;
use App\Services\GitWritePolicy;
use App\Services\WorkflowEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GithubController extends Controller
{
    public function repositories(Request $request, ApiActor $actor, GithubService $github)
    {
        return response()->json(['data' => $github->repositories($this->connection($actor->userId($request)))]);
    }

    public function import(Request $request, ApiActor $actor, GithubService $github, AuditLogger $audit)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'project_id' => ['nullable', 'string', 'max:120'],
        ]);
        $userId = $actor->userId($request);
        $project = $actor->project($request, $data['project_id'] ?? null, null, (bool) ($data['project_id'] ?? null));
        $repository = $github->import($this->connection($userId), $userId, $data['full_name'], $project);
        $audit->record([
            'actor_user_id' => $userId, 'project_id' => $repository->project_id,
            'event_type' => 'github.repository_imported', 'tool' => 'github.repositories',
            'outcome' => 'completed', 'payload' => ['repository' => $repository->full_name],
        ]);

        return response()->json(['data' => $repository], 201);
    }

    public function branch(Request $request, Repository $repository, ApiActor $actor, GithubService $github, GitWritePolicy $policy, AuditLogger $audit)
    {
        $actor->assertOwned($request, $repository);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'from' => ['nullable', 'string', 'max:160'],
        ]);
        $result = $github->createBranch($this->connection((int) $repository->user_id), $repository, $data['name'], $data['from'] ?? $repository->default_branch, $policy);
        $sha = $result['object']['sha'] ?? null;
        $repository->branches()->updateOrCreate(['name' => $data['name']], ['head_sha' => $sha, 'last_seen_at' => now()]);
        $audit->record([
            'actor_user_id' => $actor->userId($request), 'project_id' => $repository->project_id,
            'event_type' => 'github.branch_created', 'tool' => 'github.branches',
            'risk_level' => 'normal', 'outcome' => 'completed', 'payload' => ['repository' => $repository->full_name, 'branch' => $data['name']],
        ]);

        return response()->json(['data' => $result], 201);
    }

    public function pullRequest(Request $request, Repository $repository, ApiActor $actor, GithubService $github, AuditLogger $audit)
    {
        $actor->assertOwned($request, $repository);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'head' => ['required', 'string', 'max:160'],
            'base' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:16000'],
        ]);
        $result = $github->createPullRequest($this->connection((int) $repository->user_id), $repository, $data['title'], $data['head'], $data['base'] ?? $repository->default_branch, $data['body'] ?? null);
        $audit->record([
            'actor_user_id' => $actor->userId($request), 'project_id' => $repository->project_id,
            'event_type' => 'github.pull_request_created', 'tool' => 'github.pull_requests',
            'risk_level' => 'normal', 'outcome' => 'completed', 'payload' => ['repository' => $repository->full_name, 'number' => $result['number'] ?? null],
        ]);

        return response()->json(['data' => $result], 201);
    }

    public function putFile(Request $request, Repository $repository, ApiActor $actor, GithubService $github, GitWritePolicy $policy, AuditLogger $audit)
    {
        $actor->assertOwned($request, $repository);
        $data = $request->validate([
            'branch' => ['required', 'string', 'max:160'],
            'path' => ['required', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:1000000'],
            'message' => ['required', 'string', 'max:500'],
            'sha' => ['nullable', 'string', 'max:80'],
            'force' => ['nullable', 'boolean'],
        ]);
        abort_if($data['force'] ?? false, 422, 'Force pushes are forbidden.');
        $result = $github->putFile($this->connection((int) $repository->user_id), $repository, $data['branch'], $data['path'], $data['content'], $data['message'], $data['sha'] ?? null, $policy);
        $audit->record([
            'actor_user_id' => $actor->userId($request), 'project_id' => $repository->project_id,
            'event_type' => 'github.file_written', 'tool' => 'github.files', 'risk_level' => 'critical',
            'outcome' => 'completed', 'payload' => ['repository' => $repository->full_name, 'branch' => $data['branch'], 'path' => $data['path']],
        ]);

        return response()->json(['data' => $result], 201);
    }

    public function webhook(Request $request, AuditLogger $audit, ContextCache $contextCache)
    {
        $raw = $request->getContent();
        abort_unless(strlen($raw) <= 1048576, 413, 'GitHub webhook payload is too large.');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $secret = (string) config('services.github.webhook_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature), 401, 'Invalid GitHub webhook signature.');
        $deliveryId = (string) $request->header('X-GitHub-Delivery');
        abort_unless($deliveryId !== '', 422, 'GitHub delivery id is required.');
        $event = (string) $request->header('X-GitHub-Event', 'unknown');
        $payload = $request->json()->all();
        $fullName = $payload['repository']['full_name'] ?? null;
        // A shared server hook explicitly fans out only to imported repository records.
        // Each event remains bound to that record's owner and project; never choose the first global match.
        $repositories = is_string($fullName) ? Repository::where('provider', 'github')->where('full_name', $fullName)->get() : collect();
        $duplicate = DB::transaction(function () use ($deliveryId, $repositories, $event, $signature, $payload, $contextCache, $audit, $fullName) {
            GithubWebhookDelivery::firstOrCreate(['delivery_id' => $deliveryId], [
                'repository_id' => $repositories->first()?->id, 'event' => $event, 'signature' => $signature, 'payload' => $payload,
            ]);
            $delivery = GithubWebhookDelivery::where('delivery_id', $deliveryId)->lockForUpdate()->firstOrFail();
            abort_unless($delivery->event === $event && hash_equals((string) $delivery->signature, $signature), 409, 'GitHub delivery ID was reused with different data.');
            if ($delivery->status === 'processed') {
                return true;
            }
            foreach ($repositories as $repository) {
                if (in_array($event, ['push', 'create'], true)) {
                    $branch = str_replace('refs/heads/', '', (string) ($payload['ref'] ?? ''));
                    $head = (string) ($payload['after'] ?? ($payload['head_commit']['id'] ?? ''));
                    if ($branch !== '') {
                        $repository->branches()->updateOrCreate(['name' => $branch], ['head_sha' => $head ?: null, 'last_seen_at' => now()]);
                    }
                    if ($head !== '' && ! preg_match('/^0+$/', $head)) {
                        $commit = RepositoryCommit::updateOrCreate(
                            ['repository_id' => $repository->id, 'sha' => $head],
                            ['branch' => $branch ?: null, 'message' => $payload['head_commit']['message'] ?? null, 'author_name' => $payload['head_commit']['author']['name'] ?? null, 'committed_at' => $payload['head_commit']['timestamp'] ?? now(), 'payload' => $payload['head_commit'] ?? null]
                        );
                        foreach (['added', 'modified', 'removed'] as $change) {
                            foreach ((array) ($payload['head_commit'][$change] ?? []) as $path) {
                                $commit->files()->updateOrCreate(['path' => $path], ['status' => $change]);
                            }
                        }
                        $repository->update(['last_commit_sha' => $head]);
                        $contextCache->invalidate((int) $repository->user_id, (string) $repository->id, $branch ?: null);
                    }
                }
                if (in_array($event, ['push', 'pull_request'], true)) {
                    app(WorkflowEventService::class)->record((int) $repository->user_id, $repository->project_id,
                        $event === 'push' ? 'github.push' : 'github.pull_request', 'github:'.$repository->id, $deliveryId, [
                            'repository_id' => $repository->id, 'repository' => $repository->full_name,
                            'branch' => $event === 'push' ? str_replace('refs/heads/', '', (string) ($payload['ref'] ?? '')) : ($payload['pull_request']['base']['ref'] ?? null),
                            'action' => $payload['action'] ?? 'push', 'sha' => $payload['after'] ?? ($payload['pull_request']['head']['sha'] ?? null),
                            'pull_request_number' => $payload['number'] ?? null, 'deleted' => (bool) ($payload['deleted'] ?? false),
                        ]);
                }
            }
            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
            $audit->record([
                'actor_user_id' => $repositories->first()?->user_id, 'project_id' => $repositories->first()?->project_id,
                'event_type' => 'github.webhook', 'tool' => 'github.webhook', 'outcome' => 'processed',
                'payload' => ['delivery_id' => $deliveryId, 'event' => $event, 'repository' => $fullName, 'repository_count' => $repositories->count()],
            ]);

            return false;
        });

        return response()->json(['ok' => true, 'status' => $duplicate ? 'duplicate' : 'processed']);
    }

    private function connection(int $userId): OAuthConnection
    {
        return OAuthConnection::query()->where('user_id', $userId)->where('provider', 'github')->firstOrFail();
    }
}
