<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('data_migration_runs')) Schema::create('data_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->string('source_connection');
            $table->string('target_connection');
            $table->string('status')->index();
            $table->string('manifest_path')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('data_migration_table_checks')) Schema::create('data_migration_table_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_run_id')->constrained()->cascadeOnDelete();
            $table->string('table_name');
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('target_count')->default(0);
            $table->char('source_hash', 64)->nullable();
            $table->char('target_hash', 64)->nullable();
            $table->string('status')->default('pending');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['data_migration_run_id', 'table_name'], 'dm_checks_run_table_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_table_checks');
        Schema::dropIfExists('data_migration_runs');
    }
};
