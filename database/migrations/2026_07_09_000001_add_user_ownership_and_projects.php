<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'external_id']);
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('github');
            $table->string('external_id', 190)->nullable();
            $table->string('full_name', 255);
            $table->string('default_branch', 160)->default('main');
            $table->string('last_commit_sha', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'full_name']);
        });

        foreach (['luczor_project_archives', 'luczor_message_archives', 'luczor_memory_archives', 'luczor_summary_archives'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->foreignId('project_ref_id')->nullable()->after('client_id')->constrained('projects')->nullOnDelete();
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique($tableName.'_client_id_entity_type_external_id_unique');
                $table->unique(['user_id', 'client_id', 'entity_type', 'external_id'], $tableName.'_user_client_entity_external_unique');
                $table->index(['user_id', 'updated_at']);
            });
        }

        Schema::table('luczor_agent_event_archives', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('project_ref_id')->nullable()->after('client_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('memory_links', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('project_ref_id')->nullable()->after('project_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'dataset']);
        });

        Schema::table('context_artifacts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('project_ref_id')->nullable()->after('project_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('project_ref_id')->nullable()->after('project_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('project_ref_id')->nullable()->after('project_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('llm_runs', function (Blueprint $table) {
            $table->foreignId('project_ref_id')->nullable()->after('project_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'task_type', 'model_id']);
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('project_ref_id')->nullable()->after('agent_run_id')->constrained('projects')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('model_rankings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('model_rankings', function (Blueprint $table) {
            $table->dropUnique('model_rankings_task_type_model_id_unique');
            $table->unique(['user_id', 'task_type', 'model_id'], 'model_rankings_user_task_model_unique');
            $table->index(['user_id', 'task_type', 'score']);
        });
    }

    public function down(): void
    {
        Schema::table('model_rankings', function (Blueprint $table) {
            $table->dropUnique('model_rankings_user_task_model_unique');
            $table->dropIndex('model_rankings_user_id_task_type_score_index');
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['task_type', 'model_id']);
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->dropIndex('evaluation_results_user_id_created_at_index');
            $table->dropConstrainedForeignId('project_ref_id');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('llm_runs', function (Blueprint $table) {
            $table->dropIndex('llm_runs_user_id_task_type_model_id_index');
            $table->dropConstrainedForeignId('project_ref_id');
        });
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropIndex('agent_tasks_user_id_created_at_index');
            $table->dropConstrainedForeignId('project_ref_id');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex('agent_runs_user_id_created_at_index');
            $table->dropConstrainedForeignId('project_ref_id');
        });
        Schema::table('context_artifacts', function (Blueprint $table) {
            $table->dropIndex('context_artifacts_user_id_created_at_index');
            $table->dropConstrainedForeignId('project_ref_id');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('memory_links', function (Blueprint $table) {
            $table->dropIndex('memory_links_user_id_dataset_index');
            $table->dropConstrainedForeignId('project_ref_id');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('luczor_agent_event_archives', function (Blueprint $table) {
            $table->dropIndex('luczor_agent_event_archives_user_id_created_at_index');
            $table->dropConstrainedForeignId('project_ref_id');
            $table->dropConstrainedForeignId('user_id');
        });
        foreach (['luczor_project_archives', 'luczor_message_archives', 'luczor_memory_archives', 'luczor_summary_archives'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique($tableName.'_user_client_entity_external_unique');
                $table->dropIndex($tableName.'_user_id_updated_at_index');
                $table->dropConstrainedForeignId('project_ref_id');
                $table->dropConstrainedForeignId('user_id');
                $table->unique(['client_id', 'entity_type', 'external_id']);
            });
        }
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('projects');
    }
};
