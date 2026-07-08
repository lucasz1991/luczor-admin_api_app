<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_run_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_run_id')->constrained()->cascadeOnDelete();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('context_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('tool_call_count')->default(0);
            $table->integer('retry_count')->default(0);
            $table->decimal('cost_total', 12, 6)->default(0);
            $table->string('prompt_template_id')->nullable();
            $table->string('context_strategy_id')->nullable();
            $table->string('network_policy_id')->nullable();
            $table->json('raw_usage')->nullable();
            $table->timestamps();
        });

        Schema::create('evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evaluator_id')->default('luczor.mvp');
            $table->string('status')->default('unknown');
            $table->decimal('success_score', 5, 4)->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->decimal('test_pass_rate', 5, 4)->nullable();
            $table->decimal('security_score', 5, 4)->nullable();
            $table->decimal('diff_quality_score', 5, 4)->nullable();
            $table->decimal('context_efficiency_score', 5, 4)->nullable();
            $table->integer('hallucination_flags')->default(0);
            $table->integer('user_feedback')->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_results');
        Schema::dropIfExists('llm_run_metrics');
    }
};
