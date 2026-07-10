<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan', 60)->default('standard');
            $table->string('status', 30)->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete());
        Schema::table('projects', fn (Blueprint $table) => $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete());

        Schema::create('provider_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id', 80)->index();
            $table->string('model_id', 190)->index();
            $table->string('currency', 8)->default('USD');
            $table->decimal('input_per_million', 14, 8)->default(0);
            $table->decimal('output_per_million', 14, 8)->default(0);
            $table->decimal('cache_read_per_million', 14, 8)->default(0);
            $table->decimal('cache_write_per_million', 14, 8)->default(0);
            $table->string('source', 80)->default('admin');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['provider_id', 'model_id', 'valid_from']);
        });

        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name');
            $table->string('type', 80)->index();
            $table->string('status', 30)->default('active')->index();
            $table->string('prompt_template_key', 120)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('required_sources')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('llm_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_snapshot_id')->nullable()->constrained('provider_price_snapshots')->nullOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('provider_id', 80)->index();
            $table->string('model_id', 190)->index();
            $table->string('upstream_generation_id', 190)->nullable()->index();
            $table->string('status', 40)->default('started')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('finish_reason', 80)->nullable();
            $table->string('error_type', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('connect_ms')->nullable();
            $table->unsignedInteger('ttft_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->decimal('tokens_per_second', 12, 4)->nullable();
            $table->decimal('provider_cost', 14, 8)->nullable();
            $table->decimal('calculated_cost', 14, 8)->nullable();
            $table->decimal('effective_cost', 14, 8)->nullable();
            $table->string('cost_source', 40)->nullable();
            $table->char('request_hash', 64)->nullable()->index();
            $table->char('response_hash', 64)->nullable();
            $table->json('routing_meta')->nullable();
            $table->json('raw_usage')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('first_token_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['llm_run_id', 'attempt_no']);
        });

        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('task_type', 120)->nullable()->index();
            $table->longText('body');
            $table->string('status', 30)->default('active')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('context_strategies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name');
            $table->string('status', 30)->default('active')->index();
            $table->json('config');
            $table->timestamps();
        });

        Schema::create('network_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name');
            $table->string('status', 30)->default('active')->index();
            $table->unsignedInteger('connect_timeout_ms')->default(10000);
            $table->unsignedInteger('request_timeout_ms')->default(90000);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('backoff_ms')->default(250);
            $table->decimal('max_cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('max_input_tokens')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('llm_experiments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name');
            $table->string('task_type', 120)->index();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedTinyInteger('traffic_percent')->default(0);
            $table->json('variants');
            $table->json('success_criteria')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('graph_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 80)->index();
            $table->string('graph_version', 80);
            $table->string('status', 30)->default('ready')->index();
            $table->string('path', 1000)->nullable();
            $table->unsignedInteger('node_count')->nullable();
            $table->unsignedInteger('edge_count')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['repository_id', 'commit_sha', 'graph_version']);
        });

        Schema::create('retrieval_cache_entries', function (Blueprint $table) {
            $table->id();
            $table->string('cache_type', 40)->index();
            $table->char('cache_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commit_sha', 80)->nullable()->index();
            $table->string('model_id', 190)->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->string('storage_ref', 1000)->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('llm_runs', function (Blueprint $table) {
            $table->string('request_id', 120)->nullable()->after('id')->unique();
            $table->string('selected_by', 40)->nullable()->after('model_id');
            $table->unsignedInteger('attempt_count')->default(0)->after('retry_count');
            $table->unsignedInteger('ttft_ms')->nullable()->after('latency_ms');
            $table->decimal('tokens_per_second', 12, 4)->nullable()->after('ttft_ms');
            $table->decimal('provider_cost', 14, 8)->nullable()->after('cost_total');
            $table->decimal('calculated_cost', 14, 8)->nullable()->after('provider_cost');
            $table->string('cost_source', 40)->nullable()->after('calculated_cost');
            $table->string('finish_reason', 80)->nullable()->after('status');
            $table->char('request_hash', 64)->nullable()->after('finish_reason')->index();
            $table->char('response_hash', 64)->nullable()->after('request_hash');
        });

        Schema::table('llm_run_metrics', function (Blueprint $table) {
            $table->unsignedInteger('ttft_ms')->nullable()->after('latency_ms');
            $table->decimal('tokens_per_second', 12, 4)->nullable()->after('ttft_ms');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('output_tokens');
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('reasoning_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
            $table->decimal('provider_cost', 14, 8)->nullable()->after('cost_total');
            $table->decimal('calculated_cost', 14, 8)->nullable()->after('provider_cost');
            $table->string('cost_source', 40)->nullable()->after('calculated_cost');
            $table->json('provider_meta')->nullable()->after('raw_usage');
        });

        Schema::table('tool_calls', function (Blueprint $table) {
            $table->foreignId('llm_run_id')->nullable()->after('workflow_step_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('duration_ms')->nullable()->after('status');
            $table->text('error')->nullable()->after('output');
            $table->timestamp('started_at')->nullable()->after('error');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });

        Schema::table('model_rankings', function (Blueprint $table) {
            $table->unsignedInteger('avg_ttft_ms')->nullable()->after('avg_latency_ms');
            $table->decimal('avg_tokens_per_second', 12, 4)->nullable()->after('avg_ttft_ms');
            $table->decimal('cost_per_success', 14, 8)->nullable()->after('avg_cost_total');
            $table->decimal('fallback_rate', 5, 4)->nullable()->after('context_efficiency_score');
        });
    }

    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('llm_run_id');
            $table->dropColumn(['duration_ms', 'error', 'started_at', 'finished_at']);
        });
        Schema::table('model_rankings', fn (Blueprint $table) => $table->dropColumn(['avg_ttft_ms', 'avg_tokens_per_second', 'cost_per_success', 'fallback_rate']));
        Schema::table('llm_run_metrics', function (Blueprint $table) {
            $table->dropColumn(['ttft_ms', 'tokens_per_second', 'reasoning_tokens', 'cache_read_tokens', 'cache_write_tokens', 'provider_cost', 'calculated_cost', 'cost_source', 'provider_meta']);
        });
        Schema::table('llm_runs', function (Blueprint $table) {
            $table->dropColumn(['request_id', 'selected_by', 'attempt_count', 'ttft_ms', 'tokens_per_second', 'provider_cost', 'calculated_cost', 'cost_source', 'finish_reason', 'request_hash', 'response_hash']);
        });
        Schema::dropIfExists('retrieval_cache_entries');
        Schema::dropIfExists('graph_snapshots');
        Schema::dropIfExists('llm_experiments');
        Schema::dropIfExists('network_policies');
        Schema::dropIfExists('context_strategies');
        Schema::dropIfExists('prompt_templates');
        Schema::dropIfExists('llm_attempts');
        Schema::dropIfExists('agent_profiles');
        Schema::dropIfExists('provider_price_snapshots');
        Schema::table('projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        Schema::dropIfExists('tenants');
    }
};
