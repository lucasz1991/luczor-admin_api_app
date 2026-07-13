<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §14 P15 — async workflow foundation: duration, logs, run context/cursor + step artifacts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
            $table->json('logs')->nullable()->after('output');
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
            $table->json('context')->nullable()->after('output');
            // Plain pointer (no FK) to the currently executing step — a UI cursor.
            $table->unsignedBigInteger('current_workflow_step_id')->nullable()->after('agent_run_id');
        });

        Schema::create('workflow_run_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->string('step_key', 120)->nullable()->index();
            $table->string('phase', 20)->default('after')->index();   // before|after|error
            $table->string('artifact_type', 40)->index();             // screenshot|dom|log|json|file
            $table->string('label')->nullable();
            $table->text('current_url')->nullable();
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path')->nullable();
            $table->string('status', 40)->default('success')->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['workflow_run_id', 'workflow_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_artifacts');
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'context', 'current_workflow_step_id']);
        });
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'logs']);
        });
    }
};
