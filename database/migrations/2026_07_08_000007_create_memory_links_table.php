<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * memory_links = operational System-of-Record for memories (per Masterplan v3).
 * The actual memory content/graph lives in Cognee; this table keeps stable
 * pointers (project_id, feature_key, cognee_memory_id), a short summary and a
 * staleness flag — and enables degraded recall when Cognee is not available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_links', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();       // originating device
            $table->string('external_id')->nullable();      // client-side memory id
            $table->string('scope')->default('project');    // private|project|skill|agent|global
            $table->string('dataset')->index();             // cognee dataset namespace
            $table->string('project_id')->nullable()->index();
            $table->string('feature_key')->nullable()->index();
            $table->string('session_id')->nullable();
            $table->string('cognee_memory_id')->nullable();
            $table->string('type')->default('note');
            $table->string('visibility')->default('syncable'); // private|syncable|public
            $table->string('staleness')->default('fresh');     // fresh|stale
            $table->decimal('importance', 5, 4)->default(0.5);
            $table->text('summary');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'external_id']);
            $table->index(['dataset', 'importance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_links');
    }
};
