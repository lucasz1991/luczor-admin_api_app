<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PostgresMigrationAssistant extends Command
{
    protected $signature = 'luczor:postgres-migrate
        {--export= : Export legacy MySQL to this directory and create a manifest}
        {--import= : Import an existing manifest directory into pgsql}
        {--dry-run : Validate source/target table and column compatibility only}
        {--rollback= : Roll back a completed run id after typing --confirm=ROLLBACK}
        {--confirm= : Required confirmation literal for rollback}';

    protected $description = 'Lossless, manifest-validated MySQL to PostgreSQL migration assistant for Luczor.';

    public function handle(): int
    {
        $export = $this->option('export');
        $import = $this->option('import');
        $rollback = $this->option('rollback');
        if ((int) (bool) $export + (int) (bool) $import + (int) (bool) $rollback !== 1) {
            $this->error('Choose exactly one of --export, --import or --rollback.');

            return self::INVALID;
        }

        if ($rollback) {
            return $this->rollback((string) $rollback);
        }
        if ($export) {
            return $this->export((string) $export);
        }

        return $this->import((string) $import);
    }

    private function export(string $directory): int
    {
        $source = DB::connection('mysql_legacy');
        $target = DB::connection('pgsql');
        $directory = base_path($directory);
        File::ensureDirectoryExists($directory);
        $tables = $this->sourceTables($source);
        $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'source' => 'mysql_legacy', 'tables' => []];

        foreach ($tables as $table) {
            $sourceColumns = $source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->hasTable($table)
                ? $target->getSchemaBuilder()->getColumnListing($table) : [];
            $unmapped = array_values(array_diff($sourceColumns, $targetColumns));
            if ($unmapped !== []) {
                $this->error("{$table}: target is missing source columns: ".implode(', ', $unmapped));

                return self::FAILURE;
            }
            $this->line("Exporting {$table}");
            $file = $directory.DIRECTORY_SEPARATOR.$table.'.jsonl';
            $handle = fopen($file, 'wb');
            $hash = hash_init('sha256');
            $count = 0;
            $query = $source->table($table);
            if (in_array('id', $sourceColumns, true)) {
                $query->orderBy('id');
            }
            foreach ($query->cursor() as $row) {
                $record = $this->normalize((array) $row, $sourceColumns);
                fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                hash_update($hash, $this->canonical($record));
                $count++;
            }
            fclose($handle);
            $manifest['tables'][$table] = [
                'file' => basename($file), 'columns' => $sourceColumns,
                'count' => $count, 'hash' => hash_final($hash),
            ];
        }
        File::put($directory.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('Export complete: '.$directory);

        return self::SUCCESS;
    }

    private function import(string $directory): int
    {
        $directory = base_path($directory);
        $manifest = $this->manifest($directory);
        if (! $manifest) {
            return self::FAILURE;
        }
        $target = DB::connection('pgsql');
        $source = DB::connection('mysql_legacy');
        $compatibility = $this->checkCompatibility($manifest, $source, $target);
        if ($compatibility !== []) {
            foreach ($compatibility as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run passed. No PostgreSQL data was changed.');

            return self::SUCCESS;
        }

        $run = $target->table('data_migration_runs')->insertGetId([
            'run_id' => (string) Str::uuid(), 'source_connection' => 'mysql_legacy', 'target_connection' => 'pgsql',
            'status' => 'importing', 'manifest_path' => $directory, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $runId = $target->table('data_migration_runs')->where('id', $run)->value('run_id');
        try {
            foreach ($manifest['tables'] as $table => $meta) {
                $this->line("Importing {$table}");
                $this->importTable($target, $directory.DIRECTORY_SEPARATOR.$meta['file'], $table, $meta['columns']);
                $check = $this->targetCheck($target, $table, $meta['columns']);
                $ok = (int) $meta['count'] === $check['count'] && hash_equals($meta['hash'], $check['hash']);
                $target->table('data_migration_table_checks')->insert([
                    'data_migration_run_id' => $run, 'table_name' => $table,
                    'source_count' => $meta['count'], 'target_count' => $check['count'],
                    'source_hash' => $meta['hash'], 'target_hash' => $check['hash'],
                    'status' => $ok ? 'verified' : 'mismatch', 'details' => json_encode(['columns' => $meta['columns']]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $ok) {
                    throw new \RuntimeException("Validation mismatch for {$table}.");
                }
                $this->resetSequence($target, $table, $meta['columns']);
            }
            $target->table('data_migration_runs')->where('id', $run)->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            $this->info("Import {$runId} completed and verified.");

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $target->table('data_migration_runs')->where('id', $run)->update(['status' => 'failed', 'summary' => json_encode(['error' => $error->getMessage()]), 'finished_at' => now(), 'updated_at' => now()]);
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function rollback(string $runId): int
    {
        if ($this->option('confirm') !== 'ROLLBACK') {
            $this->error('Rollback requires --confirm=ROLLBACK.');

            return self::INVALID;
        }
        $target = DB::connection('pgsql');
        $run = $target->table('data_migration_runs')->where('run_id', $runId)->first();
        if (! $run || $run->status !== 'completed') {
            $this->error('Only a completed import run can be rolled back.');

            return self::FAILURE;
        }
        $tables = $target->table('data_migration_table_checks')->where('data_migration_run_id', $run->id)->pluck('table_name')->all();
        $target->transaction(function () use ($target, $tables) {
            $quoted = implode(', ', array_map(fn ($table) => '"'.str_replace('"', '""', $table).'"', $tables));
            if ($quoted !== '') {
                $target->statement('TRUNCATE TABLE '.$quoted.' RESTART IDENTITY CASCADE');
            }
        });
        $target->table('data_migration_runs')->where('id', $run->id)->update(['status' => 'rolled_back', 'finished_at' => now(), 'updated_at' => now()]);
        $this->warn("PostgreSQL rows imported by {$runId} were truncated. MySQL was untouched.");

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function sourceTables(ConnectionInterface $source): array
    {
        return collect($source->select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0])
            ->reject(fn ($table) => in_array($table, ['migrations', 'data_migration_runs', 'data_migration_table_checks']))->sort()->values()->all();
    }

    /** @return array<int,string> */
    private function checkCompatibility(array $manifest, ConnectionInterface $source, ConnectionInterface $target): array
    {
        $errors = [];
        foreach ($manifest['tables'] as $table => $meta) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                $errors[] = "Source table {$table} no longer exists.";
            }
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                $errors[] = "Target table {$table} does not exist. Run PostgreSQL migrations first.";

                continue;
            }
            $missing = array_diff($meta['columns'], $target->getSchemaBuilder()->getColumnListing($table));
            if ($missing !== []) {
                $errors[] = "Target {$table} is missing columns: ".implode(', ', $missing);
            }
            if ($target->table($table)->count() > 0) {
                $errors[] = "Target table {$table} is not empty; importing into an existing PostgreSQL dataset is refused.";
            }
        }

        return $errors;
    }

    private function importTable(ConnectionInterface $target, string $file, string $table, array $columns): void
    {
        $handle = fopen($file, 'rb');
        if (! $handle) {
            throw new \RuntimeException("Cannot open {$file}");
        }
        $chunk = [];
        while (($line = fgets($handle)) !== false) {
            $chunk[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (count($chunk) === 250) {
                $target->table($table)->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $target->table($table)->insert($chunk);
        }
        fclose($handle);
    }

    /** @return array{count:int,hash:string} */
    private function targetCheck(ConnectionInterface $target, string $table, array $columns): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $query = $target->table($table)->select($columns);
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }
        foreach ($query->cursor() as $row) {
            hash_update($hash, $this->canonical($this->normalize((array) $row, $columns)));
            $count++;
        }

        return ['count' => $count, 'hash' => hash_final($hash)];
    }

    private function resetSequence(ConnectionInterface $target, string $table, array $columns): void
    {
        if (! in_array('id', $columns, true)) {
            return;
        }
        $target->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), true)");
    }

    private function manifest(string $directory): ?array
    {
        $path = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($path)) {
            $this->error('manifest.json not found.');

            return null;
        }
        try {
            return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            $this->error('Invalid manifest: '.$error->getMessage());

            return null;
        }
    }

    private function normalize(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $out[$column] = is_object($value) && method_exists($value, '__toString') ? (string) $value : $value;
        }
        ksort($out);

        return $out;
    }

    private function canonical(array $row): string
    {
        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
