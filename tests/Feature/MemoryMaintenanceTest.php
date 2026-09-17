<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\User;
use App\Services\MemoryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemoryMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $memory = app(MemoryOrchestrator::class);
        $ids = ['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'project_id' => 'p1'];
        $link = $memory->remember(array_merge($ids, ['scope' => 'project', 'content' => 'Nur lesender Zugriff ist freigegeben.',
            'write_id' => 'original-write', 'external_id' => 'original', 'write_intent' => 'explicit', 'visibility' => 'syncable']))->link;
        $source = $memory->maintenanceSources('project', $ids, 0)['records'][0];
        $data = ['scope' => 'project', 'request_id' => 'request-1', 'model_id' => 'fixture-local', 'consent' => true,
            'quality' => ['passed' => true, 'policy' => 'luczor-maintenance-v1', 'model_id' => 'fixture-local'],
            'sources' => [['id' => $source['id'], 'revision' => $source['revision']]],
            'operations' => [['operation' => 'rewrite', 'targets' => [$source['id']], 'sources' => [$source['id']],
                'content' => 'Freigabe: ausschließlich lesender Zugriff.', 'reason' => 'Bedeutung und Grenze erhalten.']]];

        return [$memory, $ids, $data, $link];
    }

    public function test_atomic_replacement_is_idempotent_and_keeps_only_metadata_receipts(): void
    {
        [$memory, $ids, $data, $link] = $this->fixture();
        $result = $memory->applyMaintenance($data, $ids);
        $this->assertSame(1, $result['changed']);
        $this->assertNull(MemoryLink::find($link->id));
        $this->assertSame(1, MemoryLink::count());
        $this->assertSame($result, $memory->applyMaintenance($data, $ids));
        $receipt = DB::table('memory_maintenance_receipts')->first();
        $this->assertStringNotContainsString($link->summary, $receipt->metadata);
        $this->assertStringNotContainsString($data['operations'][0]['content'], $receipt->metadata);
        $this->assertSame([], $memory->maintenanceSources('project', $ids, 0)['records']);
        $this->assertSame('assistant', MemoryLink::first()->source_type);
        $this->assertLessThanOrEqual(0.35, MemoryLink::first()->confidence);
    }

    public function test_concurrent_edit_blocks_replacement_and_new_id_cannot_resurrect_deleted_sources(): void
    {
        [$memory, $ids, $data, $link] = $this->fixture();
        $link->update(['summary' => 'Nutzerkorrektur: kein Zugriff.']);
        try {
            $memory->applyMaintenance($data, $ids);
            $this->fail('Stale source accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('Nutzerkorrektur: kein Zugriff.', $link->fresh()->summary);
        $this->assertSame(0, DB::table('memory_maintenance_receipts')->count());
        $memory->forget('project', 'original', $ids);
        $data['request_id'] = 'new-attempt';
        $this->expectException(HttpException::class);
        $memory->applyMaintenance($data, $ids);
    }

    public function test_owner_scope_and_quality_gate_are_required_and_secret_write_rolls_back(): void
    {
        [$memory, $ids, $data, $link] = $this->fixture();
        $other = User::factory()->create();
        $this->assertSame([], $memory->maintenanceSources('project', [...$ids, 'user_id' => $other->id], 0)['records']);
        foreach ([['quality' => [...$data['quality'], 'passed' => false]], ['consent' => false],
            ['operations' => [[...$data['operations'][0], 'content' => 'api_key: ghp_abcdefghijklmnopqrstuvwxyz123456']]]] as $change) {
            try {
                $memory->applyMaintenance(array_replace($data, $change), $ids);
                $this->fail('Unsafe change accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
            $this->assertNotNull($link->fresh());
        }
    }

    public function test_provider_status_is_owner_scoped_and_does_not_expose_provider_payloads(): void
    {
        [$memory, $ids, $data, $link] = $this->fixture();
        MemoryProjectionOutbox::create(['user_id' => $ids['user_id'], 'dataset' => $link->dataset, 'action' => 'improve',
            'dedupe_key' => hash('sha256', 'status'), 'status' => 'processing', 'attempts' => 1,
            'payload' => ['phase' => 'improve_polling', 'pipeline_run_id' => 'run-123', 'endpoint' => 'internal-secret'],
            'last_error' => 'credential-containing provider error']);
        $status = $memory->maintenanceStatus('project', $ids);
        $this->assertSame('run-123', $status[0]['run_id']);
        $this->assertTrue($status[0]['draining']);
        $this->assertTrue($status[0]['failed']);
        $this->assertStringNotContainsString('secret', json_encode($status));
        $this->assertStringNotContainsString('credential', json_encode($status));
        $this->assertSame([], $memory->maintenanceStatus('project', [...$ids, 'project_id' => 'other']));
    }

    public function test_lost_response_recovery_is_metadata_only_and_exact_owner_scope_bound(): void
    {
        [$memory, $ids, $data] = $this->fixture();
        $this->assertNull($memory->maintenanceReceipt('project', $ids, $data['request_id']));
        $result = $memory->applyMaintenance($data, $ids);
        $this->assertSame($result, $memory->maintenanceReceipt('project', $ids, $data['request_id']));
        $this->assertNull($memory->maintenanceReceipt('project', [...$ids, 'project_id' => 'other'], $data['request_id']));
        $other = User::factory()->create();
        $this->assertNull($memory->maintenanceReceipt('project', [...$ids, 'user_id' => $other->id], $data['request_id']));
        $this->assertArrayNotHasKey('content', $result);
    }

    public function test_replacement_cannot_resurrect_original_write_or_replace_legacy_without_ledger(): void
    {
        [$memory, $ids, $data, $link] = $this->fixture();
        DB::table('memory_write_events')->where('memory_link_id', $link->id)->delete();
        try {
            $memory->applyMaintenance($data, $ids);
            $this->fail('Legacy source without tombstone support accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertNotNull($link->fresh());
        $this->assertSame(0, DB::table('memory_maintenance_receipts')->count());
    }

    public function test_http_noop_is_durable_and_read_permission_does_not_grant_writes(): void
    {
        [$memory, $ids, $data] = $this->fixture();
        $read = ApiKey::mint(['user_id' => $ids['user_id'], 'name' => 'Read test', 'abilities' => ['brain.read'], 'active' => true]);
        $this->withHeader('X-Api-Key', $read['plain'])->postJson('/api/v1/memory/maintenance/sources', ['scope' => 'project', 'project_id' => 'p1'])->assertOk()->assertJsonCount(1, 'data.records');
        $data['project_id'] = 'p1';
        $data['operations'] = [['operation' => 'noop', 'targets' => [], 'sources' => [], 'content' => '', 'reason' => 'Unverändert']];
        $this->withHeader('X-Api-Key', $read['plain'])->postJson('/api/v1/memory/maintenance/apply', $data)->assertForbidden();
        $write = ApiKey::mint(['user_id' => $ids['user_id'], 'name' => 'Write test', 'abilities' => ['brain.write', 'brain.read'], 'active' => true]);
        $this->withHeader('X-Api-Key', $write['plain'])->postJson('/api/v1/memory/maintenance/apply', $data)->assertOk()->assertJsonPath('data.changed', 0);
        $this->withHeader('X-Api-Key', $write['plain'])->postJson('/api/v1/memory/maintenance/receipt', ['scope' => 'project', 'project_id' => 'p1', 'request_id' => $data['request_id']])->assertOk()->assertJsonPath('data.changed', 0);
        $this->assertSame(1, MemoryLink::count());
    }

    public function test_original_request_stays_forgotten_after_automatic_replacement(): void
    {
        [$memory, $ids, $data] = $this->fixture();
        $memory->applyMaintenance($data, $ids);
        try {
            $memory->remember(array_merge($ids, ['scope' => 'project', 'content' => 'Nur lesender Zugriff ist freigegeben.',
                'write_id' => 'original-write', 'external_id' => 'original', 'write_intent' => 'explicit', 'visibility' => 'syncable']));
            $this->fail('Forgotten source revived.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(1, MemoryLink::count());
        $this->assertSame('assistant', MemoryLink::first()->source_type);
    }
}
