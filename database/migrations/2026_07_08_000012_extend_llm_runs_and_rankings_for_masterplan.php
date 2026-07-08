<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('llm_runs', 'workflow_id')) {
                $table->string('workflow_id')->nullable()->after('project_id')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'task_id')) {
                $table->string('task_id')->nullable()->after('workflow_id')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'session_id')) {
                $table->string('session_id')->nullable()->after('task_id')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'feature_key')) {
                $table->string('feature_key')->nullable()->after('task_type')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'context_id')) {
                $table->string('context_id')->nullable()->after('feature_key')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'prompt_template_id')) {
                $table->string('prompt_template_id')->nullable()->after('provider_id');
            }
            if (! Schema::hasColumn('llm_runs', 'context_strategy_id')) {
                $table->string('context_strategy_id')->nullable()->after('prompt_template_id');
            }
            if (! Schema::hasColumn('llm_runs', 'network_policy_id')) {
                $table->string('network_policy_id')->nullable()->after('context_strategy_id');
            }
            if (! Schema::hasColumn('llm_runs', 'repo_id')) {
                $table->string('repo_id')->nullable()->after('network_policy_id')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'branch')) {
                $table->string('branch')->nullable()->after('repo_id');
            }
            if (! Schema::hasColumn('llm_runs', 'commit_sha')) {
                $table->string('commit_sha', 80)->nullable()->after('branch')->index();
            }
            if (! Schema::hasColumn('llm_runs', 'cost_total')) {
                $table->decimal('cost_total', 12, 6)->default(0)->after('output_tokens');
            }
            if (! Schema::hasColumn('llm_runs', 'quality_score')) {
                $table->decimal('quality_score', 5, 4)->nullable()->after('cost_total');
            }
            if (! Schema::hasColumn('llm_runs', 'test_passed')) {
                $table->boolean('test_passed')->nullable()->after('quality_score');
            }
            if (! Schema::hasColumn('llm_runs', 'tool_call_count')) {
                $table->integer('tool_call_count')->default(0)->after('test_passed');
            }
            if (! Schema::hasColumn('llm_runs', 'retry_count')) {
                $table->integer('retry_count')->default(0)->after('tool_call_count');
            }
        });

        Schema::table('model_rankings', function (Blueprint $table) {
            if (! Schema::hasColumn('model_rankings', 'test_pass_rate')) {
                $table->decimal('test_pass_rate', 5, 4)->nullable()->after('success_rate');
            }
            if (! Schema::hasColumn('model_rankings', 'quality_score')) {
                $table->decimal('quality_score', 5, 4)->nullable()->after('test_pass_rate');
            }
            if (! Schema::hasColumn('model_rankings', 'avg_cost_total')) {
                $table->decimal('avg_cost_total', 12, 6)->nullable()->after('avg_latency_ms');
            }
            if (! Schema::hasColumn('model_rankings', 'avg_input_tokens')) {
                $table->integer('avg_input_tokens')->nullable()->after('avg_cost_total');
            }
            if (! Schema::hasColumn('model_rankings', 'context_efficiency_score')) {
                $table->decimal('context_efficiency_score', 5, 4)->nullable()->after('avg_input_tokens');
            }
        });
    }

    public function down(): void
    {
        Schema::table('model_rankings', function (Blueprint $table) {
            foreach (['context_efficiency_score', 'avg_input_tokens', 'avg_cost_total', 'quality_score', 'test_pass_rate'] as $column) {
                if (Schema::hasColumn('model_rankings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('llm_runs', function (Blueprint $table) {
            foreach ([
                'retry_count', 'tool_call_count', 'test_passed', 'quality_score', 'cost_total',
                'commit_sha', 'branch', 'repo_id', 'network_policy_id', 'context_strategy_id',
                'prompt_template_id', 'context_id', 'feature_key', 'session_id', 'task_id', 'workflow_id',
            ] as $column) {
                if (Schema::hasColumn('llm_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
