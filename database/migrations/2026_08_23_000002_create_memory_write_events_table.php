<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the event ledger compatible with Luczor's published MySQL schema.
     * AppServiceProvider sets Laravel's global string length to 191.
     */
    private const DATASET_LENGTH = 191;

    /** @var array<string, array{types: list<string>, nullable: bool, length?: int, auto_increment?: bool}> */
    private const COLUMN_CONTRACT = [
        'id' => ['types' => ['bigint', 'int8', 'integer'], 'nullable' => false, 'auto_increment' => true],
        'idempotency_key' => ['types' => ['char', 'bpchar', 'varchar'], 'nullable' => false, 'length' => 64],
        'write_fingerprint' => ['types' => ['char', 'bpchar', 'varchar'], 'nullable' => false, 'length' => 64],
        'memory_link_id' => ['types' => ['bigint', 'int8', 'integer'], 'nullable' => true],
        'user_id' => ['types' => ['bigint', 'int8', 'integer'], 'nullable' => true],
        'dataset' => ['types' => ['varchar'], 'nullable' => false, 'length' => self::DATASET_LENGTH],
        'state' => ['types' => ['varchar'], 'nullable' => false, 'length' => 24],
        'forgotten_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => true],
        'created_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => true],
        'updated_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('memory_write_events')) {
            Schema::create('memory_write_events', function (Blueprint $table) {
                $table->id();
                $table->char('idempotency_key', 64)->unique();
                $table->char('write_fingerprint', 64);
                $table->unsignedBigInteger('memory_link_id')->nullable()->index();
                $table->foreign('memory_link_id')->references('id')->on('memory_links')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('dataset', self::DATASET_LENGTH)->index();
                $table->string('state', 24)->default('committed')->index();
                $table->timestamp('forgotten_at')->nullable();
                $table->timestamps();
            });
        }

        $this->assertTableContract();

        // Deployments which already accepted writes with the previous schema
        // gain durable event records before the new code can delete a link.
        DB::table('memory_links')
            ->whereNotNull('idempotency_key')
            ->whereNotNull('write_fingerprint')
            ->select(['id', 'user_id', 'dataset', 'idempotency_key', 'write_fingerprint', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('memory_write_events')->insertOrIgnore([
                        'idempotency_key' => $row->idempotency_key,
                        'write_fingerprint' => $row->write_fingerprint,
                        'memory_link_id' => $row->id,
                        'user_id' => $row->user_id,
                        'dataset' => $row->dataset,
                        'state' => 'committed',
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);
                }
            });
    }

    private function assertTableContract(): void
    {
        $columns = [];
        foreach (Schema::getColumns('memory_write_events') as $column) {
            $columns[strtolower($column['name'])] = $column;
        }

        $violations = [];
        foreach (self::COLUMN_CONTRACT as $name => $contract) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                $violations[] = "missing column {$name}";

                continue;
            }

            $typeName = strtolower($column['type_name']);
            if (! in_array($typeName, $contract['types'], true)) {
                $violations[] = "column {$name} has type {$column['type']}";
            }
            if ($name !== 'id' && $column['nullable'] !== $contract['nullable']) {
                $expected = $contract['nullable'] ? 'nullable' : 'not nullable';
                $violations[] = "column {$name} must be {$expected}";
            }
            if (($contract['auto_increment'] ?? false) && ! $column['auto_increment']) {
                $violations[] = "column {$name} must auto increment";
            }
            if (isset($contract['length'])) {
                $declaredLength = $this->declaredLength($column['type']);
                if ($declaredLength !== null && $declaredLength !== $contract['length']) {
                    $violations[] = "column {$name} must have length {$contract['length']}";
                }
                if ($declaredLength === null && DB::connection()->getDriverName() !== 'sqlite') {
                    $violations[] = "column {$name} must declare length {$contract['length']}";
                }
            }
        }

        $stateDefault = $columns['state']['default'] ?? null;
        if ($this->normalizeDefault($stateDefault) !== 'committed') {
            $violations[] = 'column state must default to committed';
        }

        $indexes = Schema::getIndexes('memory_write_events');
        if (! $this->hasIndex($indexes, ['id'], primary: true)) {
            $violations[] = 'missing primary index on [id]';
        }
        if (! $this->hasIndex($indexes, ['idempotency_key'], unique: true)) {
            $violations[] = 'missing unique index on [idempotency_key]';
        }
        foreach (['memory_link_id', 'dataset', 'state'] as $indexedColumn) {
            if (! $this->hasIndex($indexes, [$indexedColumn])) {
                $violations[] = "missing index on [{$indexedColumn}]";
            }
        }

        $foreignKeys = Schema::getForeignKeys('memory_write_events');
        if (! $this->hasForeignKey($foreignKeys, 'memory_link_id', 'memory_links', 'id', 'set null')) {
            $violations[] = 'missing memory_link_id -> memory_links(id) ON DELETE SET NULL foreign key';
        }
        if (! $this->hasForeignKey($foreignKeys, 'user_id', 'users', 'id', 'cascade')) {
            $violations[] = 'missing user_id -> users(id) ON DELETE CASCADE foreign key';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Existing memory_write_events table does not match the required contract: '
                .implode('; ', $violations).'.'
            );
        }
    }

    private function declaredLength(string $type): ?int
    {
        return preg_match('/\((\d+)\)/', strtolower($type), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $normalized = preg_replace('/::(?:character varying|text|bpchar)$/i', '', trim((string) $default));

        return trim($normalized ?? (string) $default, "'\"");
    }

    /**
     * @param  list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}>  $indexes
     * @param  list<string>  $columns
     */
    private function hasIndex(array $indexes, array $columns, ?bool $unique = null, ?bool $primary = null): bool
    {
        foreach ($indexes as $index) {
            $indexedColumns = array_map('strtolower', $index['columns']);
            if ($indexedColumns !== $columns) {
                continue;
            }
            if ($unique !== null && $index['unique'] !== $unique) {
                continue;
            }
            if ($primary !== null && $index['primary'] !== $primary) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>  $foreignKeys
     */
    private function hasForeignKey(
        array $foreignKeys,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        string $onDelete,
    ): bool {
        foreach ($foreignKeys as $foreignKey) {
            if (array_map('strtolower', $foreignKey['columns']) === [$column]
                && strtolower($foreignKey['foreign_table']) === $foreignTable
                && array_map('strtolower', $foreignKey['foreign_columns']) === [$foreignColumn]
                && strtolower((string) $foreignKey['on_delete']) === $onDelete) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_write_events');
    }
};
