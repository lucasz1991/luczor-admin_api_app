<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskIdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_external_id_makes_task_creation_idempotent_and_exactly_queryable(): void
    {
        [$owner, $token] = $this->token();
        $externalId = (string) Str::uuid();
        $headers = ['X-Api-Key' => $token];

        $this->withHeaders($headers)->postJson('/api/v1/tasks', [
            'external_id' => $externalId,
            'title' => 'Graph vervollständigen',
            'description' => 'Erster, möglicherweise zeitlich abgebrochener Versuch.',
        ])->assertCreated()->assertJsonPath('data.external_id', $externalId);

        $this->withHeaders($headers)->postJson('/api/v1/tasks', [
            'external_id' => $externalId,
            'title' => 'Dieser Retry darf keinen zweiten Task erzeugen',
        ])->assertOk()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonMissingPath('data.title');

        $this->assertSame(1, Task::where('user_id', $owner->getKey())->where('external_id', $externalId)->count());
        $this->assertDatabaseHas('tasks', [
            'user_id' => $owner->getKey(),
            'external_id' => $externalId,
            'title' => 'Graph vervollständigen',
            'description' => 'Erster, möglicherweise zeitlich abgebrochener Versuch.',
        ]);
        $this->withHeaders($headers)->getJson('/api/v1/tasks?external_id='.$externalId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', $externalId)
            ->assertJsonPath('meta.task_create_idempotency', 'external_id_v1')
            ->assertJsonPath('meta.filters.external_id', $externalId);

        $missingExternalId = (string) Str::uuid();
        $this->withHeaders($headers)->getJson('/api/v1/tasks?external_id='.$missingExternalId)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.task_create_idempotency', 'external_id_v1')
            ->assertJsonPath('meta.filters.external_id', $missingExternalId);
    }

    public function test_external_id_idempotency_is_scoped_to_the_authenticated_user(): void
    {
        [, $firstToken] = $this->token();
        [, $secondToken] = $this->token();
        $externalId = (string) Str::uuid();

        foreach ([$firstToken, $secondToken] as $token) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks', [
                'external_id' => $externalId,
                'title' => 'Eigene Aufgabe',
            ])->assertCreated();
        }

        $this->assertSame(2, Task::where('external_id', $externalId)->count());
    }

    public function test_write_only_replay_does_not_disclose_existing_task_content(): void
    {
        [$owner, $token] = $this->token(['brain.write']);
        $externalId = (string) Str::uuid();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks', [
            'external_id' => $externalId,
            'title' => 'Vertraulicher Titel',
            'description' => 'Vertrauliche Beschreibung',
        ])->assertCreated()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.description');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks', [
            'external_id' => $externalId,
            'title' => 'Abweichender Retry-Titel',
        ])->assertOk()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.description');

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/tasks?external_id='.$externalId)
            ->assertForbidden();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks/verify-create', [
            'external_id' => $externalId,
        ])->assertOk()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('meta.task_create_idempotency', 'external_id_v1')
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.description');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks/verify-create', [
            'external_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.exists', false);
        $this->assertDatabaseHas('tasks', [
            'user_id' => $owner->getKey(),
            'external_id' => $externalId,
            'title' => 'Vertraulicher Titel',
            'description' => 'Vertrauliche Beschreibung',
        ]);
    }

    public function test_unknown_project_filter_does_not_match_an_unassigned_task(): void
    {
        [, $token] = $this->token();
        $externalId = (string) Str::uuid();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks', [
            'external_id' => $externalId,
            'title' => 'Unzugeordnete Aufgabe',
        ])->assertCreated();

        $this->withHeader('X-Api-Key', $token)->getJson(
            '/api/v1/tasks?external_id='.$externalId.'&project_id=unknown-project'
        )->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.filters.external_id', $externalId);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/tasks', [
            'external_id' => (string) Str::uuid(),
            'project_id' => 'unknown-project',
            'title' => 'Darf nicht unzugeordnet entstehen',
        ])->assertUnprocessable();

        $this->assertSame(1, Task::count());
    }

    public function test_task_project_preparation_does_not_reactivate_or_erase_an_existing_project(): void
    {
        [$owner, $token] = $this->token();
        Project::query()->create([
            'user_id' => $owner->getKey(),
            'external_id' => 'project-archived',
            'name' => 'Servername',
            'status' => 'archived',
            'meta' => ['source' => 'server', 'protected' => true],
        ]);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/projects', [
            'external_id' => 'project-archived',
            'name' => 'Lokaler Name',
        ])->assertOk()
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.name', 'Servername')
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.meta.source', 'server')
            ->assertJsonPath('data.meta.protected', true);

        $this->assertDatabaseHas('projects', [
            'user_id' => $owner->getKey(),
            'external_id' => 'project-archived',
            'name' => 'Servername',
            'status' => 'archived',
        ]);
    }

    public function test_task_update_rejects_an_unknown_project_without_removing_the_existing_assignment(): void
    {
        [$owner, $token] = $this->token();
        $project = Project::query()->create([
            'user_id' => $owner->getKey(),
            'external_id' => 'project-valid',
            'name' => 'Gültiges Projekt',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'user_id' => $owner->getKey(),
            'client_id' => 'test',
            'external_id' => (string) Str::uuid(),
            'project_ref_id' => $project->getKey(),
            'title' => 'Zugeordnete Aufgabe',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $this->withHeader('X-Api-Key', $token)->patchJson('/api/v1/tasks/'.$task->external_id, [
            'project_id' => 'project-unknown',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->getKey(),
            'project_ref_id' => $project->getKey(),
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities = ['brain.read', 'brain.write']): array
    {
        $createdUser = User::factory()->create();
        $user = User::query()->findOrFail($createdUser->getKey());
        $minted = ApiKey::mint([
            'user_id' => $user->getKey(),
            'name' => 'Task client',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, (string) $minted['plain']];
    }
}
