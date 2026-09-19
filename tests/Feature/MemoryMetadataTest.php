<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\User;
use App\Services\MemoryMetadata;
use App\Services\MemoryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemoryMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Metadata test', 'abilities' => ['brain.write', 'brain.read'], 'active' => true]);
        $metadata = MemoryMetadata::initial(new MemoryLink(['source_type' => 'user']));
        $metadata['kind'] = 'preference';
        $metadata['categories'] = [MemoryMetadata::category(['Softwareentwicklung', 'backend', 'Laravel'])];
        $metadata['evidence']['sources'] = [['kind' => 'chat', 'id' => 'message-42', 'role' => 'user', 'conversationId' => 'chat-1']];
        $body = ['scope' => 'user', 'external_id' => 'preference-1', 'write_id' => 'metadata-write-1',
            'content' => 'Ich bevorzuge klare Antworten.', 'meta' => ['memory_metadata' => $metadata, 'unrelated' => 'retained'],
            'tags' => ['communication'], 'write_intent' => 'explicit'];

        return [$user, ['X-Api-Key' => $key['plain']], $body];
    }

    public function test_same_text_metadata_update_requires_cas_and_new_event_and_retries_exactly(): void
    {
        [, $headers, $body] = $this->fixture();
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $edit = $body;
        $edit['meta']['memory_metadata']['kind'] = 'rule';
        $this->postJson('/api/v1/memory/remember', $edit, $headers)->assertConflict();
        $edit['write_id'] = 'metadata-write-2';
        $this->postJson('/api/v1/memory/remember', $edit, $headers)->assertConflict()->assertJsonPath('current_memory_id', $first);
        $edit['expected_previous_id'] = $first;
        $second = $this->postJson('/api/v1/memory/remember', $edit, $headers)->assertCreated()->json('memory_link_id');
        $this->assertNotSame($first, $second);
        $this->assertSame(MemoryLink::find($first)->content_hash, MemoryLink::find($second)->content_hash);
        $this->assertSame('superseded', MemoryLink::find($first)->status);
        $this->postJson('/api/v1/memory/remember', $edit, $headers)->assertCreated()->assertJsonPath('memory_link_id', $second);
        $this->postJson('/api/v1/memory/remember', [...$edit, 'write_id' => 'stale-event'], $headers)->assertConflict();
        $this->assertDatabaseCount('memory_links', 2);
        $this->assertDatabaseCount('memory_write_events', 2);
        $this->postJson('/api/v1/memory/maintenance/status', ['scope' => 'user'], $headers)->assertOk()
            ->assertJsonPath('capabilities.memory_metadata_versions', [1])->assertJsonPath('capabilities.memory_metadata_cas', true);
    }

    public function test_legacy_omission_preserves_annotations_and_changed_content_invalidates_inference(): void
    {
        [, $headers, $body] = $this->fixture();
        $body['meta']['memory_metadata']['overrides'] = ['kind'];
        $body['meta']['memory_metadata']['interest'] = 0.7;
        $body['meta']['memory_metadata']['classification']['inputRevision'] = 'processed';
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $legacy = $body;
        unset($legacy['meta'], $legacy['tags']);
        $legacy['write_id'] = 'legacy-repeat';
        $this->postJson('/api/v1/memory/remember', $legacy, $headers)->assertCreated()->assertJsonPath('memory_link_id', $first);
        $legacy['write_id'] = 'legacy-edit';
        $legacy['content'] = 'Ich bevorzuge künftig ausführliche Antworten.';
        $second = $this->postJson('/api/v1/memory/remember', $legacy, $headers)->assertCreated()->json('memory_link_id');
        $metadata = MemoryLink::find($second)->meta['memory_metadata'];
        $this->assertSame('preference', $metadata['kind']);
        $this->assertNull($metadata['interest']);
        $this->assertSame([], $metadata['categories']);
        $this->assertSame('stale', $metadata['evidence']['status']);
        $this->assertArrayNotHasKey('inputRevision', $metadata['classification']);
        $clear = [...$legacy, 'write_id' => 'clear', 'expected_previous_id' => $second, 'meta' => ['memory_metadata' => null]];
        $third = $this->postJson('/api/v1/memory/remember', $clear, $headers)->assertCreated()->json('memory_link_id');
        $this->assertNull(MemoryLink::find($third)->meta['memory_metadata']);
    }

    public function test_cas_can_follow_an_authorized_source_across_devices_but_stale_write_cannot_win(): void
    {
        [$user, , $body] = $this->fixture();
        $memory = app(MemoryOrchestrator::class);
        $first = $memory->remember([...$body, 'user_id' => $user->id, 'client_id' => 'desktop-a'])->link;
        $body['meta']['memory_metadata']['kind'] = 'decision';
        $second = $memory->remember([...$body, 'user_id' => $user->id, 'client_id' => 'desktop-b',
            'write_id' => 'device-b-edit', 'expected_previous_id' => $first->id])->link;
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame('desktop-b', $second->client_id);
        $this->expectException(HttpException::class);
        $memory->remember([...$body, 'user_id' => $user->id, 'client_id' => 'desktop-a',
            'write_id' => 'device-a-stale', 'expected_previous_id' => $first->id]);
    }

    public function test_candidate_metadata_updates_never_promote_unconfirmed_content(): void
    {
        [$user, $headers, $body] = $this->fixture();
        $body['write_intent'] = 'inferred';
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertAccepted()->json('memory_link_id');
        $body['meta']['memory_metadata']['kind'] = 'hypothesis';
        $second = $this->postJson('/api/v1/memory/remember', [...$body, 'write_id' => 'candidate-edit', 'expected_previous_id' => $first], $headers)
            ->assertAccepted()->assertJsonPath('status', 'candidate')->json('memory_link_id');
        $this->assertNotSame($first, $second);
        $this->assertSame('superseded', MemoryLink::find($first)->status);
        $this->assertSame(0.35, MemoryLink::find($second)->confidence);
        $this->assertSame([], app(MemoryOrchestrator::class)->recall('', 'user', ['user_id' => $user->id]));
        $this->assertSame([], app(MemoryOrchestrator::class)->maintenanceSources('user', ['user_id' => $user->id], 0)['records']);
    }

    public function test_envelope_validation_and_nested_dlp_apply_to_http_and_internal_writes(): void
    {
        [$user, $headers, $body] = $this->fixture();
        foreach ([['version' => 2], ['interest' => '0.8'], ['categories' => [['id' => 'bad', 'path' => ['a', 'b', 'c', 'd', 'e']]]],
            ['evidence' => ['status' => 'source_backed', 'verifiedAt' => 1, 'sources' => []]]] as $invalid) {
            $bad = $body;
            $bad['meta']['memory_metadata'] = array_replace($bad['meta']['memory_metadata'], $invalid);
            $this->postJson('/api/v1/memory/remember', $bad, $headers)->assertUnprocessable();
        }
        $body['meta']['memory_metadata']['evidence']['sources'][0]['id'] = 'api_key: ghp_abcdefghijklmnopqrstuvwxyz123456';
        $this->assertSame('local_only', app(MemoryOrchestrator::class)->remember([...$body, 'user_id' => $user->id])->decision);
        $body['meta']['memory_metadata']['evidence']['sources'] = [];
        $body['meta']['memory_metadata']['files'] = [['path' => 'src/widget.vue', 'relation' => 'mentioned']];
        $this->postJson('/api/v1/memory/remember', $body, $headers)->assertAccepted()->assertJsonPath('decision', 'local_only');
        $this->assertDatabaseCount('memory_links', 0);
    }

    public function test_annotation_preserves_text_evidence_overrides_and_stops_reclassification(): void
    {
        [$user, $headers, $body] = $this->fixture();
        $body['meta']['memory_metadata']['overrides'] = ['kind', 'tags', 'importance'];
        $body['importance'] = 0.8;
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $source = $this->postJson('/api/v1/memory/maintenance/sources', ['scope' => 'user'], $headers)->assertOk()->json('data.records.0');
        $this->assertTrue($source['metadata_needed']);
        $request = ['scope' => 'user', 'request_id' => 'annotation-1', 'model_id' => 'small-local',
            'sources' => [['id' => $first, 'revision' => $source['revision']]], 'operations' => [[
                'operation' => 'annotate', 'targets' => [$first], 'sources' => [$first], 'content' => '', 'reason' => 'Kategorisierung',
                'metadata' => ['kind' => 'rule', 'interest' => 0.6, 'tags' => ['new'], 'importance' => 0.2,
                    'categories' => [['softwareentwicklung', 'backend', 'laravel']]],
            ]]];
        $this->postJson('/api/v1/memory/maintenance/apply', $request, $headers)->assertOk()->assertJsonPath('data.changed', 1);
        $this->postJson('/api/v1/memory/maintenance/apply', $request, $headers)->assertOk()->assertJsonPath('data.changed', 1);
        $active = MemoryLink::where('status', 'active')->first();
        $this->assertSame($body['content'], $active->summary);
        $this->assertSame(0.85, $active->confidence);
        $this->assertSame(0.8, $active->importance);
        $this->assertSame(['communication'], $active->meta['tags']);
        $this->assertSame('preference', $active->meta['memory_metadata']['kind']);
        $this->assertSame('user_stated', $active->meta['memory_metadata']['evidence']['status']);
        $this->assertSame('retained', $active->meta['unrelated']);
        $this->assertSame('software/backend/laravel', $active->meta['memory_metadata']['categories'][0]['id']);
        $next = app(MemoryOrchestrator::class)->maintenanceSources('user', ['user_id' => $user->id], 0)['records'][0];
        $this->assertFalse($next['metadata_needed']);
        $this->assertDatabaseCount('memory_maintenance_receipts', 1);
        $this->postJson('/api/v1/memory/maintenance/apply', [...$request, 'request_id' => 'stale-annotation'], $headers)->assertConflict();
        $this->assertDatabaseCount('memory_links', 2);
    }

    public function test_metadata_patch_cannot_change_evidence_and_scope_and_secret_metadata_are_rejected(): void
    {
        [, $headers, $body] = $this->fixture();
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $source = $this->postJson('/api/v1/memory/maintenance/sources', ['scope' => 'user'], $headers)->json('data.records.0');
        $request = ['scope' => 'user', 'request_id' => 'bad-annotation', 'model_id' => 'small-local',
            'sources' => [['id' => $first, 'revision' => $source['revision']]], 'operations' => [[
                'operation' => 'annotate', 'targets' => [$first], 'sources' => [$first], 'content' => '', 'reason' => 'Metadaten',
                'metadata' => ['evidence' => ['status' => 'source_backed']],
            ]]];
        $this->postJson('/api/v1/memory/maintenance/apply', $request, $headers)->assertUnprocessable();
        $request['operations'][0]['metadata'] = ['categories' => [['api_key: private']]];
        $this->postJson('/api/v1/memory/maintenance/apply', $request, $headers)->assertUnprocessable();
        $request['operations'][0]['metadata'] = ['kind' => 'fact'];
        $request['operations'][0]['content'] = 'Changed text';
        $this->postJson('/api/v1/memory/maintenance/apply', $request, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('memory_links', 1);
        $this->assertDatabaseCount('memory_maintenance_receipts', 0);
    }

    public function test_metadata_terms_find_old_memories_and_interest_only_breaks_equal_relevance(): void
    {
        [$user, , $body] = $this->fixture();
        $memory = app(MemoryOrchestrator::class);
        $body['meta']['memory_metadata']['categories'][] = MemoryMetadata::category(['Überzeugungen']);
        $old = $memory->remember([...$body, 'user_id' => $user->id])->link;
        for ($i = 0; $i < 105; $i++) {
            $noise = $old->replicate(['external_id', 'idempotency_key', 'write_fingerprint']);
            $noise->fill(['external_id' => 'noise-'.$i, 'importance' => 1, 'summary' => 'Unrelated subject '.$i,
                'content_hash' => hash('sha256', 'noise-'.$i), 'meta' => []])->save();
        }
        $hits = $memory->recall('Laravel', 'user', ['user_id' => $user->id]);
        $this->assertSame('preference-1', $hits[0]['id']);
        $this->assertSame('preference-1', $memory->recall('überzeugungen', 'user', ['user_id' => $user->id])[0]['id']);
        $body['meta']['memory_metadata']['interest'] = 1;
        $body['meta']['memory_metadata']['categories'] = [];
        $memory->remember([...$body, 'user_id' => $user->id, 'external_id' => 'interesting', 'write_id' => 'interesting', 'content' => 'Different unrelated preference.']);
        $this->assertSame('preference-1', $memory->recall('Laravel', 'user', ['user_id' => $user->id])[0]['id']);
        $body['meta']['memory_metadata']['categories'] = [MemoryMetadata::category(['Laravel'])];
        $memory->remember([...$body, 'user_id' => $user->id, 'external_id' => 'tie', 'write_id' => 'tie', 'content' => 'Equally relevant category.']);
        $this->assertSame('tie', $memory->recall('Laravel', 'user', ['user_id' => $user->id])[0]['id']);
        $this->assertSame([], $memory->recall('Laravel', 'user', ['user_id' => User::factory()->create()->id]));
    }

    public function test_local_dream_sync_keeps_client_stamp_but_server_owns_its_scheduling_revision(): void
    {
        [$user, $headers, $body] = $this->fixture();
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $body['meta']['memory_metadata']['classification']['origin'] = 'dream';
        $body['meta']['memory_metadata']['classification']['inputRevision'] = 'm1:client-key';
        $body['provenance'] = ['memory_metadata_input_revision' => 'forged'];
        $body['write_id'] = 'dream-sync';
        $body['expected_previous_id'] = $first;
        $second = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $link = MemoryLink::find($second);
        $this->assertSame('m1:client-key', $link->meta['memory_metadata']['classification']['inputRevision']);
        $this->assertSame(MemoryMetadata::inputRevision($link), $link->provenance['memory_metadata_input_revision']);
        $this->assertFalse(app(MemoryOrchestrator::class)->maintenanceSources('user', ['user_id' => $user->id], 0)['records'][0]['metadata_needed']);
        $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->assertJsonPath('memory_link_id', $second);
        $body['write_id'] = 'changed-after-dream';
        $body['content'] = 'Eine neue Bedeutung.';
        $body['expected_previous_id'] = $second;
        unset($body['meta'], $body['provenance']);
        $third = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        $this->assertArrayNotHasKey('memory_metadata_input_revision', MemoryLink::find($third)->provenance);
        $this->assertTrue(app(MemoryOrchestrator::class)->maintenanceSources('user', ['user_id' => $user->id], 0)['records'][0]['metadata_needed']);
    }

    public function test_metadata_only_write_does_not_refresh_expired_evidence_dates(): void
    {
        [$user, $headers, $body] = $this->fixture();
        $body['observed_at'] = '2025-01-01T00:00:00Z';
        $body['valid_from'] = '2025-01-01T00:00:00Z';
        $body['valid_until'] = '2025-02-01T00:00:00Z';
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->json('memory_link_id');
        unset($body['observed_at'], $body['valid_from'], $body['valid_until']);
        $body['meta']['memory_metadata']['kind'] = 'rule';
        $second = $this->postJson('/api/v1/memory/remember', [...$body, 'write_id' => 'expired-annotation', 'expected_previous_id' => $first], $headers)
            ->assertCreated()->json('memory_link_id');
        $this->assertTrue(MemoryLink::find($first)->observed_at->equalTo(MemoryLink::find($second)->observed_at));
        $this->assertTrue(MemoryLink::find($first)->valid_until->equalTo(MemoryLink::find($second)->valid_until));
        $this->assertSame([], app(MemoryOrchestrator::class)->recall('', 'user', ['user_id' => $user->id]));
    }

    public function test_merge_keeps_categories_tags_manual_values_and_source_evidence_without_elevating_trust(): void
    {
        [$user, , $body] = $this->fixture();
        $memory = app(MemoryOrchestrator::class);
        $body['meta']['memory_metadata']['overrides'] = ['kind', 'importance'];
        $body['importance'] = 0.8;
        $first = $memory->remember([...$body, 'user_id' => $user->id])->link;
        $body['meta']['memory_metadata']['categories'] = [MemoryMetadata::category(['Software', 'Frontend', 'Vue'])];
        $body['meta']['memory_metadata']['evidence']['sources'][0]['id'] = 'message-43';
        $body['tags'] = ['frontend'];
        $memory->remember([...$body, 'user_id' => $user->id, 'external_id' => 'merge-second', 'write_id' => 'merge-second', 'content' => 'Noch eine Präferenz.']);
        $sources = $memory->maintenanceSources('user', ['user_id' => $user->id], 0)['records'];
        $ids = array_column($sources, 'id');
        $result = $memory->applyMaintenance(['scope' => 'user', 'request_id' => 'merge', 'model_id' => 'capable', 'consent' => true,
            'quality' => ['passed' => true, 'policy' => 'luczor-maintenance-v1', 'model_id' => 'capable'],
            'sources' => array_map(fn ($source) => ['id' => $source['id'], 'revision' => $source['revision']], $sources),
            'operations' => [['operation' => 'merge', 'targets' => $ids, 'sources' => $ids,
                'content' => 'Zusammengefasste Präferenzen.', 'reason' => 'Gleiche Bedeutung erhalten.']]], ['user_id' => $user->id]);
        $this->assertSame(2, $result['changed']);
        $merged = MemoryLink::where('status', 'active')->first();
        $this->assertSame(0.8, $merged->importance);
        $this->assertSame(['communication', 'frontend', 'maintenance-derived'], $merged->meta['tags']);
        $this->assertCount(2, $merged->meta['memory_metadata']['categories']);
        $this->assertCount(2, $merged->meta['memory_metadata']['evidence']['sources']);
        $this->assertSame('inferred', $merged->meta['memory_metadata']['evidence']['status']);
        $this->assertSame('preference', $merged->meta['memory_metadata']['kind']);
        $this->assertSame('assistant', $merged->source_type);
        $this->assertLessThanOrEqual(0.35, $merged->confidence);
    }
}
