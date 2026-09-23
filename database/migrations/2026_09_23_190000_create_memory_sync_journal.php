<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_sync_scopes', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence')->default(0);
        });
        Schema::create('memory_sync_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64);
            $table->foreign('scope_key')->references('id')->on('memory_sync_scopes')->cascadeOnDelete();
            $table->string('record_key', 64);
            $table->string('record_id', 190);
            $table->unsignedBigInteger('memory_link_id');
            $table->string('fingerprint', 64);
            $table->unique(['scope_key', 'record_key']);
        });
        Schema::create('memory_sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64);
            $table->foreign('scope_key')->references('id')->on('memory_sync_scopes')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('record_id', 190);
            $table->unsignedBigInteger('memory_link_id');
            $table->string('operation', 10);
            $table->unique(['scope_key', 'sequence']);
        });
        Schema::create('memory_deletion_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('identity_key', 64)->index();
            $table->unsignedBigInteger('memory_version')->default(0);
            $table->json('memory_ids');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_deletion_receipts');
        Schema::dropIfExists('memory_sync_changes');
        Schema::dropIfExists('memory_sync_entries');
        Schema::dropIfExists('memory_sync_scopes');
    }
};
