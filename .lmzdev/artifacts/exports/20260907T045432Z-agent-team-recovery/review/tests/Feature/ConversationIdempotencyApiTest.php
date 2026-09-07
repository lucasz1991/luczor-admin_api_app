<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationIdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_external_id_makes_conversation_creation_idempotent_and_exactly_queryable(): void
    {
        [$owner, $token] = $this->token();
        $externalId = (string) Str::uuid();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/conversations', [
            'external_id' => $externalId,
            'title' => 'Erster Titel',
        ])->assertCreated()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('meta.replayed', false);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/conversations', [
            'external_id' => $externalId,
            'title' => 'Retry darf Inhalt nicht ändern',
        ])->assertOk()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonMissingPath('data.title');

        $this->assertSame(
            1,
            Conversation::where('user_id', $owner->getKey())->where('external_id', $externalId)->count()
        );
        $this->assertDatabaseHas('conversations', [
            'user_id' => $owner->getKey(),
            'external_id' => $externalId,
            'title' => 'Erster Titel',
        ]);

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/conversations?external_id='.$externalId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.conversation_create_idempotency', 'external_id_v1')
            ->assertJsonPath('meta.filters.external_id', $externalId);
    }

    public function test_write_only_key_can_verify_without_reading_conversation_content(): void
    {
        [, $token] = $this->token(['brain.write']);
        $externalId = (string) Str::uuid();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/conversations', [
            'external_id' => $externalId,
            'title' => 'Vertraulicher Chat',
        ])->assertCreated();

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/conversations?external_id='.$externalId)
            ->assertForbidden();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/conversations/verify-create', [
            'external_id' => $externalId,
        ])->assertOk()
            ->assertJsonPath('data.external_id', $externalId)
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('meta.conversation_create_idempotency', 'external_id_v1')
            ->assertJsonMissingPath('data.title');
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities = ['brain.read', 'brain.write']): array
    {
        $createdUser = User::factory()->create();
        $user = User::query()->findOrFail($createdUser->getKey());
        $minted = ApiKey::mint([
            'user_id' => $user->getKey(),
            'name' => 'Conversation client',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, (string) $minted['plain']];
    }
}
