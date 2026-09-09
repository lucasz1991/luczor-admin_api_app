<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('root_workflow_run_id')->nullable()->index();
            $table->json('budgets')->nullable();
            $table->json('budget_state')->nullable();
            $table->string('test_mode', 20)->nullable();
        });
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->unsignedInteger('type_version')->default(1);
            $table->json('result_envelope')->nullable();
            $table->json('control_state')->nullable();
        });
        Schema::table('workflow_definitions', fn (Blueprint $table) => $table->json('repair_policy')->nullable());
        Schema::create('workflow_execution_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('root_workflow_run_id')->index();
            $table->unsignedBigInteger('workflow_step_id')->index();
            $table->uuid('execution_id');
            $table->unsignedInteger('attempt');
            $table->string('status', 24);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->unique(['execution_id', 'attempt']);
        });
        Schema::create('workflow_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('specification');
            $table->char('assertions_hash', 64);
            $table->char('fixture_hash', 64);
            $table->timestamps();
        });
        Schema::create('workflow_repair_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_run_id')->index();
            $table->unsignedInteger('base_version');
            $table->json('definition');
            $table->json('snapshot');
            $table->char('definition_hash', 64);
            $table->char('code_hash', 64);
            $table->json('scope');
            $table->string('status', 24)->default('proposed');
            $table->unsignedInteger('activated_version')->nullable();
            $table->timestamps();
        });
        Schema::create('workflow_test_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_test_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('repair_revision_id')->nullable()->index();
            $table->unsignedBigInteger('workflow_run_id')->nullable()->index();
            $table->string('mode', 20);
            $table->string('status', 24);
            $table->char('definition_hash', 64);
            $table->char('code_hash', 64);
            $table->char('assertions_hash', 64);
            $table->char('fixture_hash', 64);
            $table->char('environment_hash', 64);
            $table->json('snapshot');
            $table->json('result')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::table('workflow_automation_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('predecessor_grant_id')->nullable()->index();
            $table->unsignedBigInteger('repair_revision_id')->nullable();
            $table->unsignedBigInteger('test_evidence_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_automation_grants', fn (Blueprint $table) => $table->dropColumn(['predecessor_grant_id', 'repair_revision_id', 'test_evidence_id']));
        Schema::dropIfExists('workflow_test_evidence');
        Schema::dropIfExists('workflow_repair_revisions');
        Schema::dropIfExists('workflow_test_cases');
        Schema::dropIfExists('workflow_execution_records');
        Schema::table('workflow_definitions', fn (Blueprint $table) => $table->dropColumn('repair_policy'));
        Schema::table('workflow_steps', fn (Blueprint $table) => $table->dropColumn(['type_version', 'result_envelope', 'control_state']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropColumn(['root_workflow_run_id', 'budgets', 'budget_state', 'test_mode']));
    }
};
