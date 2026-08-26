<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('memory_links', 'write_fingerprint')) {
            return;
        }

        Schema::table('memory_links', function (Blueprint $table): void {
            $table->char('write_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        // This repair deliberately adopts a column that may already have been
        // created by the accidentally edited historical migration. Migration
        // state cannot prove column ownership, so rollback must never delete
        // potentially pre-existing identity data.
    }
};
