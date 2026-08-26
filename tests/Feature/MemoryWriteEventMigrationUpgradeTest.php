<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class MemoryWriteEventMigrationUpgradeTest extends TestCase
{
    public function test_published_orchestration_schema_is_repaired_before_write_events_are_backfilled(): void
    {
        $this->createPublishedOrchestrationSchema();

        $this->assertFalse(Schema::hasColumn('memory_links', 'write_fingerprint'));

        $repair = require database_path(
            'migrations/2026_08_23_000001_ensure_memory_write_fingerprint.php'
        );
        $repair->up();
        $repair->up();

        $this->assertTrue(Schema::hasColumn('memory_links', 'write_fingerprint'));
        $this->assertNull(DB::table('memory_links')->value('write_fingerprint'));

        $repair->down();
        $this->assertTrue(Schema::hasColumn('memory_links', 'write_fingerprint'));
    }

    public function test_repair_down_never_deletes_a_preexisting_fingerprint_column_or_its_data(): void
    {
        $this->createPublishedOrchestrationSchema();
        Schema::table('memory_links', function (Blueprint $table): void {
            $table->char('write_fingerprint', 64)->nullable()->after('idempotency_key');
        });
        $fingerprint = hash('sha256', 'preexisting-fingerprint');
        DB::table('memory_links')->update(['write_fingerprint' => $fingerprint]);

        $repair = require database_path(
            'migrations/2026_08_23_000001_ensure_memory_write_fingerprint.php'
        );
        $repair->up();
        $repair->down();

        $this->assertTrue(Schema::hasColumn('memory_links', 'write_fingerprint'));
        $this->assertSame($fingerprint, DB::table('memory_links')->value('write_fingerprint'));
    }

    public function test_laravel_discovers_repair_between_published_migration_and_write_events(): void
    {
        $migrator = $this->app->make(Migrator::class);
        $migrationNames = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $expectedOrder = [
            '2026_08_23_000001_add_memory_orchestration_fields',
            '2026_08_23_000001_ensure_memory_write_fingerprint',
            '2026_08_23_000002_create_memory_write_events_table',
        ];

        $this->assertSame(
            $expectedOrder,
            array_values(array_intersect($migrationNames, $expectedOrder)),
        );
    }

    public function test_write_event_dataset_contract_matches_the_published_mysql_string_length(): void
    {
        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );
        $reflection = new ReflectionClass($migration);
        $datasetLength = $reflection->getReflectionConstant('DATASET_LENGTH')?->getValue();
        $columnContract = $reflection->getReflectionConstant('COLUMN_CONTRACT')?->getValue();

        $this->assertSame(191, Builder::$defaultStringLength);
        $this->assertSame(Builder::$defaultStringLength, $datasetLength);
        $this->assertIsArray($columnContract);
        $this->assertSame($datasetLength, $columnContract['dataset']['length']);
    }

    public function test_write_event_migration_resumes_after_mysql_style_partial_ddl_and_is_repeatable(): void
    {
        $linkId = $this->createRepairableMemoryLink();
        $this->createWriteEventsTable();

        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('memory_write_events', 1);
        $this->assertDatabaseHas('memory_write_events', [
            'memory_link_id' => $linkId,
            'idempotency_key' => hash('sha256', 'published-write-id'),
            'write_fingerprint' => hash('sha256', 'published-write-fingerprint'),
            'dataset' => 'user:legacy:project:p1',
            'state' => 'committed',
        ]);
    }

    public function test_write_event_migration_rejects_an_incomplete_existing_table(): void
    {
        $this->createRepairableMemoryLink();
        Schema::create('memory_write_events', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64)->unique();
        });

        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Existing memory_write_events table does not match the required contract: missing column write_fingerprint'
        );

        $migration->up();
    }

    public function test_write_event_migration_rejects_a_missing_unique_idempotency_index(): void
    {
        $this->createRepairableMemoryLink();
        $this->createWriteEventsTable(uniqueIdempotency: false);

        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing unique index on [idempotency_key]');

        $migration->up();
    }

    public function test_write_event_migration_rejects_missing_foreign_keys(): void
    {
        $this->createRepairableMemoryLink();
        $this->createWriteEventsTable(includeForeignKeys: false);

        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'missing memory_link_id -> memory_links(id) ON DELETE SET NULL foreign key'
        );

        $migration->up();
    }

    public function test_write_event_migration_rejects_wrong_memory_link_nullability(): void
    {
        $this->createRepairableMemoryLink();
        $this->createWriteEventsTable(memoryLinkNullable: false);

        $migration = require database_path(
            'migrations/2026_08_23_000002_create_memory_write_events_table.php'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('column memory_link_id must be nullable');

        $migration->up();
    }

    private function createRepairableMemoryLink(): int
    {
        $this->createPublishedOrchestrationSchema();
        $repair = require database_path(
            'migrations/2026_08_23_000001_ensure_memory_write_fingerprint.php'
        );
        $repair->up();

        $idempotencyKey = hash('sha256', 'published-write-id');
        $writeFingerprint = hash('sha256', 'published-write-fingerprint');
        $linkId = (int) DB::table('memory_links')->value('id');
        DB::table('memory_links')->where('id', $linkId)->update([
            'idempotency_key' => $idempotencyKey,
            'write_fingerprint' => $writeFingerprint,
        ]);

        return $linkId;
    }

    private function createPublishedOrchestrationSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('memory_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('scope')->default('project');
            $table->string('dataset')->index();
            $table->string('project_id')->nullable()->index();
            $table->string('feature_key')->nullable()->index();
            $table->string('session_id')->nullable();
            $table->string('cognee_memory_id')->nullable();
            $table->string('type')->default('note');
            $table->string('visibility')->default('syncable');
            $table->string('staleness')->default('fresh');
            $table->decimal('importance', 5, 4)->default(0.5);
            $table->text('summary');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'external_id']);
            $table->index(['dataset', 'importance']);
        });
        DB::table('memory_links')->insert([
            'client_id' => 'legacy-device',
            'external_id' => 'legacy-memory',
            'scope' => 'project',
            'dataset' => 'user:legacy:project:p1',
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'summary' => 'Published memory row.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publishedMigration = require database_path(
            'migrations/2026_08_23_000001_add_memory_orchestration_fields.php'
        );
        $publishedMigration->up();
    }

    private function createWriteEventsTable(
        bool $uniqueIdempotency = true,
        bool $includeForeignKeys = true,
        bool $memoryLinkNullable = true,
    ): void {
        Schema::create('memory_write_events', function (Blueprint $table) use (
            $uniqueIdempotency,
            $includeForeignKeys,
            $memoryLinkNullable,
        ): void {
            $table->id();
            $idempotency = $table->char('idempotency_key', 64);
            if ($uniqueIdempotency) {
                $idempotency->unique();
            } else {
                $idempotency->index();
            }
            $table->char('write_fingerprint', 64);
            if ($memoryLinkNullable) {
                $table->unsignedBigInteger('memory_link_id')->nullable()->index();
            } else {
                $table->unsignedBigInteger('memory_link_id')->index();
            }
            $table->unsignedBigInteger('user_id')->nullable();
            if ($includeForeignKeys) {
                $table->foreign('memory_link_id')->references('id')->on('memory_links')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            }
            $table->string('dataset')->index();
            $table->string('state', 24)->default('committed')->index();
            $table->timestamp('forgotten_at')->nullable();
            $table->timestamps();
        });
    }
}
