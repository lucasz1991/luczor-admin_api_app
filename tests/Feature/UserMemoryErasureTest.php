<?php

namespace Tests\Feature;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountMemoryErasureService;
use App\Services\Cognee\CogneeClient;
use App\Services\LuczorMemoryService;
use App\Services\MemoryErasureIdentity;
use App\Services\MemoryLedgerIdentity;
use App\Services\MemoryProjectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class UserMemoryErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_delete_rekeys_a_live_v2_write_event_into_the_unlinkable_v3_domain(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $service = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));
        $link = $service->remember([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'account-delete-ledger-v2',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Dieser Inhalt wird zusammen mit dem Konto entfernt.',
            'status' => 'active',
            'retention' => 'durable',
            'write_id' => 'account-delete-ledger-v2-write',
            'project_to_cognee' => false,
        ]);
        $event = MemoryWriteEvent::query()->where('memory_link_id', $link->id)->sole();
        $liveIdempotency = $event->idempotency_key;
        $liveFingerprint = $event->write_fingerprint;
        $this->assertSame(2, $event->ledger_identity_version);

        $this->assertTrue($user->delete());

        $erased = $event->fresh();
        $this->assertNotNull($erased);
        $this->assertSame(3, $erased->ledger_identity_version);
        $this->assertSame(MemoryLedgerIdentity::erasedIdempotency($liveIdempotency), $erased->idempotency_key);
        $this->assertSame(MemoryLedgerIdentity::erasedFingerprint($liveFingerprint), $erased->write_fingerprint);
        $this->assertNull($erased->memory_link_id);
        $this->assertNull($erased->user_id);
        $this->assertSame('forgotten', $erased->state);
        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
    }

    public function test_user_delete_erases_owned_memory_but_preserves_shared_memory_and_tombstones(): void
    {
        Queue::fake();

        $tenant = Tenant::create(['name' => 'Workspace', 'slug' => 'workspace']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $userId = $user->id;
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';

        $project = $this->memory($user, 'project', 'project-owned', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $skill = $this->memory($user, 'skill', 'skill-owned', [
            'projection_status' => 'failed',
        ]);
        $private = $this->memory($user, 'private', 'private-owned', [
            'cognee_memory_id' => '9824c69d-9f1c-4892-a521-e4030c09ea98',
            'visibility' => 'private',
            'projection_status' => 'not_required',
        ]);
        $userScoped = $this->memory($user, 'user', 'user-owned', [
            'projection_status' => 'not_required',
        ]);
        $workspace = $this->memory($user, 'workspace', 'workspace-shared', [
            'tenant_id' => $tenant->id,
            'dataset' => "tenant:{$tenant->id}:workspace",
            'projection_status' => 'ready',
            'source_ref' => 'user-profile:'.$userId,
            'provenance' => [
                'actor_user_id' => $userId,
                'device' => ['client_id' => 'desktop-secret'],
                'captured_at' => now()->toIso8601String(),
                'policy_version' => 'memory-policy.v2',
            ],
            'meta' => [
                'memory_key' => 'shared.rule',
                'user_email' => $user->email,
                'nested' => ['device_id' => 'desktop-secret', 'safe' => 'kept'],
            ],
        ]);
        $global = $this->memory($user, 'global', 'global-shared', [
            'tenant_id' => null,
            'dataset' => 'global:curated',
            'projection_status' => 'ready',
        ]);

        foreach ([$project, $skill, $private, $userScoped, $workspace, $global] as $link) {
            MemoryWriteEvent::create([
                'idempotency_key' => $link->idempotency_key,
                'write_fingerprint' => $link->write_fingerprint,
                'memory_link_id' => $link->id,
                'user_id' => $userId,
                'dataset' => $link->dataset,
                'state' => 'committed',
            ]);
        }

        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $skill->id,
            'user_id' => $userId,
            'action' => 'upsert',
            'dataset' => $skill->dataset,
            'dedupe_key' => hash('sha256', 'skill-upsert'),
            'payload' => [
                'phase' => 'adding',
                'content_ciphertext' => 'encrypted-recovery-envelope',
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $userId,
            'action' => 'improve',
            'dataset' => $project->dataset,
            'dedupe_key' => hash('sha256', 'project-improve'),
            'payload' => ['phase' => 'new'],
            'status' => 'queued',
        ]);
        $existingDelete = MemoryProjectionOutbox::create([
            'memory_link_id' => $private->id,
            'user_id' => $userId,
            'action' => 'delete',
            'dataset' => $private->dataset,
            'dedupe_key' => hash('sha256', implode('|', [
                'delete',
                $private->dataset,
                $private->id,
                $private->cognee_memory_id,
            ])),
            'payload' => [
                'cognee_memory_id' => $private->cognee_memory_id,
                'content_hash' => $private->content_hash,
            ],
            'status' => 'failed',
            'last_error' => 'temporary provider error',
            'next_attempt_at' => now()->addDay(),
        ]);

        $this->assertTrue($user->delete());

        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseMissing('memory_links', ['id' => $project->id]);
        $this->assertDatabaseMissing('memory_links', ['id' => $skill->id]);
        $this->assertDatabaseMissing('memory_links', ['id' => $private->id]);
        $this->assertDatabaseMissing('memory_links', ['id' => $userScoped->id]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $workspace->id,
            'user_id' => null,
            'scope' => 'workspace',
            'summary' => $workspace->summary,
        ]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $global->id,
            'user_id' => null,
            'scope' => 'global',
            'summary' => $global->summary,
        ]);

        foreach ([$project, $skill, $private, $userScoped] as $owned) {
            $event = MemoryWriteEvent::query()
                ->where('dataset', MemoryErasureIdentity::dataset($owned->dataset))
                ->where('state', 'forgotten')
                ->firstOrFail();
            $this->assertNull($event->memory_link_id);
            $this->assertNull($event->user_id);
            $this->assertSame('forgotten', $event->state);
            $this->assertNotNull($event->forgotten_at);
            $this->assertSame(3, $event->ledger_identity_version);
            $this->assertSame(
                MemoryLedgerIdentity::erasedIdempotency((string) $owned->idempotency_key),
                $event->idempotency_key,
            );
            $this->assertSame(
                MemoryLedgerIdentity::erasedFingerprint((string) $owned->write_fingerprint),
                $event->write_fingerprint,
            );
            $this->assertStringStartsWith('erased:', $event->dataset);
            $this->assertSame(MemoryErasureIdentity::dataset($owned->dataset), $event->dataset);
            $this->assertNotSame('erased:'.hash('sha256', $owned->dataset), $event->dataset);
        }

        foreach ([$workspace, $global] as $shared) {
            $event = MemoryWriteEvent::query()
                ->where('idempotency_key', $shared->idempotency_key)
                ->firstOrFail();
            $this->assertSame($shared->id, $event->memory_link_id);
            $this->assertNull($event->user_id);
            $this->assertSame('committed', $event->state);
            $this->assertSame($shared->dataset, $event->dataset);
        }
        $workspace->refresh();
        $this->assertNull($workspace->client_id);
        $this->assertNull($workspace->source_ref);
        $this->assertArrayNotHasKey('actor_user_id', $workspace->provenance);
        $this->assertArrayNotHasKey('device', $workspace->provenance);
        $this->assertSame('memory-policy.v2', $workspace->provenance['policy_version']);
        $this->assertArrayHasKey('account_actor_erased_at', $workspace->provenance);
        $this->assertSame(['memory_key' => 'shared.rule'], $workspace->meta);

        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $project->dataset)
            ->firstOrFail();
        $this->assertNull($delete->user_id);
        $this->assertSame('queued', $delete->status);
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);

        $this->assertDatabaseHas('memory_projection_outbox', [
            'id' => $upsert->id,
            'user_id' => null,
            'status' => 'queued',
            'last_error' => null,
            'next_attempt_at' => null,
        ]);
        $upsert->refresh();
        $this->assertArrayNotHasKey('content_ciphertext', $upsert->payload);
        $this->assertSame('account_deleted', $upsert->payload['account_erasure_reason']);
        $this->assertSame('account_deleted', $upsert->payload['source_erasure_reason']);
        $this->assertSame($skill->id, $upsert->payload['provider_memory_link_id']);
        $this->assertSame($skill->content_hash, $upsert->payload['content_hash']);
        $this->assertArrayHasKey('content_snapshot_erased_at', $upsert->payload);
        $improve->refresh();
        $this->assertSame('done', $improve->status);
        $this->assertSame('erasure_cleanup_complete', $improve->payload['phase']);
        $this->assertSame('account_deleted', $improve->payload['erasure_reason']);
        $this->assertSame(MemoryErasureIdentity::dataset($project->dataset), $improve->dataset);
        $this->assertNull($improve->user_id);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'id' => $existingDelete->id,
            'user_id' => null,
            'status' => 'queued',
            'last_error' => null,
            'next_attempt_at' => null,
        ]);

        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $delete->id);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $upsert->id);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $existingDelete->id);
    }

    public function test_unknown_memory_scope_blocks_and_rolls_back_account_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $link = $this->memory($user, 'future-shared-scope', 'unknown-owner', [
            'cognee_memory_id' => '3eaa21d6-a09f-4511-887c-6790e4062df2',
        ]);

        try {
            $user->delete();
            $this->fail('Unknown memory ownership must block account deletion.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('ownership is undefined', $error->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'user_id' => $user->id]);
        $this->assertDatabaseCount('memory_projection_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_workspace_memory_without_a_matching_tenant_blocks_account_delete(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $link = $this->memory($user, 'workspace', 'ownerless-workspace', [
            'tenant_id' => null,
            'dataset' => 'tenant:personal:workspace',
        ]);

        try {
            $user->delete();
            $this->fail('Ownerless workspace memory must block account deletion.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('workspace memory', $error->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'user_id' => $user->id]);
    }

    public function test_erasure_locks_and_revalidates_the_user_before_taking_its_memory_snapshot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $insertedLinkId = null;
        $eventName = 'eloquent.retrieved: '.User::class;
        Event::listen($eventName, function (User $lockedUser) use ($user, &$insertedLinkId): void {
            if ((int) $lockedUser->id !== (int) $user->id || $insertedLinkId !== null) {
                return;
            }

            // Deterministic Remember/Delete interleaving: memory appearing
            // immediately after the account lock must be included in the
            // subsequent snapshot and cannot survive erasure.
            $insertedLinkId = $this->memory($lockedUser, 'project', 'inserted-after-user-lock')->id;
        });

        try {
            DB::transaction(fn () => app(AccountMemoryErasureService::class)->eraseBeforeDelete($user));
        } finally {
            Event::forget($eventName);
        }

        $this->assertNotNull($insertedLinkId);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('memory_links', ['id' => $insertedLinkId]);
    }

    public function test_erasure_locks_write_events_before_revalidating_locked_memory_links(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $link = $this->memory($user, 'project', 'event-before-link-lock');
        MemoryWriteEvent::create([
            'idempotency_key' => $link->idempotency_key,
            'write_fingerprint' => $link->write_fingerprint,
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'dataset' => $link->dataset,
            'state' => 'committed',
        ]);
        $linkRetrievals = 0;
        $eventRetrievals = 0;
        $eventWasLockedBeforeLinkRevalidation = false;
        $linkEvent = 'eloquent.retrieved: '.MemoryLink::class;
        $writeEvent = 'eloquent.retrieved: '.MemoryWriteEvent::class;
        Event::listen($writeEvent, function (MemoryWriteEvent $retrieved) use ($link, &$eventRetrievals): void {
            if ((int) $retrieved->memory_link_id === (int) $link->id) {
                $eventRetrievals++;
            }
        });
        Event::listen($linkEvent, function (MemoryLink $retrieved) use (
            $link,
            &$linkRetrievals,
            &$eventRetrievals,
            &$eventWasLockedBeforeLinkRevalidation,
        ): void {
            if ((int) $retrieved->id !== (int) $link->id) {
                return;
            }

            $linkRetrievals++;
            if ($linkRetrievals === 2) {
                // Existing event is retrieved once by firstOrCreate and again
                // by the ordered lockForUpdate query before this second,
                // revalidated MemoryLink retrieval.
                $eventWasLockedBeforeLinkRevalidation = $eventRetrievals >= 2;
            }
        });

        try {
            DB::transaction(fn () => app(AccountMemoryErasureService::class)->eraseBeforeDelete($user));
        } finally {
            Event::forget($writeEvent);
            Event::forget($linkEvent);
        }

        $this->assertSame(2, $linkRetrievals);
        $this->assertGreaterThanOrEqual(2, $eventRetrievals);
        $this->assertTrue($eventWasLockedBeforeLinkRevalidation);
    }

    public function test_bulk_query_delete_is_restricted_when_it_would_bypass_memory_erasure(): void
    {
        $user = User::factory()->create();
        $link = $this->memory($user, 'project', 'must-not-orphan');

        try {
            User::query()->whereKey($user->id)->delete();
            $this->fail('The database must reject a user delete that bypasses model erasure events.');
        } catch (QueryException) {
            // Expected: memory_links.user_id is RESTRICT, not NULL ON DELETE.
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'user_id' => $user->id]);
    }

    public function test_bulk_query_delete_is_restricted_for_outbox_only_and_event_only_accounts(): void
    {
        $outboxUser = User::factory()->create();
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $outboxUser->id,
            'action' => 'delete',
            'dataset' => "tenant:personal:user:{$outboxUser->id}:project",
            'dedupe_key' => hash('sha256', 'outbox-only-bulk-delete-guard'),
            'payload' => ['cognee_memory_id' => 'f349156a-f614-4d09-8c41-822612442953'],
            'status' => 'queued',
        ]);

        try {
            User::query()->whereKey($outboxUser->id)->delete();
            $this->fail('The outbox FK must reject a bulk user delete that bypasses account erasure.');
        } catch (QueryException) {
            // Expected: memory_projection_outbox.user_id is RESTRICT.
        }
        $this->assertDatabaseHas('users', ['id' => $outboxUser->id]);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'id' => $outbox->id,
            'user_id' => $outboxUser->id,
        ]);

        $eventUser = User::factory()->create();
        $event = MemoryWriteEvent::create([
            'idempotency_key' => hash('sha256', 'event-only-bulk-delete-guard'),
            'write_fingerprint' => hash('sha256', 'event-only-bulk-delete-fingerprint'),
            'memory_link_id' => null,
            'user_id' => $eventUser->id,
            'dataset' => "tenant:personal:user:{$eventUser->id}:project",
            'state' => 'forgotten',
            'forgotten_at' => now(),
        ]);

        try {
            User::query()->whereKey($eventUser->id)->delete();
            $this->fail('The write-event FK must reject a bulk user delete that bypasses account erasure.');
        } catch (QueryException) {
            // Expected: memory_write_events.user_id is RESTRICT.
        }
        $this->assertDatabaseHas('users', ['id' => $eventUser->id]);
        $this->assertDatabaseHas('memory_write_events', [
            'id' => $event->id,
            'user_id' => $eventUser->id,
        ]);
    }

    public function test_hardening_migration_replaces_production_foreign_keys_in_one_statement(): void
    {
        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $sqlFor = new \ReflectionMethod($migration, 'userForeignKeySql');

        foreach (['memory_links', 'memory_projection_outbox', 'memory_write_events'] as $table) {
            foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
                $sql = $sqlFor->invoke($migration, $driver, $table, 'restrict');
                $this->assertIsString($sql);
                $this->assertSame(1, substr_count(strtoupper($sql), 'ALTER TABLE'));
                $this->assertStringContainsString('DROP', strtoupper($sql));
                $this->assertStringContainsString('ADD CONSTRAINT', strtoupper($sql));
                $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($sql));
            }
        }

        $this->assertNull($sqlFor->invoke($migration, 'sqlite', 'memory_links', 'restrict'));

        $guardSqlFor = new \ReflectionMethod($migration, 'userGuardForeignKeySql');
        foreach (['memory_links', 'memory_projection_outbox', 'memory_write_events'] as $table) {
            foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
                $add = $guardSqlFor->invoke($migration, $driver, $table, 'add');
                $drop = $guardSqlFor->invoke($migration, $driver, $table, 'drop');
                $this->assertStringContainsString('ADD CONSTRAINT', strtoupper($add));
                $this->assertStringContainsString('ERASURE_GUARD', strtoupper($add));
                $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($add));
                $this->assertStringNotContainsString('DROP', strtoupper($add));
                $this->assertStringContainsString('DROP', strtoupper($drop));
                $this->assertStringContainsString('ERASURE_GUARD', strtoupper($drop));
            }
        }
    }

    public function test_hardening_migration_classifies_ownerless_outbox_only_history(): void
    {
        $formerUser = User::factory()->create();
        $formerUserId = $formerUser->id;
        $this->assertTrue($formerUser->delete());
        $dataset = "tenant:personal:user:{$formerUserId}:project:historical";
        $upsertDataId = 'f349156a-f614-4d09-8c41-822612442953';
        $providerMemoryLinkId = 123;
        $deleteDataId = '9824c69d-9f1c-4892-a521-e4030c09ea98';
        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'historical-linkless-upsert'),
            'payload' => [
                'phase' => 'ingested',
                'cognee_memory_id' => $upsertDataId,
                'provider_memory_link_id' => $providerMemoryLinkId,
                'content_hash' => hash('sha256', 'historical private content'),
                'content' => 'historical private content',
                'content_ciphertext' => 'historical-private-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'last_error' => 'legacy projection failure',
            'next_attempt_at' => now()->addHour(),
        ]);
        $delete = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'historical-linkless-delete'),
            'payload' => [
                'cognee_memory_id' => $deleteDataId,
                'content_hash' => hash('sha256', 'historical private content'),
                'content_ciphertext' => 'must-be-scrubbed',
            ],
            'status' => 'done',
            'processed_at' => now()->subDay(),
        ]);
        $liveImprovePayload = [
            'phase' => 'improve_polling',
            'pipeline_run_id' => '744a537f-bb81-4637-8287-79b5c55f0913',
            'cognee_dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            'cognee_instance_id' => '18eb4da1-32d8-4b27-9e68-f6e3c00adc67',
        ];
        $liveImprove = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'historical-linkless-live-improve'),
            'payload' => $liveImprovePayload,
            'status' => 'pending',
        ]);
        $improveDedupe = hash('sha256', 'historical-linkless-improve');
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => $improveDedupe,
            'payload' => [
                'phase' => 'improve_disabled',
                'content' => 'historical private content',
            ],
            'status' => 'done',
            'processed_at' => now()->subDay(),
        ]);
        $acknowledgedDedupe = hash('sha256', 'historical-linkless-acknowledged-delete');
        $acknowledged = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => $acknowledgedDedupe,
            'payload' => [
                'cognee_memory_id' => '3eaa21d6-a09f-4511-887c-6790e4062df2',
                'exact_forget_ack_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'done',
            'processed_at' => now()->subMinute(),
        ]);
        $opaqueDataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $opaque = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => 'luczor:v2:project:'.str_repeat('a', 64),
            'dedupe_key' => hash('sha256', 'opaque-ownerless-upsert'),
            'payload' => [
                'phase' => 'ingested',
                'cognee_memory_id' => $opaqueDataId,
                'content_hash' => hash('sha256', 'opaque historical private content'),
                'content_ciphertext' => 'opaque-private-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'last_error' => 'legacy opaque projection failure',
            'next_attempt_at' => now()->addHour(),
        ]);
        $unrelated = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => 'global:curated',
            'dedupe_key' => hash('sha256', 'unrelated-ownerless-system-outbox'),
            'payload' => ['phase' => 'improve_disabled', 'system' => true],
            'status' => 'done',
            'processed_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $scan = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessOutboxOnlyRows');
        $scan->invoke($migration);

        $upsert->refresh();
        $this->assertSame('pending', $upsert->status);
        $this->assertNull($upsert->next_attempt_at);
        $this->assertNull($upsert->last_error);
        $this->assertSame('legacy_ownerless_user_outbox', $upsert->payload['account_erasure_reason']);
        $this->assertArrayNotHasKey('content', $upsert->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $upsert->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $upsert->payload);

        $delete->refresh();
        $this->assertSame('pending', $delete->status);
        $this->assertSame($dataset, $delete->dataset);
        $this->assertSame($deleteDataId, $delete->payload['cognee_memory_id']);
        $this->assertSame('legacy_ownerless_user_outbox', $delete->payload['erasure_reason']);
        $this->assertArrayNotHasKey('content_ciphertext', $delete->payload);

        $compensation = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $dataset)
            ->where('payload->cognee_memory_id', $upsertDataId)
            ->firstOrFail();
        $this->assertSame('pending', $compensation->status);
        $this->assertSame('legacy_ownerless_user_outbox', $compensation->payload['erasure_reason']);
        $this->assertSame($providerMemoryLinkId, $compensation->payload['provider_memory_link_id']);
        $this->assertSame(hash('sha256', implode('|', [
            'delete',
            $dataset,
            $providerMemoryLinkId,
            $upsertDataId,
        ])), $compensation->dedupe_key);

        (new MemoryProjectionService(new CogneeClient('http://cognee:8000', 'key')))->process($upsert->id);
        $this->assertSame(1, MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $dataset)
            ->get()
            ->filter(fn (MemoryProjectionOutbox $row): bool => ($row->payload['cognee_memory_id'] ?? null) === $upsertDataId)
            ->count());

        foreach ([[$improve, $improveDedupe], [$acknowledged, $acknowledgedDedupe]] as [$terminal, $rawDedupe]) {
            $terminal->refresh();
            $this->assertNull($terminal->memory_link_id);
            $this->assertSame(MemoryErasureIdentity::dataset($dataset), $terminal->dataset);
            $this->assertSame(MemoryErasureIdentity::dedupe($rawDedupe), $terminal->dedupe_key);
            $this->assertSame('erasure_cleanup_complete', $terminal->payload['phase']);
            $this->assertSame('legacy_ownerless_user_outbox', $terminal->payload['erasure_reason']);
        }
        $this->assertArrayHasKey('exact_forget_ack_at', $acknowledged->payload);

        $liveImprove->refresh();
        $this->assertSame('pending', $liveImprove->status);
        $this->assertSame('improve_polling', $liveImprove->payload['phase']);
        $this->assertSame(
            'legacy_ownerless_user_outbox',
            $liveImprove->payload['account_erasure_reason'],
        );
        foreach ($liveImprovePayload as $key => $value) {
            $this->assertSame($value, $liveImprove->payload[$key]);
        }

        $opaque->refresh();
        $this->assertSame('pending', $opaque->status);
        $this->assertSame('legacy_ownerless_user_outbox', $opaque->payload['account_erasure_reason']);
        $this->assertArrayNotHasKey('content_ciphertext', $opaque->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $opaque->payload);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'action' => 'delete',
            'dataset' => $opaque->dataset,
            'status' => 'pending',
        ]);
        $this->assertSame(1, MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $opaque->dataset)
            ->get()
            ->filter(fn (MemoryProjectionOutbox $row): bool => ($row->payload['cognee_memory_id'] ?? null) === $opaqueDataId)
            ->count());

        $unrelated->refresh();
        $this->assertSame('global:curated', $unrelated->dataset);
        $this->assertSame(['phase' => 'improve_disabled', 'system' => true], $unrelated->payload);
    }

    public function test_hardening_migration_blocks_an_ambiguous_ownerless_live_add(): void
    {
        $formerUser = User::factory()->create();
        $formerUserId = $formerUser->id;
        $this->assertTrue($formerUser->delete());
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$formerUserId}:project:ambiguous-add",
            'dedupe_key' => hash('sha256', 'ambiguous-ownerless-live-add'),
            'payload' => [
                'phase' => 'adding',
                'content_hash' => hash('sha256', 'ambiguous historical private content'),
                'content_ciphertext' => 'historical-private-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'last_error' => 'lost add response',
            'next_attempt_at' => now()->addHour(),
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $scan = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessOutboxOnlyRows');

        try {
            $scan->invoke($migration);
            $this->fail('An ownerless live Add without its provider filename identity must block migration.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('without a verified provider_memory_link_id', $error->getMessage());
            $this->assertStringContainsString('do not guess', $error->getMessage());
        }

        $outbox->refresh();
        $this->assertSame('failed', $outbox->status);
        $this->assertSame('adding', $outbox->payload['phase']);
        $this->assertSame('historical-private-envelope', $outbox->payload['content_ciphertext']);
        $this->assertSame('lost add response', $outbox->last_error);
        $this->assertNotNull($outbox->next_attempt_at);
    }

    public function test_hardening_migration_blocks_a_linkless_live_add_without_content_identity(): void
    {
        $formerUser = User::factory()->create();
        $formerUserId = $formerUser->id;
        $this->assertTrue($formerUser->delete());
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$formerUserId}:project:missing-hash",
            'dedupe_key' => hash('sha256', 'ownerless-live-add-missing-hash'),
            'payload' => [
                'phase' => 'adding',
                'provider_memory_link_id' => 123,
                'content_ciphertext' => 'historical-private-envelope',
            ],
            'status' => 'failed',
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $scan = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessOutboxOnlyRows');
        try {
            $scan->invoke($migration);
            $this->fail('An ownerless live Add without its content identity must block migration.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('without a verified content_hash', $error->getMessage());
        }

        $outbox->refresh();
        $this->assertSame('failed', $outbox->status);
        $this->assertArrayNotHasKey('content_hash', $outbox->payload);
        $this->assertSame('historical-private-envelope', $outbox->payload['content_ciphertext']);
    }

    public function test_hardening_preflight_blocks_a_conflicting_linked_provider_identity(): void
    {
        $formerUser = User::factory()->create();
        $link = $this->memory($formerUser, 'project', 'conflicting-provider-link', [
            'user_id' => null,
            'projection_status' => 'processing',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'conflicting-linked-provider-identity'),
            'payload' => [
                'phase' => 'adding',
                'provider_memory_link_id' => $link->id + 100,
                'content_hash' => $link->content_hash,
            ],
            'status' => 'failed',
        ]);
        $this->assertTrue($formerUser->delete());

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        try {
            $migration->up();
            $this->fail('The preflight must stop before replacing a monotonic provider identity.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('conflicting provider filename identities', $error->getMessage());
        }

        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'user_id' => null]);
        $outbox->refresh();
        $this->assertSame($link->id + 100, $outbox->payload['provider_memory_link_id']);
        $this->assertSame('failed', $outbox->status);
    }

    public function test_hardening_migration_erases_preexisting_ownerless_user_memory(): void
    {
        $formerUser = User::factory()->create();
        $primaryDataId = 'f349156a-f614-4d09-8c41-822612442953';
        $duplicateDataId = '9824c69d-9f1c-4892-a521-e4030c09ea98';
        $orphan = $this->memory($formerUser, 'project', 'legacy-ownerless', [
            'user_id' => null,
            'cognee_memory_id' => $primaryDataId,
            'projection_status' => 'processing',
        ]);
        MemoryWriteEvent::create([
            'idempotency_key' => $orphan->idempotency_key,
            'write_fingerprint' => $orphan->write_fingerprint,
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'dataset' => $orphan->dataset,
            'state' => 'committed',
        ]);
        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-ownerless-upsert'),
            'payload' => [
                'phase' => 'adding',
                'cognee_memory_id' => $primaryDataId,
                'recovered_data_ids' => [$primaryDataId, $duplicateDataId],
                'content_ciphertext' => 'legacy-private-envelope',
            ],
            'status' => 'failed',
            'last_error' => 'lost response',
            'next_attempt_at' => now()->addHour(),
        ]);
        $doneUpsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-ownerless-done-upsert'),
            'payload' => [
                'phase' => 'add_absent_recovered',
                'content_hash' => $orphan->content_hash,
                'content_ciphertext' => 'stale-terminal-envelope',
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $liveUpsertPayload = [
            'phase' => 'polling',
            'content_hash' => $orphan->content_hash,
            'content_ciphertext' => 'live-provider-envelope',
            'pipeline_run_id' => '744a537f-bb81-4637-8287-79b5c55f0913',
            'cognee_dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            'cognee_instance_id' => '18eb4da1-32d8-4b27-9e68-f6e3c00adc67',
        ];
        $doneLiveUpsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'upsert',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-ownerless-done-live-upsert'),
            'payload' => $liveUpsertPayload,
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $doneImprove = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-ownerless-done-improve'),
            'payload' => ['phase' => 'improve_disabled', 'legacy_actor' => $formerUser->id],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $liveImprovePayload = [
            'phase' => 'improve_polling',
            'pipeline_run_id' => '3eaa21d6-a09f-4511-887c-6790e4062df2',
            'cognee_dataset_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
            'cognee_instance_id' => '6b988166-3ea8-4f20-9f37-26f84d247675',
        ];
        $liveImprove = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-ownerless-failed-live-improve'),
            'payload' => $liveImprovePayload,
            'status' => 'failed',
            'last_error' => 'provider timeout',
            'next_attempt_at' => now()->addHour(),
        ]);
        $this->assertTrue($formerUser->delete());

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $erase = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessUserMemory');
        $erase->invoke($migration);

        $this->assertDatabaseMissing('memory_links', ['id' => $orphan->id]);
        $event = MemoryWriteEvent::query()
            ->where('dataset', MemoryErasureIdentity::dataset($orphan->dataset))
            ->firstOrFail();
        $this->assertNull($event->memory_link_id);
        $this->assertSame('forgotten', $event->state);
        $this->assertSame(3, $event->ledger_identity_version);
        $this->assertSame(
            MemoryLedgerIdentity::erasedIdempotency((string) $orphan->idempotency_key),
            $event->idempotency_key,
        );
        $this->assertStringStartsWith('erased:', $event->dataset);
        $upsert->refresh();
        $this->assertSame('queued', $upsert->status);
        $this->assertNull($upsert->next_attempt_at);
        $this->assertArrayNotHasKey('content_ciphertext', $upsert->payload);
        $this->assertSame('legacy_ownerless_user_scope', $upsert->payload['account_erasure_reason']);
        $this->assertSame('legacy_ownerless_user_scope', $upsert->payload['source_erasure_reason']);
        $this->assertSame($orphan->id, $upsert->payload['provider_memory_link_id']);
        $this->assertSame($orphan->content_hash, $upsert->payload['content_hash']);
        $doneLiveUpsert->refresh();
        $this->assertSame('queued', $doneLiveUpsert->status);
        $this->assertSame($orphan->dataset, $doneLiveUpsert->dataset);
        $this->assertSame('polling', $doneLiveUpsert->payload['phase']);
        $this->assertSame($orphan->id, $doneLiveUpsert->payload['provider_memory_link_id']);
        $this->assertArrayNotHasKey('content_ciphertext', $doneLiveUpsert->payload);
        $this->assertNull($doneLiveUpsert->last_error);
        $this->assertNull($doneLiveUpsert->next_attempt_at);
        $this->assertNull($doneLiveUpsert->processed_at);
        foreach ($liveUpsertPayload as $key => $value) {
            if ($key !== 'content_ciphertext') {
                $this->assertSame($value, $doneLiveUpsert->payload[$key]);
            }
        }
        $liveImprove->refresh();
        $this->assertSame('queued', $liveImprove->status);
        $this->assertSame($orphan->dataset, $liveImprove->dataset);
        $this->assertSame('legacy_ownerless_user_scope', $liveImprove->payload['account_erasure_reason']);
        $this->assertNull($liveImprove->last_error);
        $this->assertNull($liveImprove->next_attempt_at);
        $this->assertNull($liveImprove->processed_at);
        foreach ($liveImprovePayload as $key => $value) {
            $this->assertSame($value, $liveImprove->payload[$key]);
        }
        $erasedDataset = MemoryErasureIdentity::dataset($orphan->dataset);
        foreach ([$doneUpsert, $doneImprove] as $terminal) {
            $terminal->refresh();
            $this->assertNull($terminal->memory_link_id);
            $this->assertSame($erasedDataset, $terminal->dataset);
            $this->assertSame([
                'phase' => 'erasure_cleanup_complete',
                'erasure_reason' => 'legacy_ownerless_user_scope',
            ], $terminal->payload);
        }
        $deletes = MemoryProjectionOutbox::query()->where('action', 'delete')->get();
        $this->assertCount(2, $deletes);
        $this->assertEqualsCanonicalizing(
            [$primaryDataId, $duplicateDataId],
            $deletes->pluck('payload')->map(fn (array $payload): string => $payload['cognee_memory_id'])->all(),
        );
        $this->assertTrue($deletes->every(
            fn (MemoryProjectionOutbox $delete): bool => $delete->status === 'queued'
                && ($delete->payload['erasure_reason'] ?? null) === 'legacy_ownerless_user_scope'
        ));
    }

    public function test_hardening_migration_resolves_ownerless_identity_without_guessing(): void
    {
        Queue::fake();
        $existingUser = User::factory()->create();
        $reattached = $this->memory($existingUser, 'user', 'existing-raw-owner', [
            'user_id' => null,
            'dataset' => "tenant:personal:user:{$existingUser->id}:private",
            'projection_status' => 'legacy_review_required',
        ]);

        $missingOpaqueActor = User::factory()->create();
        $missingOpaqueActorId = $missingOpaqueActor->id;
        $this->assertTrue($missingOpaqueActor->delete());
        $opaqueOrphan = $this->memory($missingOpaqueActor, 'project', 'missing-opaque-owner', [
            'user_id' => null,
            'dataset' => 'luczor:v2:project:'.str_repeat('a', 64),
            'provenance' => ['actor_user_id' => (string) $missingOpaqueActorId],
            'projection_status' => 'legacy_review_required',
        ]);

        $datasetActor = User::factory()->create();
        $provenanceActor = User::factory()->create();
        $datasetActorId = $datasetActor->id;
        $provenanceActorId = $provenanceActor->id;
        $this->assertTrue($datasetActor->delete());
        $this->assertTrue($provenanceActor->delete());
        $conflict = $this->memory($datasetActor, 'project', 'conflicting-owner-proof', [
            'user_id' => null,
            'dataset' => "tenant:personal:user:{$datasetActorId}:project:legacy",
            'provenance' => ['actor_user_id' => $provenanceActorId],
            'projection_status' => 'not_required',
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $erase = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessUserMemory');
        $erase->invoke($migration);

        $reattached->refresh();
        $this->assertSame($existingUser->id, $reattached->user_id);
        $this->assertSame('ownerless_scope_reattached', $reattached->write_reason);
        $this->assertTrue($existingUser->delete());
        $this->assertDatabaseMissing('memory_links', ['id' => $reattached->id]);

        $this->assertDatabaseMissing('memory_links', ['id' => $opaqueOrphan->id]);
        $conflict->refresh();
        $this->assertNull($conflict->user_id);
        $this->assertSame('legacy_review_required', $conflict->projection_status);
        $this->assertSame('ownerless_identity_conflict_review_required', $conflict->write_reason);
    }

    public function test_hardening_migration_erases_an_ownerless_duplicate_instead_of_failing_reattach(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:private";
        $ownerless = $this->memory($user, 'user', 'duplicate-after-recovery', [
            'user_id' => null,
            'dataset' => $dataset,
            'idempotency_key' => hash('sha256', 'ownerless-duplicate-idempotency'),
            'write_fingerprint' => hash('sha256', 'ownerless-duplicate-fingerprint'),
        ]);
        $owned = $this->memory($user, 'user', 'duplicate-after-recovery', [
            'dataset' => $dataset,
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $erase = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessUserMemory');
        $erase->invoke($migration);

        $this->assertDatabaseMissing('memory_links', ['id' => $ownerless->id]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $owned->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_hardening_migration_redacts_done_deletes_and_preserves_processing_cleanup_identity(): void
    {
        $formerUser = User::factory()->create();
        $formerUserId = $formerUser->id;
        $this->assertTrue($formerUser->delete());
        $orphan = $this->memory($formerUser, 'project', 'legacy-delete-history', [
            'user_id' => null,
            'dataset' => "tenant:personal:user:{$formerUserId}:project:legacy",
            'cognee_memory_id' => null,
        ]);
        $doneDedupe = hash('sha256', 'legacy-done-delete-history');
        $done = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $orphan->dataset,
            'dedupe_key' => $doneDedupe,
            'payload' => [
                'cognee_memory_id' => '9824c69d-9f1c-4892-a521-e4030c09ea98',
                'content_hash' => $orphan->content_hash,
                'exact_forget_ack_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $unacknowledgedDataId = 'f349156a-f614-4d09-8c41-822612442953';
        $unacknowledgedDedupe = hash('sha256', 'legacy-unacknowledged-done-delete-history');
        $unacknowledged = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $orphan->dataset,
            'dedupe_key' => $unacknowledgedDedupe,
            'payload' => [
                'cognee_memory_id' => $unacknowledgedDataId,
                'content_hash' => $orphan->content_hash,
                'content_ciphertext' => 'must-be-scrubbed',
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $processingDataId = '3eaa21d6-a09f-4511-887c-6790e4062df2';
        $processing = MemoryProjectionOutbox::create([
            'memory_link_id' => $orphan->id,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $orphan->dataset,
            'dedupe_key' => hash('sha256', 'legacy-processing-delete-history'),
            'payload' => [
                'cognee_memory_id' => $processingDataId,
                'content_hash' => $orphan->content_hash,
            ],
            'status' => 'processing',
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $erase = new \ReflectionMethod($migration, 'erasePreexistingOwnerlessUserMemory');
        $erase->invoke($migration);

        $this->assertDatabaseMissing('memory_links', ['id' => $orphan->id]);
        $done->refresh();
        $this->assertNull($done->memory_link_id);
        $this->assertSame(MemoryErasureIdentity::dataset($orphan->dataset), $done->dataset);
        $this->assertSame(MemoryErasureIdentity::dedupe($doneDedupe), $done->dedupe_key);
        $this->assertNotSame($doneDedupe, $done->dedupe_key);
        $this->assertSame('erasure_cleanup_complete', $done->payload['phase']);
        $this->assertSame('legacy_ownerless_user_scope', $done->payload['erasure_reason']);
        $this->assertArrayHasKey('exact_forget_ack_at', $done->payload);

        $unacknowledged->refresh();
        $this->assertSame('queued', $unacknowledged->status);
        $this->assertSame($orphan->id, $unacknowledged->memory_link_id);
        $this->assertSame($orphan->dataset, $unacknowledged->dataset);
        $this->assertSame($unacknowledgedDedupe, $unacknowledged->dedupe_key);
        $this->assertSame($unacknowledgedDataId, $unacknowledged->payload['cognee_memory_id']);
        $this->assertSame('legacy_ownerless_user_scope', $unacknowledged->payload['erasure_reason']);
        $this->assertArrayNotHasKey('content_ciphertext', $unacknowledged->payload);
        $this->assertNull($unacknowledged->processed_at);

        $processing->refresh();
        $this->assertSame('processing', $processing->status);
        $this->assertSame($orphan->id, $processing->memory_link_id);
        $this->assertSame($orphan->dataset, $processing->dataset);
        $this->assertSame($processingDataId, $processing->payload['cognee_memory_id']);
        $this->assertSame('legacy_ownerless_user_scope', $processing->payload['erasure_reason']);
    }

    public function test_hardening_migration_detaches_only_shared_rows_with_a_proven_missing_actor(): void
    {
        $tenant = Tenant::create(['name' => 'Historical workspace', 'slug' => 'historical-workspace']);
        $formerActor = User::factory()->create();
        $formerActorId = $formerActor->id;
        $this->assertTrue($formerActor->delete());
        $existingActor = User::factory()->create();

        $proven = $this->memory($formerActor, 'workspace', 'proven-shared-orphan', [
            'user_id' => null,
            'tenant_id' => $tenant->id,
            'client_id' => 'former-device',
            'source_ref' => 'profile:former-actor',
            'provenance' => [
                'actor_user_id' => $formerActorId,
                'captured_at' => '2026-08-23T12:00:00+00:00',
                'policy_version' => 'memory-policy.v2',
                'personal_note' => 'must disappear',
            ],
            'meta' => [
                'source_external_id' => 'shared.logical.rule',
                'memory_key' => 'shared.rule',
                'email' => 'former@example.test',
            ],
        ]);
        $ambiguous = $this->memory($formerActor, 'global', 'ambiguous-system-shared', [
            'user_id' => null,
            'client_id' => 'system-client',
            'source_ref' => 'system-import',
            'provenance' => ['source' => 'curated-system'],
            'meta' => ['curator' => 'system'],
        ]);
        $existing = $this->memory($existingActor, 'workspace', 'existing-actor-shared', [
            'user_id' => null,
            'client_id' => 'active-device',
            'source_ref' => 'active-profile',
            'provenance' => ['actor_user_id' => (string) $existingActor->id, 'personal_note' => 'still owned'],
            'meta' => ['email' => $existingActor->email],
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000004_harden_user_memory_erasure_foreign_keys.php'
        );
        $detach = new \ReflectionMethod($migration, 'detachPreexistingOwnerlessSharedActors');
        $detach->invoke($migration);

        $proven->refresh();
        $this->assertNull($proven->client_id);
        $this->assertNull($proven->source_ref);
        $this->assertSame('2026-08-23T12:00:00+00:00', $proven->provenance['captured_at']);
        $this->assertSame('memory-policy.v2', $proven->provenance['policy_version']);
        $this->assertArrayHasKey('account_actor_erased_at', $proven->provenance);
        $this->assertArrayNotHasKey('actor_user_id', $proven->provenance);
        $this->assertArrayNotHasKey('personal_note', $proven->provenance);
        $this->assertSame([
            'source_external_id' => 'shared.logical.rule',
            'memory_key' => 'shared.rule',
        ], $proven->meta);

        $ambiguous->refresh();
        $this->assertSame('system-client', $ambiguous->client_id);
        $this->assertSame('system-import', $ambiguous->source_ref);
        $this->assertSame(['source' => 'curated-system'], $ambiguous->provenance);
        $this->assertSame(['curator' => 'system'], $ambiguous->meta);

        $existing->refresh();
        $this->assertSame('active-device', $existing->client_id);
        $this->assertSame('active-profile', $existing->source_ref);
        $this->assertSame((string) $existingActor->id, $existing->provenance['actor_user_id']);
        $this->assertSame(['email' => $existingActor->email], $existing->meta);
    }

    public function test_shared_logical_identity_survives_actor_erasure_for_the_next_tenant_user(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['name' => 'Shared workspace', 'slug' => 'shared-workspace']);
        $firstActor = User::factory()->create(['tenant_id' => $tenant->id]);
        $nextActor = User::factory()->create(['tenant_id' => $tenant->id]);
        $memory = new LuczorMemoryService(new CogneeClient);
        $base = [
            'scope' => 'workspace',
            'tenant_id' => $tenant->id,
            'user_id' => $firstActor->id,
            'client_id' => 'desktop-a',
            'external_id' => 'workspace-style-rule',
            'memory_key' => 'workspace.style.rule',
            'retention' => 'durable',
            'visibility' => 'syncable',
            'project_to_cognee' => false,
        ];

        $first = $memory->remember($base + [
            'write_id' => 'workspace-style-v1',
            'content' => 'Use compact answers.',
        ]);
        $second = $memory->remember($base + [
            'write_id' => 'workspace-style-v2',
            'content' => 'Use compact answers with concrete evidence.',
        ]);
        $secondMeta = $second->meta;
        $secondMeta['personal_note'] = 'created by first actor';
        $second->update([
            'source_ref' => 'profile:'.$firstActor->id,
            'provenance' => [
                'actor_user_id' => $firstActor->id,
                'device_id' => 'desktop-a',
                'policy_version' => 'memory-policy.v2',
            ],
            'meta' => $secondMeta,
        ]);

        $this->assertTrue($firstActor->delete());
        $second->refresh();
        $this->assertSame([
            'source_external_id' => 'workspace-style-rule',
            'memory_key' => 'workspace.style.rule',
        ], $second->meta);

        $third = $memory->remember([
            ...$base,
            'user_id' => $nextActor->id,
            'client_id' => 'desktop-b',
            'write_id' => 'workspace-style-v3',
            'content' => 'Use compact, evidence-backed German answers.',
        ]);

        $this->assertSame($second->id, $third->supersedes_id);
        $this->assertSame(1, MemoryLink::query()
            ->where('scope', 'workspace')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(3, MemoryLink::query()
            ->where('scope', 'workspace')
            ->where('tenant_id', $tenant->id)
            ->count());
        $this->assertTrue($memory->forget('workspace', 'workspace-style-rule', [
            'tenant_id' => $tenant->id,
            'user_id' => $nextActor->id,
        ]));
        $this->assertSame(0, MemoryLink::query()
            ->where('scope', 'workspace')
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    public function test_account_delete_classifies_outboxes_left_dangling_by_normal_forget(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $memory = new LuczorMemoryService(new CogneeClient);
        $link = $memory->remember([
            'scope' => 'project',
            'user_id' => $user->id,
            'client_id' => 'desktop-'.$user->id,
            'external_id' => 'forgotten-before-account-delete',
            'write_id' => 'forgotten-before-account-delete-v1',
            'content' => 'Memory forgotten-before-account-delete',
            'project_to_cognee' => false,
        ]);
        $link->update([
            'cognee_memory_id' => 'f349156a-f614-4d09-8c41-822612442953',
            'projection_status' => 'ready',
        ]);
        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'forgotten-dangling-upsert'),
            'payload' => [
                'phase' => 'adding',
                'content_hash' => $link->content_hash,
                'content' => $link->summary,
                'content_ciphertext' => 'private-recovery-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'last_error' => 'ambiguous add response',
            'next_attempt_at' => now()->addHour(),
        ]);
        $this->assertTrue($memory->forget('project', $link->external_id, [
            'user_id' => $user->id,
        ]));
        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('memory_link_id', $link->id)
            ->firstOrFail();

        $this->assertTrue($user->delete());

        foreach ([$upsert, $delete] as $residual) {
            $residual->refresh();
            $this->assertNull($residual->user_id);
            $this->assertSame($link->id, $residual->memory_link_id);
            $this->assertSame('queued', $residual->status);
            $this->assertNull($residual->last_error);
            $this->assertNull($residual->next_attempt_at);
            $this->assertArrayNotHasKey('content', $residual->payload);
            $this->assertArrayNotHasKey('content_ciphertext', $residual->payload);
            $this->assertArrayNotHasKey('content_snapshot_expires_at', $residual->payload);
        }
        $this->assertSame('account_deleted', $upsert->payload['account_erasure_reason']);
        $this->assertSame($link->id, $upsert->payload['provider_memory_link_id']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);
        $this->assertSame(
            'f349156a-f614-4d09-8c41-822612442953',
            $delete->payload['cognee_memory_id'],
        );
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job): bool => $job->outboxId === $upsert->id);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job): bool => $job->outboxId === $delete->id);
    }

    public function test_account_delete_blocks_an_ambiguous_linkless_live_add(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:ambiguous-add",
            'dedupe_key' => hash('sha256', 'ambiguous-linkless-account-add'),
            'payload' => [
                'phase' => 'adding',
                'content_hash' => hash('sha256', 'ambiguous account content'),
                'content_ciphertext' => 'account-private-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'last_error' => 'lost add response',
            'next_attempt_at' => now()->addHour(),
        ]);

        try {
            $user->delete();
            $this->fail('Account deletion must block when exact Cognee Add recovery is impossible.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('without a verified provider_memory_link_id', $error->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $outbox->refresh();
        $this->assertSame($user->id, $outbox->user_id);
        $this->assertSame('failed', $outbox->status);
        $this->assertSame('adding', $outbox->payload['phase']);
        $this->assertSame('account-private-envelope', $outbox->payload['content_ciphertext']);
        $this->assertSame('lost add response', $outbox->last_error);
        $this->assertNotNull($outbox->next_attempt_at);
        Queue::assertNothingPushed();
    }

    public function test_account_delete_blocks_a_linkless_live_add_without_content_identity(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:missing-hash",
            'dedupe_key' => hash('sha256', 'account-live-add-missing-hash'),
            'payload' => [
                'phase' => 'adding',
                'provider_memory_link_id' => 123,
                'content_ciphertext' => 'account-private-envelope',
            ],
            'status' => 'failed',
        ]);

        try {
            $user->delete();
            $this->fail('Account deletion must block when the Cognee Add content identity is missing.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('without a verified content_hash', $error->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $outbox->refresh();
        $this->assertSame($user->id, $outbox->user_id);
        $this->assertArrayNotHasKey('content_hash', $outbox->payload);
        $this->assertSame('account-private-envelope', $outbox->payload['content_ciphertext']);
        Queue::assertNothingPushed();
    }

    public function test_done_delete_keeps_its_provider_identity_until_exact_forget_is_acknowledged(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $link = $this->memory($user, 'project', 'retained-delete-identity', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $link->dataset,
            $link->id,
            $dataId,
        ]));
        $delete = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $link->dataset,
            'dedupe_key' => $dedupe,
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => $link->content_hash,
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);

        $this->assertTrue($user->delete());

        $delete->refresh();
        $this->assertSame('queued', $delete->status);
        $this->assertNull($delete->user_id);
        $this->assertSame($link->id, $delete->memory_link_id);
        $this->assertSame($link->dataset, $delete->dataset);
        $this->assertSame($dedupe, $delete->dedupe_key);
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);
        $this->assertNull($delete->processed_at);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job): bool => $job->outboxId === $delete->id);
    }

    public function test_account_erasure_keeps_a_live_improve_turn_until_terminal_before_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $link = $this->memory($user, 'project', 'delete-after-live-improve', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'account-erasure-live-improve'),
            'payload' => [
                'phase' => 'improve_polling',
                'pipeline_run_id' => $runId,
                'cognee_dataset_id' => $datasetId,
                'cognee_instance_id' => $instanceId,
                'improve_started_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'pending',
        ]);

        $this->assertTrue($user->delete());
        $improve->refresh();
        $this->assertSame('queued', $improve->status);
        $this->assertSame('improve_polling', $improve->payload['phase']);
        $this->assertSame('account_deleted', $improve->payload['account_erasure_reason']);
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $link->dataset)
            ->firstOrFail();

        $cognee = new class($datasetId, $runId, $instanceId) extends CogneeClient
        {
            public bool $completed = false;

            public int $forgets = 0;

            public function __construct(
                private string $datasetIdValue,
                private string $runIdValue,
                private string $instanceIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'improve_pipeline',
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => $this->completed ? 'DATASET_PROCESSING_COMPLETED' : 'DATASET_PROCESSING_RUNNING',
                ];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => $this->datasetIdValue,
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);

        $projection->process($delete->id);
        $this->assertSame('pending', $delete->fresh()->status);
        $this->assertSame(0, $cognee->forgets);

        $cognee->completed = true;
        $projection->process($improve->id);
        $improve->refresh();
        $this->assertSame('done', $improve->status);
        $this->assertSame('erasure_cleanup_complete', $improve->payload['phase']);

        $delete->refresh()->update(['status' => 'queued', 'next_attempt_at' => null]);
        $projection->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->fresh()->status);
    }

    public function test_account_erasure_does_not_relaunch_an_ambiguous_improve_after_wrapper_restart(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstanceId = '46fdc252-d660-4690-8220-04504368422c';
        $launchKey = hash('sha256', 'erased-improve-lost-response');
        $link = $this->memory($user, 'project', 'delete-after-restarted-improve', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'account-erasure-restarted-improve'),
            'payload' => [
                'phase' => 'improve_launching',
                'launch_key' => $launchKey,
                'launch_generation' => 1,
                'launch_intent_at' => now()->subMinute()->toIso8601String(),
                'cognee_probe_instance_id' => $oldInstanceId,
            ],
            'status' => 'pending',
        ]);

        $this->assertTrue($user->delete());
        $improve->refresh();
        $this->assertSame('account_deleted', $improve->payload['account_erasure_reason']);
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $link->dataset)
            ->firstOrFail();

        $cognee = new class($newInstanceId) extends CogneeClient
        {
            public int $probes = 0;

            public int $improves = 0;

            public int $forgets = 0;

            public function __construct(private string $instanceIdValue) {}

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                $this->probes++;

                return $this->instanceIdValue;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->improves++;

                return [];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);

        $projection->process($improve->id);
        $improve->refresh();
        $this->assertSame(1, $cognee->probes);
        $this->assertSame(0, $cognee->improves);
        $this->assertSame('done', $improve->status);
        $this->assertSame('erasure_cleanup_complete', $improve->payload['phase']);

        $projection->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->fresh()->status);
    }

    public function test_account_erasure_replays_only_the_current_improve_generation_after_an_older_boot_failed(): void
    {
        Queue::fake();
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $currentInstanceId = '46fdc252-d660-4690-8220-04504368422c';
        $link = $this->memory($user, 'project', 'delete-after-same-boot-improve', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'account-erasure-same-boot-improve'),
            'payload' => [
                'phase' => 'new',
                'launch_generation' => 1,
                'cognee_probe_instance_id' => $oldInstanceId,
                'cognee_instance_id' => $oldInstanceId,
                'improve_started_at' => now()->subHour()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);

        $cognee = new class($datasetId, $runId, $currentInstanceId) extends CogneeClient
        {
            public int $improves = 0;

            public int $forgets = 0;

            public function __construct(
                private string $datasetIdValue,
                private string $runIdValue,
                private string $instanceIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceIdValue;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->improves++;
                if ($this->improves === 1) {
                    throw new \RuntimeException('The guarded Improve response was lost.');
                }

                return [
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'PipelineRunStarted',
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'improve_pipeline',
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'DATASET_PROCESSING_COMPLETED',
                ];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => $this->datasetIdValue,
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);

        try {
            $projection->process($improve->id);
            $this->fail('The first guarded Improve response must remain ambiguous.');
        } catch (\RuntimeException) {
            // Generation 2 may be live on the current boot and must be retried
            // with exactly its persisted probe identity.
        }
        $improve->refresh();
        $this->assertSame('failed', $improve->status);
        $this->assertSame('improve_launching', $improve->payload['phase']);
        $this->assertSame(2, $improve->payload['launch_generation']);
        $this->assertSame($currentInstanceId, $improve->payload['cognee_probe_instance_id']);
        $this->assertArrayNotHasKey('cognee_instance_id', $improve->payload);

        $this->assertTrue($user->delete());
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $link->dataset)
            ->firstOrFail();

        $projection->process($improve->id);
        $improve->refresh();
        $this->assertSame(2, $cognee->improves);
        $this->assertSame('pending', $improve->status);
        $this->assertSame('improve_polling', $improve->payload['phase']);

        $projection->process($delete->id);
        $this->assertSame('pending', $delete->fresh()->status);
        $this->assertSame(0, $cognee->forgets);

        $improve->update(['status' => 'queued', 'next_attempt_at' => null]);
        $projection->process($improve->id);
        $this->assertSame('done', $improve->fresh()->status);

        $delete->refresh()->update(['status' => 'queued', 'next_attempt_at' => null]);
        $projection->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->fresh()->status);
    }

    public function test_shared_workspace_improve_only_detaches_the_deleted_actor(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['name' => 'Shared improve workspace', 'slug' => 'shared-improve-workspace']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $workspace = $this->memory($user, 'workspace', 'shared-improve-source', [
            'tenant_id' => $tenant->id,
            'dataset' => "tenant:{$tenant->id}:workspace",
            'projection_status' => 'ready',
        ]);
        $payload = [
            'phase' => 'improve_polling',
            'pipeline_run_id' => '744a537f-bb81-4637-8287-79b5c55f0913',
            'cognee_dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            'cognee_instance_id' => '18eb4da1-32d8-4b27-9e68-f6e3c00adc67',
        ];
        $improve = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $workspace->dataset,
            'dedupe_key' => hash('sha256', 'shared-workspace-live-improve'),
            'payload' => $payload,
            'status' => 'pending',
        ]);

        $this->assertTrue($user->delete());

        $workspace->refresh();
        $improve->refresh();
        $this->assertNull($workspace->user_id);
        $this->assertNull($improve->user_id);
        $this->assertSame('pending', $improve->status);
        $this->assertSame($workspace->dataset, $improve->dataset);
        $this->assertSame($payload, $improve->payload);
        Queue::assertNotPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job): bool => $job->outboxId === $improve->id);
    }

    public function test_linkless_residual_outboxes_are_scrubbed_and_anonymized_after_exact_forget(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project";
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'linkless-account-upsert'),
            'payload' => [
                'phase' => 'new',
                'content_hash' => hash('sha256', 'linkless private content'),
                'content_ciphertext' => 'private-recovery-envelope',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $delete = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'linkless-account-delete'),
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => hash('sha256', 'linkless private content'),
                'content' => 'linkless private content',
            ],
            'status' => 'queued',
        ]);
        $rawUpsertDedupe = $upsert->dedupe_key;
        $rawDeleteDedupe = $delete->dedupe_key;

        $this->assertTrue($user->delete());
        $upsert->refresh();
        $delete->refresh();
        $this->assertNull($upsert->user_id);
        $this->assertNull($delete->user_id);
        $this->assertSame('account_deleted', $upsert->payload['account_erasure_reason']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);
        $this->assertArrayNotHasKey('content_ciphertext', $upsert->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $upsert->payload);
        $this->assertArrayNotHasKey('content', $delete->payload);
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);

        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);
        $projection->process($delete->id);
        $projection->process($upsert->id);

        $this->assertSame(1, $cognee->forgets);
        foreach ([[$delete, $rawDeleteDedupe], [$upsert, $rawUpsertDedupe]] as [$terminal, $rawDedupe]) {
            $terminal->refresh();
            $this->assertSame('done', $terminal->status);
            $this->assertNull($terminal->memory_link_id);
            $this->assertNull($terminal->user_id);
            $this->assertSame(MemoryErasureIdentity::dataset($dataset), $terminal->dataset);
            $this->assertSame(MemoryErasureIdentity::dedupe($rawDedupe), $terminal->dedupe_key);
            $this->assertSame('erasure_cleanup_complete', $terminal->payload['phase']);
            $this->assertSame('account_deleted', $terminal->payload['erasure_reason']);
        }
    }

    public function test_exact_forget_ack_terminally_anonymizes_account_erasure_outbox_history(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $link = $this->memory($user, 'project', 'terminal-cleanup', [
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $rawDataset = $link->dataset;
        $linkId = $link->id;

        $this->assertTrue($user->delete());
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $rawDataset)
            ->firstOrFail();
        $rawDeleteDedupe = $delete->dedupe_key;
        $rawLegacyDedupe = hash('sha256', 'legacy-erasure-terminal-history');
        $legacyTerminal = MemoryProjectionOutbox::create([
            'memory_link_id' => $linkId,
            'user_id' => null,
            'action' => 'improve',
            'dataset' => $rawDataset,
            'dedupe_key' => $rawLegacyDedupe,
            'payload' => [
                'phase' => 'account_erased',
                'account_erasure_reason' => 'account_deleted',
                'content_hash' => $link->content_hash,
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);

        $projection->process($delete->id);

        $erasedDataset = MemoryErasureIdentity::dataset($rawDataset);
        $this->assertNotSame('erased:'.hash('sha256', $rawDataset), $erasedDataset);
        $delete->refresh();
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->status);
        $this->assertNull($delete->memory_link_id);
        $this->assertSame($erasedDataset, $delete->dataset);
        $this->assertSame(MemoryErasureIdentity::dedupe($rawDeleteDedupe), $delete->dedupe_key);
        $this->assertNotSame(hash('sha256', $rawDeleteDedupe), $delete->dedupe_key);
        $this->assertSame('erasure_cleanup_complete', $delete->payload['phase']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);
        $this->assertArrayHasKey('exact_forget_ack_at', $delete->payload);
        $this->assertArrayNotHasKey('cognee_memory_id', $delete->payload);
        $this->assertArrayNotHasKey('content_hash', $delete->payload);

        $legacyTerminal->refresh();
        $this->assertNull($legacyTerminal->memory_link_id);
        $this->assertSame($erasedDataset, $legacyTerminal->dataset);
        $this->assertSame(MemoryErasureIdentity::dedupe($rawLegacyDedupe), $legacyTerminal->dedupe_key);
        $this->assertSame([
            'phase' => 'erasure_cleanup_complete',
            'erasure_reason' => 'account_deleted',
        ], $legacyTerminal->payload);

        $projection->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
    }

    public function test_in_flight_upsert_cannot_restore_a_scrubbed_snapshot_after_account_erasure(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $link = $this->memory($user, 'project', 'in-flight-erasure', [
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'in-flight-account-erasure'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $dataId = 'f349156a-f614-4d09-8c41-822612442953';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $cognee = new class($user, $dataId, $datasetId) extends CogneeClient
        {
            public int $adds = 0;

            public function __construct(
                private User $actor,
                private string $dataIdValue,
                private string $datasetIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->adds++;
                // Deterministic interleaving: the worker still owns its stale
                // plaintext/ciphertext variables while account erasure commits.
                $this->actor->delete();

                return [
                    'dataset_id' => $this->datasetIdValue,
                    'data_ingestion_info' => [['data_id' => $this->dataIdValue]],
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $outbox->refresh();
        $this->assertSame(1, $cognee->adds);
        $this->assertSame('done', $outbox->status);
        $this->assertNull($outbox->user_id);
        $this->assertNull($outbox->memory_link_id);
        $this->assertSame(MemoryErasureIdentity::dataset($link->dataset), $outbox->dataset);
        $this->assertSame('erasure_cleanup_complete', $outbox->payload['phase']);
        $this->assertSame('account_deleted', $outbox->payload['erasure_reason']);
        $this->assertArrayNotHasKey('content', $outbox->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $outbox->payload);
        $delete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $link->dataset)
            ->firstOrFail();
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        $this->assertSame('account_deleted', $delete->payload['erasure_reason']);
    }

    /** @param array<string,mixed> $overrides */
    private function memory(User $user, string $scope, string $externalId, array $overrides = []): MemoryLink
    {
        $summary = "Memory {$externalId}";
        $attributes = [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'client_id' => 'desktop-'.$user->id,
            'external_id' => $externalId,
            'scope' => $scope,
            'dataset' => "tenant:personal:user:{$user->id}:{$scope}",
            'type' => 'note',
            'visibility' => 'syncable',
            'staleness' => 'fresh',
            'status' => 'active',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'importance' => 0.7,
            'confidence' => 0.9,
            'summary' => $summary,
            'content_hash' => hash('sha256', $summary),
            'idempotency_key' => MemoryLedgerIdentity::idempotency(
                hash('sha256', "idempotency:{$user->id}:{$externalId}"),
            ),
            'write_fingerprint' => MemoryLedgerIdentity::fingerprint(
                hash('sha256', "fingerprint:{$user->id}:{$externalId}"),
            ),
            'ledger_identity_version' => 2,
            'source_type' => 'user',
            'projection_status' => 'not_required',
        ];

        return MemoryLink::create(array_merge($attributes, $overrides));
    }
}
