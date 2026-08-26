<?php

namespace Tests\Feature;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\User;
use App\Services\Cognee\CogneeClient;
use App\Services\MemoryLedgerIdentity;
use App\Services\MemoryProjectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class MemoryLedgerHardeningMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sha_ledger_and_link_identities_are_rekeyed_without_their_preimage(): void
    {
        $this->disableSqliteVersionGuards();
        $user = $this->createUser();
        $rawIdempotency = hash('sha256', 'enumerable-user-write-id');
        $rawFingerprint = hash('sha256', 'enumerable-user-write-fingerprint');
        $link = MemoryLink::create([
            'user_id' => $user->getKey(),
            'external_id' => 'legacy-ledger-memory',
            'scope' => 'project',
            'dataset' => 'user:'.$user->getKey().':project:p1',
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.9,
            'summary' => 'Legacy ledger memory.',
            'content_hash' => hash('sha256', 'Legacy ledger memory.'),
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => $rawFingerprint,
            'ledger_identity_version' => 1,
            'valid_from' => now(),
            'projection_status' => 'not_required',
        ]);
        $event = MemoryWriteEvent::create([
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => $rawFingerprint,
            'memory_link_id' => $link->getKey(),
            'user_id' => $user->getKey(),
            'dataset' => $link->getAttribute('dataset'),
            'state' => 'committed',
            'ledger_identity_version' => 1,
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000005_harden_memory_write_ledger_identities.php'
        );
        $migration->up();

        $expectedIdempotency = MemoryLedgerIdentity::idempotency($rawIdempotency);
        $expectedFingerprint = MemoryLedgerIdentity::fingerprint($rawFingerprint);
        $this->assertSame($expectedIdempotency, $event->fresh()->getAttribute('idempotency_key'));
        $this->assertSame($expectedFingerprint, $event->fresh()->getAttribute('write_fingerprint'));
        $this->assertSame($expectedIdempotency, $link->fresh()->getAttribute('idempotency_key'));
        $this->assertSame($expectedFingerprint, $link->fresh()->getAttribute('write_fingerprint'));
        $this->assertSame(2, $event->fresh()->getAttribute('ledger_identity_version'));
        $this->assertSame(2, $link->fresh()->getAttribute('ledger_identity_version'));
        $this->assertNotSame($rawIdempotency, $expectedIdempotency);
        $this->assertNotSame($rawFingerprint, $expectedFingerprint);
    }

    public function test_partial_legacy_link_identities_are_each_hardened_instead_of_being_mislabeled(): void
    {
        $this->disableSqliteVersionGuards();
        $user = $this->createUser();
        $rawIdempotency = hash('sha256', 'partial-link-write-id');
        $rawFingerprint = hash('sha256', 'partial-link-write-fingerprint');
        $idempotencyOnly = $this->createLink($user, 'partial-idempotency', [
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => null,
            'ledger_identity_version' => 1,
        ]);
        $fingerprintOnly = $this->createLink($user, 'partial-fingerprint', [
            'idempotency_key' => null,
            'write_fingerprint' => $rawFingerprint,
            'ledger_identity_version' => 1,
        ]);

        $this->migration()->up();

        $this->assertSame(
            MemoryLedgerIdentity::idempotency($rawIdempotency),
            $idempotencyOnly->fresh()->getAttribute('idempotency_key'),
        );
        $this->assertNull($idempotencyOnly->fresh()->getAttribute('write_fingerprint'));
        $this->assertSame(2, $idempotencyOnly->fresh()->getAttribute('ledger_identity_version'));
        $this->assertNull($fingerprintOnly->fresh()->getAttribute('idempotency_key'));
        $this->assertSame(
            MemoryLedgerIdentity::fingerprint($rawFingerprint),
            $fingerprintOnly->fresh()->getAttribute('write_fingerprint'),
        );
        $this->assertSame(2, $fingerprintOnly->fresh()->getAttribute('ledger_identity_version'));
    }

    public function test_forgotten_account_event_is_migrated_into_an_unlinkable_erasure_domain(): void
    {
        $this->disableSqliteVersionGuards();
        $rawIdempotency = hash('sha256', 'deleted-account-write-id');
        $rawFingerprint = hash('sha256', 'deleted-account-write-fingerprint');
        $event = MemoryWriteEvent::create([
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => $rawFingerprint,
            'memory_link_id' => null,
            'user_id' => null,
            'dataset' => 'erased:v1:'.str_repeat('a', 64),
            'state' => 'forgotten',
            'forgotten_at' => now(),
            'ledger_identity_version' => 1,
        ]);

        $migration = require database_path(
            'migrations/2026_08_23_000005_harden_memory_write_ledger_identities.php'
        );
        $migration->up();

        $liveIdempotency = MemoryLedgerIdentity::idempotency($rawIdempotency);
        $liveFingerprint = MemoryLedgerIdentity::fingerprint($rawFingerprint);
        $erased = $event->fresh();
        $this->assertSame(3, $erased->getAttribute('ledger_identity_version'));
        $this->assertSame(
            MemoryLedgerIdentity::erasedIdempotency($liveIdempotency),
            $erased->getAttribute('idempotency_key'),
        );
        $this->assertSame(
            MemoryLedgerIdentity::erasedFingerprint($liveFingerprint),
            $erased->getAttribute('write_fingerprint'),
        );
        $this->assertNotSame($liveIdempotency, $erased->getAttribute('idempotency_key'));
        $this->assertNotSame($liveFingerprint, $erased->getAttribute('write_fingerprint'));
    }

    public function test_forgotten_v1_v2_collision_erases_the_link_and_durably_queues_provider_compensation(): void
    {
        $this->disableSqliteVersionGuards();
        $user = $this->createUser();
        $rawIdempotency = hash('sha256', 'collision-write-id');
        $rawFingerprint = hash('sha256', 'collision-write-fingerprint');
        $hardenedIdempotency = MemoryLedgerIdentity::idempotency($rawIdempotency);
        $hardenedFingerprint = MemoryLedgerIdentity::fingerprint($rawFingerprint);
        $providerId = '11111111-1111-4111-8111-111111111111';
        $recoveredProviderId = '22222222-2222-4222-8222-222222222222';
        $link = $this->createLink($user, 'forgotten-collision', [
            'cognee_memory_id' => $providerId,
            'projection_status' => 'ready',
            'idempotency_key' => $hardenedIdempotency,
            'write_fingerprint' => $hardenedFingerprint,
            'ledger_identity_version' => 2,
        ]);
        $current = MemoryWriteEvent::create([
            'idempotency_key' => $hardenedIdempotency,
            'write_fingerprint' => $hardenedFingerprint,
            'ledger_identity_version' => 2,
            'memory_link_id' => $link->getKey(),
            'user_id' => $user->getKey(),
            'dataset' => $link->getAttribute('dataset'),
            'state' => 'committed',
        ]);
        MemoryWriteEvent::create([
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => $rawFingerprint,
            'ledger_identity_version' => 1,
            'memory_link_id' => null,
            'user_id' => $user->getKey(),
            'dataset' => $link->getAttribute('dataset'),
            'state' => 'forgotten',
            'forgotten_at' => now()->subMinute(),
        ]);
        $source = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->getKey(),
            'user_id' => $user->getKey(),
            'action' => 'upsert',
            'dataset' => $link->getAttribute('dataset'),
            'dedupe_key' => hash('sha256', 'collision-upsert'),
            'payload' => [
                'phase' => 'ingested',
                'cognee_memory_id' => $providerId,
                'recovered_data_ids' => [$recoveredProviderId],
                'content_ciphertext' => 'durable-recovery-snapshot',
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);
        $existingDoneDelete = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->getKey(),
            'action' => 'delete',
            'dataset' => $link->getAttribute('dataset'),
            'dedupe_key' => hash('sha256', implode('|', [
                'delete',
                $link->getAttribute('dataset'),
                $link->getKey(),
                $providerId,
            ])),
            'payload' => [
                'cognee_memory_id' => $providerId,
                'content_hash' => $link->getAttribute('content_hash'),
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);

        $migration = $this->migration();
        $migration->up();

        $this->assertDatabaseMissing('memory_links', ['id' => $link->getKey()]);
        $this->assertDatabaseCount('memory_write_events', 1);
        $tombstone = $current->fresh();
        $this->assertSame('forgotten', $tombstone->getAttribute('state'));
        $this->assertNull($tombstone->memory_link_id);
        $this->assertSame(2, $tombstone->getAttribute('ledger_identity_version'));
        $this->assertNull($source->fresh()->memory_link_id);
        $this->assertSame($link->getKey(), $source->fresh()->payload['provider_memory_link_id']);
        $this->assertSame(
            'forgotten_ledger_collision',
            $source->fresh()->payload['source_erasure_reason'],
        );
        $this->assertArrayHasKey('content_snapshot_erased_at', $source->fresh()->payload);
        $this->assertArrayNotHasKey('content', $source->fresh()->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $source->fresh()->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $source->fresh()->payload);

        $deletes = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $deletes);
        $this->assertSame(
            [$providerId, $recoveredProviderId],
            $deletes->pluck('payload')
                ->map(fn (array $payload): string => (string) $payload['cognee_memory_id'])
                ->sort()
                ->values()
                ->all(),
        );
        foreach ($deletes as $delete) {
            $this->assertNull($delete->memory_link_id);
            $this->assertSame('pending', $delete->status);
            $this->assertSame('forgotten_ledger_collision', $delete->payload['erasure_reason']);
            $this->assertSame($link->getKey(), $delete->payload['provider_memory_link_id']);
        }
        $existingDoneDelete->refresh();
        $this->assertSame('pending', $existingDoneDelete->status);
        $this->assertNull($existingDoneDelete->processed_at);

        // A restarted deployment can replay the migration without duplicating
        // provider Delete work or resurrecting the canonical link.
        $migration->up();
        $this->assertDatabaseCount('memory_links', 0);
        $this->assertSame(2, MemoryProjectionOutbox::where('action', 'delete')->count());
    }

    public function test_forgotten_collision_recovers_an_ambiguous_add_by_its_preserved_provider_filename(): void
    {
        Queue::fake();
        $this->disableSqliteVersionGuards();
        $user = $this->createUser();
        $rawIdempotency = hash('sha256', 'ambiguous-collision-write-id');
        $rawFingerprint = hash('sha256', 'ambiguous-collision-write-fingerprint');
        $hardenedIdempotency = MemoryLedgerIdentity::idempotency($rawIdempotency);
        $hardenedFingerprint = MemoryLedgerIdentity::fingerprint($rawFingerprint);
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $link = $this->createLink($user, 'ambiguous-forgotten-collision', [
            'projection_status' => 'failed',
            'idempotency_key' => $hardenedIdempotency,
            'write_fingerprint' => $hardenedFingerprint,
            'ledger_identity_version' => 2,
        ]);
        MemoryWriteEvent::create([
            'idempotency_key' => $hardenedIdempotency,
            'write_fingerprint' => $hardenedFingerprint,
            'ledger_identity_version' => 2,
            'memory_link_id' => $link->getKey(),
            'user_id' => $user->getKey(),
            'dataset' => $link->getAttribute('dataset'),
            'state' => 'committed',
        ]);
        MemoryWriteEvent::create([
            'idempotency_key' => $rawIdempotency,
            'write_fingerprint' => $rawFingerprint,
            'ledger_identity_version' => 1,
            'memory_link_id' => null,
            'user_id' => $user->getKey(),
            'dataset' => $link->getAttribute('dataset'),
            'state' => 'forgotten',
            'forgotten_at' => now(),
        ]);
        $source = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->getKey(),
            'user_id' => $user->getKey(),
            'action' => 'upsert',
            'dataset' => $link->getAttribute('dataset'),
            'dedupe_key' => hash('sha256', 'ambiguous-collision-upsert'),
            'payload' => [
                'phase' => 'adding',
                'content' => 'must be scrubbed',
                'content_ciphertext' => 'must also be scrubbed',
                'content_snapshot_expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'done',
            'attempts' => 1,
            'processed_at' => now(),
        ]);
        $providerMemoryLinkId = (int) $link->getKey();

        $this->migration()->up();

        $source->refresh();
        $this->assertDatabaseMissing('memory_links', ['id' => $providerMemoryLinkId]);
        $this->assertNull($source->memory_link_id);
        $this->assertSame('pending', $source->status);
        $this->assertSame($providerMemoryLinkId, $source->payload['provider_memory_link_id']);
        $this->assertSame($link->getAttribute('content_hash'), $source->payload['content_hash']);
        $this->assertSame('forgotten_ledger_collision', $source->payload['source_erasure_reason']);
        $this->assertArrayHasKey('content_snapshot_erased_at', $source->payload);
        $this->assertArrayNotHasKey('content', $source->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $source->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $source->payload);

        $cognee = new class($datasetId, $dataId) extends CogneeClient
        {
            /** @var list<int> */
            public array $lookupMemoryIds = [];

            public int $adds = 0;

            public function __construct(private string $datasetId, private string $dataId)
            {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function enabled(): bool
            {
                return true;
            }

            public function findData(
                string $dataset,
                int $memoryId,
                string $contentHash,
                bool $throw = false,
            ): array {
                $this->lookupMemoryIds[] = $memoryId;

                return [
                    'dataset_id' => $this->datasetId,
                    'data_ids' => [$this->dataId],
                ];
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->adds++;

                return [];
            }
        };

        (new MemoryProjectionService($cognee))->process($source->getKey());

        $source->refresh();
        $this->assertSame([$providerMemoryLinkId], $cognee->lookupMemoryIds);
        $this->assertSame(0, $cognee->adds);
        $this->assertSame('done', $source->status);
        $this->assertSame($providerMemoryLinkId, $source->payload['provider_memory_link_id']);
        $this->assertSame('forgotten_ledger_collision', $source->payload['source_erasure_reason']);
        $this->assertArrayNotHasKey('content', $source->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $source->payload);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->sole();
        $this->assertNull($delete->memory_link_id);
        $this->assertSame($user->getKey(), $delete->user_id);
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        $this->assertSame($providerMemoryLinkId, $delete->payload['provider_memory_link_id']);
        $this->assertSame('forgotten_ledger_collision', $delete->payload['erasure_reason']);
        $this->assertSame(
            hash('sha256', implode('|', [
                'delete',
                $source->dataset,
                $providerMemoryLinkId,
                $dataId,
            ])),
            $delete->dedupe_key,
        );
        Queue::assertPushed(ProcessMemoryProjection::class);
    }

    public function test_stale_chunk_snapshots_cannot_downgrade_runtime_hardened_rows(): void
    {
        $this->disableSqliteVersionGuards();
        $user = $this->createUser();
        $eventRawIdempotency = hash('sha256', 'stale-event-id');
        $eventRawFingerprint = hash('sha256', 'stale-event-fingerprint');
        $event = MemoryWriteEvent::create([
            'idempotency_key' => $eventRawIdempotency,
            'write_fingerprint' => $eventRawFingerprint,
            'ledger_identity_version' => 1,
            'memory_link_id' => null,
            'user_id' => $user->getKey(),
            'dataset' => 'user:'.$user->getKey().':project:p1',
            'state' => 'committed',
        ]);
        $staleEvent = DB::table('memory_write_events')->where('id', $event->getKey())->first();
        $runtimeEventIdempotency = MemoryLedgerIdentity::erasedIdempotency(
            MemoryLedgerIdentity::idempotency($eventRawIdempotency),
        );
        $runtimeEventFingerprint = MemoryLedgerIdentity::erasedFingerprint(
            MemoryLedgerIdentity::fingerprint($eventRawFingerprint),
        );
        DB::table('memory_write_events')->where('id', $event->getKey())->update([
            'idempotency_key' => $runtimeEventIdempotency,
            'write_fingerprint' => $runtimeEventFingerprint,
            'ledger_identity_version' => 3,
            'user_id' => null,
            'dataset' => 'erased:v1:'.str_repeat('b', 64),
            'state' => 'forgotten',
            'forgotten_at' => now(),
        ]);

        $linkRawIdempotency = hash('sha256', 'stale-link-id');
        $linkRawFingerprint = hash('sha256', 'stale-link-fingerprint');
        $link = $this->createLink($user, 'stale-link', [
            'idempotency_key' => $linkRawIdempotency,
            'write_fingerprint' => $linkRawFingerprint,
            'ledger_identity_version' => 1,
        ]);
        $staleLink = DB::table('memory_links')->where('id', $link->getKey())->first();
        $runtimeLinkIdempotency = MemoryLedgerIdentity::idempotency($linkRawIdempotency);
        $runtimeLinkFingerprint = MemoryLedgerIdentity::fingerprint($linkRawFingerprint);
        DB::table('memory_links')->where('id', $link->getKey())->update([
            'idempotency_key' => $runtimeLinkIdempotency,
            'write_fingerprint' => $runtimeLinkFingerprint,
            'ledger_identity_version' => 2,
        ]);

        $migration = $this->migration();
        $this->invokeMigrationMethod($migration, 'hardenWriteEvent', $staleEvent);
        $this->invokeMigrationMethod($migration, 'hardenMemoryLink', $staleLink);
        $migration->up();

        $event->refresh();
        $this->assertSame(3, $event->getAttribute('ledger_identity_version'));
        $this->assertSame($runtimeEventIdempotency, $event->getAttribute('idempotency_key'));
        $this->assertSame($runtimeEventFingerprint, $event->getAttribute('write_fingerprint'));
        $this->assertSame('forgotten', $event->getAttribute('state'));
        $link->refresh();
        $this->assertSame(2, $link->getAttribute('ledger_identity_version'));
        $this->assertSame($runtimeLinkIdempotency, $link->getAttribute('idempotency_key'));
        $this->assertSame($runtimeLinkFingerprint, $link->getAttribute('write_fingerprint'));
    }

    public function test_version_one_database_default_and_guards_reject_old_writers(): void
    {
        foreach (['memory_links', 'memory_write_events'] as $tableName) {
            $column = collect(Schema::getColumns($tableName))
                ->firstWhere('name', 'ledger_identity_version');

            $this->assertNotNull($column);
            $default = trim((string) $column['default'], "'\"() ");
            $this->assertSame(1, (int) $default);
        }

        $user = $this->createUser();
        $current = MemoryWriteEvent::create([
            'idempotency_key' => MemoryLedgerIdentity::idempotency(hash('sha256', 'current-default-id')),
            'write_fingerprint' => MemoryLedgerIdentity::fingerprint(hash('sha256', 'current-default-fingerprint')),
            'memory_link_id' => null,
            'user_id' => $user->getKey(),
            'dataset' => 'user:'.$user->getKey().':project:p1',
            'state' => 'committed',
        ]);
        $this->assertSame(2, $current->getAttribute('ledger_identity_version'));

        try {
            DB::table('memory_write_events')->where('id', $current->getKey())->update([
                'ledger_identity_version' => 1,
            ]);
            $this->fail('The update guard accepted a legacy ledger identity version.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('contract version is invalid', $error->getMessage());
        }

        try {
            DB::table('memory_write_events')->insert([
                'idempotency_key' => hash('sha256', 'old-writer-id'),
                'write_fingerprint' => hash('sha256', 'old-writer-fingerprint'),
                'memory_link_id' => null,
                'user_id' => $user->getKey(),
                'dataset' => 'user:'.$user->getKey().':project:p1',
                'state' => 'committed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The insert guard accepted a writer which omitted the contract version.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('contract version is invalid', $error->getMessage());
        }
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_23_000005_harden_memory_write_ledger_identities.php'
        );
    }

    private function createUser(): User
    {
        $created = User::factory()->create();

        return User::query()->findOrFail($created->getKey());
    }

    /** @param array<string,mixed> $overrides */
    private function createLink(User $user, string $externalId, array $overrides = []): MemoryLink
    {
        return MemoryLink::create(array_merge([
            'user_id' => $user->getKey(),
            'external_id' => $externalId,
            'scope' => 'project',
            'dataset' => 'user:'.$user->getKey().':project:p1',
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.9,
            'summary' => 'Ledger migration fixture.',
            'content_hash' => hash('sha256', $externalId),
            'valid_from' => now(),
            'projection_status' => 'not_required',
        ], $overrides));
    }

    private function invokeMigrationMethod(object $migration, string $method, object $row): void
    {
        $reflection = new ReflectionMethod($migration, $method);
        $reflection->setAccessible(true);
        $reflection->invoke($migration, $row);
    }

    private function disableSqliteVersionGuards(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['memory_links', 'memory_write_events'] as $tableName) {
            foreach (['insert', 'update'] as $suffix) {
                $trigger = $tableName.'_ledger_identity_v2_'.$suffix;
                DB::unprepared("DROP TRIGGER IF EXISTS \"{$trigger}\"");
            }
        }
    }
}
