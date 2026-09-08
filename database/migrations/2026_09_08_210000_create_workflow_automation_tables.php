<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_triggers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('kind', 60)->index();
            $table->boolean('enabled')->default(false);
            $table->json('config');
            $table->json('input')->nullable();
            $table->string('secret_hash', 64)->nullable();
            $table->timestamp('next_due_at')->nullable()->index();
            $table->string('last_wall_key', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 60);
            $table->string('source', 120);
            $table->string('event_key', 190);
            $table->json('payload');
            $table->json('causation')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'source', 'event_key'], 'workflow_event_identity');
        });
        Schema::create('workflow_trigger_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_trigger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('event_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['workflow_trigger_id', 'workflow_event_id'], 'workflow_trigger_event_unique');
        });
        Schema::create('workflow_automation_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 120);
            $table->unsignedInteger('approved_revision');
            $table->string('status', 20);
            $table->json('config');
            $table->string('scope_hash', 64);
            $table->uuid('operation_id');
            $table->timestamps();
            $table->unique(['user_id', 'operation_id'], 'workflow_grant_operation_unique');
            $table->index(['workflow_definition_id', 'id']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('workflow_causation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('workflow_causation'));
        Schema::dropIfExists('workflow_automation_grants');
        Schema::dropIfExists('workflow_trigger_deliveries');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_triggers');
    }
};
