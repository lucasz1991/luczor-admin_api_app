<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\User;
use App\Services\MemoryMetadata;
use App\Services\MemoryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MemorySyncTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Memory sync', 'abilities' => ['brain.read', 'brain.write'], 'active' => true]);

        return [$user, ['X-Api-Key' => $key['plain']]];
    }

    private function remember(User $user, string $id, array $extra = []): MemoryLink
    {
        return app(MemoryOrchestrator::class)->remember([...['user_id' => $user->id, 'tenant_id' => $user->tenant_id,
            'scope' => 'user', 'external_id' => $id, 'write_id' => $id, 'content' => 'Eine stabile Einstellung '.$id,
            'write_intent' => 'explicit'], ...$extra])->link;
    }

    public function test_initial_feed_pages_existing_rows_and_cursor_replays_without_skips(): void
    {
        [$user, $headers] = $this->identity();
        foreach (range(1, 7) as $n) {
            $this->remember($user, 'entry-'.$n);
        }
        $cursor = null;
        $ids = [];
        do {
            $page = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'limit' => 2, 'cursor' => $cursor], $headers)->assertOk()->json();
            $this->assertSame($cursor === null, $page['reset']);
            $ids = [...$ids, ...array_column($page['changes'], 'record_id')];
            $cursor = $page['cursor'];
        } while ($page['has_more']);
        $this->assertCount(7, array_unique($ids));
        $this->assertCount(7, $ids);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'cursor' => $cursor], $headers)->assertOk()->assertJsonPath('changes', []);
        $replay = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'limit' => 100], $headers)->assertOk()->json('changes');
        $this->assertSame($ids, array_column($replay, 'record_id'));
        $this->assertDatabaseCount('memory_sync_changes', 7);
        DB::table('memory_links')->where('user_id', $user->id)->update(['updated_at' => now()->addMinute()]);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'cursor' => $cursor], $headers)->assertOk()->assertJsonPath('changes', []);
    }

    public function test_sql_updates_bulk_revocations_and_deletions_become_ordered_changes_without_old_text(): void
    {
        [$user, $headers] = $this->identity();
        $row = $this->remember($user, 'one');
        $cursor = $this->postJson('/api/v1/memory/changes', ['scope' => 'user'], $headers)->json('cursor');
        DB::table('memory_links')->where('id', $row->id)->update(['importance' => 0.99]);
        $page = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'cursor' => $cursor], $headers)->assertOk()
            ->assertJsonPath('changes.0.memory.importance', 0.99)->json();
        DB::table('memory_links')->where('id', $row->id)->update(['visibility' => 'private']);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'cursor' => $page['cursor']], $headers)->assertOk()
            ->assertJsonPath('changes.0.operation', 'delete')->assertJsonMissing(['content' => $row->summary]);
        DB::table('memory_links')->where('id', $row->id)->delete();
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user'], $headers)->assertOk()->assertJsonMissing(['content' => $row->summary]);
        $this->assertStringNotContainsString($row->summary, json_encode(DB::table('memory_sync_changes')->get()));
    }

    public function test_cursor_and_content_are_account_and_project_bound(): void
    {
        [$user, $headers] = $this->identity();
        [, $other] = $this->identity();
        $this->remember($user, 'project-one', ['scope' => 'project', 'project_id' => 'one']);
        $page = $this->postJson('/api/v1/memory/changes', ['scope' => 'project', 'project_id' => 'one'], $headers)->assertOk()->json();
        $this->assertCount(1, $page['changes']);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'project', 'project_id' => 'one', 'cursor' => $page['cursor']], $other)->assertUnprocessable();
        $this->postJson('/api/v1/memory/changes', ['scope' => 'project', 'project_id' => 'two', 'cursor' => $page['cursor']], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/memory/changes', ['scope' => 'project', 'project_id' => 'one'], $other)->assertOk()->assertJsonPath('changes', []);
    }

    public function test_candidates_expired_and_repository_metadata_are_excluded(): void
    {
        [$user, $headers] = $this->identity();
        $this->remember($user, 'candidate', ['write_intent' => 'inferred']);
        $expired = $this->remember($user, 'expired');
        $expired->update(['expires_at' => now()->subMinute()]);
        $private = $this->remember($user, 'private');
        $private->update(['meta' => ['source_type' => 'repository_file', 'path' => '/private/source.ts']]);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user'], $headers)->assertOk()->assertJsonPath('changes', []);
    }

    public function test_forget_returns_scoped_projection_receipt_and_repeat_is_idempotent(): void
    {
        [$user, $headers] = $this->identity();
        [, $other] = $this->identity();
        $row = $this->remember($user, 'forget-me');
        $row->update(['cognee_memory_id' => 'provider-data']);
        $response = $this->postJson('/api/v1/memory/forget', ['scope' => 'user', 'external_id' => 'forget-me'], $headers)->assertOk()
            ->assertJsonPath('forgotten', true)->assertJsonPath('deletion_receipt.status', 'projection_pending')->json();
        $id = $response['deletion_receipt']['id'];
        $this->postJson('/api/v1/memory/deletion-receipt', ['receipt_id' => $id], $other)->assertNotFound();
        $this->postJson('/api/v1/memory/forget', ['scope' => 'user', 'external_id' => 'forget-me'], $headers)->assertOk()
            ->assertJsonPath('already_absent', true)->assertJsonPath('deletion_receipt.id', $id);
        MemoryProjectionOutbox::query()->where('memory_link_id', $row->id)->update(['status' => 'failed']);
        $this->postJson('/api/v1/memory/deletion-receipt', ['receipt_id' => $id], $headers)->assertOk()->assertJsonPath('data.status', 'blocked');
        MemoryProjectionOutbox::query()->where('memory_link_id', $row->id)->update(['status' => 'done']);
        $this->postJson('/api/v1/memory/deletion-receipt', ['receipt_id' => $id], $headers)->assertOk()->assertJsonPath('data.status', 'complete');
    }

    public function test_capabilities_are_cached_and_not_tied_to_a_maintenance_scope(): void
    {
        [, $headers] = $this->identity();
        $this->getJson('/api/v1/memory/capabilities', $headers)->assertOk()->assertJsonPath('capabilities.memory_change_feed', 1)
            ->assertJsonPath('capabilities.memory_metadata_scopes.2', 'workspace')->assertHeader('Cache-Control', 'max-age=300, private');
    }

    public function test_conflict_includes_safe_comparison_but_never_overwrites_a_version(): void
    {
        [$user, $headers] = $this->identity();
        $meta = MemoryMetadata::initial(new MemoryLink(['source_type' => 'user']));
        $first = $this->remember($user, 'conflict', ['meta' => ['memory_metadata' => $meta]]);
        $changed = $meta;
        $changed['kind'] = 'rule';
        $second = $this->remember($user, 'conflict', ['write_id' => 'change', 'expected_previous_id' => $first->id, 'meta' => ['memory_metadata' => $changed]]);
        $incoming = $meta;
        $incoming['kind'] = 'decision';
        $this->postJson('/api/v1/memory/remember', ['scope' => 'user', 'external_id' => 'conflict', 'write_id' => 'stale',
            'expected_previous_id' => $first->id, 'content' => $first->summary, 'meta' => ['memory_metadata' => $incoming]], $headers)
            ->assertConflict()->assertJsonPath('metadata_conflict.current_version_id', $second->id)->assertJsonPath('metadata_conflict.can_rebase', false);
        $this->assertDatabaseCount('memory_links', 2);
    }

    public function test_new_versions_between_pages_are_not_skipped_and_old_content_is_not_replayed(): void
    {
        [$user, $headers] = $this->identity();
        $first = $this->remember($user, 'first');
        $this->remember($user, 'second');
        $page = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'limit' => 1], $headers)->assertOk()->json();
        $new = $this->remember($user, 'first', ['write_id' => 'new-first', 'content' => 'Die neue Entscheidung.', 'expected_previous_id' => $first->id]);
        $next = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'cursor' => $page['cursor']], $headers)->assertOk()->json();
        $this->assertSame(['second', 'first'], array_column($next['changes'], 'record_id'));
        $this->assertSame((string) $new->id, $next['changes'][1]['source_record_id']);
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user'], $headers)->assertOk()->assertJsonMissing(['content' => $first->summary]);
    }

    public function test_scope_journal_and_receipts_are_removed_on_account_erasure(): void
    {
        [$user, $headers] = $this->identity();
        $this->remember($user, 'one');
        $this->postJson('/api/v1/memory/changes', ['scope' => 'user'], $headers)->assertOk();
        $this->postJson('/api/v1/memory/forget', ['scope' => 'user', 'external_id' => 'one'], $headers)->assertOk();
        $this->assertTrue($user->delete());
        foreach (['memory_sync_scopes', 'memory_sync_entries', 'memory_sync_changes', 'memory_deletion_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_large_metadata_is_paginated_below_client_response_limit_without_cursor_loss(): void
    {
        [$user, $headers] = $this->identity();
        foreach (range(1, 14) as $n) {
            $this->remember($user, 'large-'.$n, ['meta' => ['note' => str_repeat('ß', 16000)]]);
        }
        $page = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'limit' => 100], $headers)->assertOk();
        $page->assertJsonPath('has_more', true);
        $this->assertLessThan(1100000, strlen($page->getContent()));
        $next = $this->postJson('/api/v1/memory/changes', ['scope' => 'user', 'limit' => 100, 'cursor' => $page->json('cursor')], $headers)->assertOk();
        $this->assertCount(14, array_unique([...array_column($page->json('changes'), 'record_id'), ...array_column($next->json('changes'), 'record_id')]));
    }
}
