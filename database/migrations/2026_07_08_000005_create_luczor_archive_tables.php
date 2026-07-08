<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['luczor_project_archives', 'luczor_message_archives', 'luczor_memory_archives', 'luczor_summary_archives'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('client_id');
                $table->string('entity_type');
                $table->string('external_id');
                $table->json('payload');
                $table->timestamp('created_at_client')->nullable();
                $table->timestamp('updated_at_client')->nullable();
                $table->timestamps();
                $table->unique(['client_id', 'entity_type', 'external_id']);
                $table->index('updated_at');
            });
        }

        Schema::create('luczor_agent_event_archives', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->string('external_id')->nullable();
            $table->string('event_type')->default('event');
            $table->json('payload');
            $table->timestamp('occurred_at_client')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'event_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luczor_agent_event_archives');
        Schema::dropIfExists('luczor_summary_archives');
        Schema::dropIfExists('luczor_memory_archives');
        Schema::dropIfExists('luczor_message_archives');
        Schema::dropIfExists('luczor_project_archives');
    }
};
