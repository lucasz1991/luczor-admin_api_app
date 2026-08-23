<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'luczor_project_archives' => 'project_archives_sync_keyset_idx',
        'luczor_message_archives' => 'message_archives_sync_keyset_idx',
        'luczor_memory_archives' => 'memory_archives_sync_keyset_idx',
        'luczor_summary_archives' => 'summary_archives_sync_keyset_idx',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->index(['user_id', 'updated_at', 'id'], $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }
};
