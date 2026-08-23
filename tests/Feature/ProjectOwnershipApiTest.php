<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_are_owned_and_cannot_be_read_or_updated_by_another_user(): void
    {
        [$owner, $ownerToken] = $this->token(['brain.read', 'brain.write']);
        [, $otherToken] = $this->token(['brain.read', 'brain.write']);
        $project = $this->withHeader('X-Api-Key', $ownerToken)->postJson('/api/v1/projects', [
            'external_id' => 'project-owned', 'name' => 'Owned project',
        ])->assertCreated()->json('data');

        $this->withHeader('X-Api-Key', $otherToken)->getJson('/api/v1/projects/'.$project['id'])->assertNotFound();
        $this->withHeader('X-Api-Key', $otherToken)->patchJson('/api/v1/projects/'.$project['id'], ['name' => 'Taken over'])->assertNotFound();
        $this->withHeader('X-Api-Key', $otherToken)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.total', 0);
        $this->assertSame('Owned project', Project::findOrFail($project['id'])->name);
        $this->assertSame($owner->id, Project::findOrFail($project['id'])->user_id);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Project client', 'abilities' => $abilities, 'active' => true]);

        return [$user, $minted['plain']];
    }
}
