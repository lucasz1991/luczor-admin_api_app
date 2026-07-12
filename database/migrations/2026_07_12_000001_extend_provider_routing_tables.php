<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SOLL §1.1/1.2/1.4/1.5 — additive multi-provider routing columns.
 * The legacy model_profiles.provider string stays for backward compatibility
 * but credential lookup moves to provider_credential_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_profiles', function (Blueprint $table) {
            $table->foreignId('provider_credential_id')->nullable()
                ->after('provider')->constrained('provider_credentials')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(1)->after('active');
            $table->json('capabilities')->nullable()->after('meta');
            $table->unsignedInteger('context_window')->nullable()->after('capabilities');
        });

        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('active');
            $table->string('last_test_status', 32)->nullable()->after('last_tested_at');
            $table->string('last_error_code', 64)->nullable()->after('last_test_status');
            // OpenAI only: 'responses' (default) or 'chat_completions'.
            $table->string('request_format', 32)->nullable()->after('last_error_code');
        });

        Schema::table('model_use_cases', function (Blueprint $table) {
            $table->string('routing_strategy', 16)->default('manual')->after('active');
            $table->unsignedInteger('max_attempts')->default(3)->after('routing_strategy');
            $table->string('prompt_template_key')->nullable()->after('max_attempts');
            $table->string('network_policy_key')->nullable()->after('prompt_template_key');
            $table->boolean('review_enabled')->default(false)->after('network_policy_key');
            $table->foreignId('review_use_case_id')->nullable()
                ->after('review_enabled')->constrained('model_use_cases')->nullOnDelete();
        });

        Schema::table('model_rankings', function (Blueprint $table) {
            $table->foreignId('model_profile_id')->nullable()
                ->after('model_id')->constrained('model_profiles')->nullOnDelete();
            $table->unique(['user_id', 'task_type', 'model_profile_id'], 'model_rankings_user_task_profile_unique');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        // Link existing openrouter profiles to the newest active openrouter credential.
        $credentialId = DB::table('provider_credentials')
            ->where('provider', 'openrouter')->where('active', true)
            ->orderByDesc('id')->value('id');
        if ($credentialId) {
            DB::table('model_profiles')->where('provider', 'openrouter')
                ->update(['provider_credential_id' => $credentialId]);
        }

        // Gapless global library order (stable by id).
        foreach (DB::table('model_profiles')->orderBy('id')->pluck('id')->values() as $idx => $id) {
            DB::table('model_profiles')->where('id', $id)->update(['sort_order' => $idx + 1]);
        }

        // Key rankings by profile where the model_id string resolves uniquely.
        foreach (DB::table('model_profiles')->pluck('id', 'model_id') as $modelId => $profileId) {
            DB::table('model_rankings')->where('model_id', $modelId)
                ->update(['model_profile_id' => $profileId]);
        }

        // Rollout §13: STT/TTS use cases are deactivated, never deleted.
        DB::table('model_use_cases')->whereIn('slug', ['stt', 'tts'])->update(['active' => false]);
    }

    public function down(): void
    {
        Schema::table('model_rankings', function (Blueprint $table) {
            $table->dropUnique('model_rankings_user_task_profile_unique');
            $table->dropConstrainedForeignId('model_profile_id');
        });
        Schema::table('model_use_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_use_case_id');
            $table->dropColumn(['routing_strategy', 'max_attempts', 'prompt_template_key', 'network_policy_key', 'review_enabled']);
        });
        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->dropColumn(['last_tested_at', 'last_test_status', 'last_error_code', 'request_format']);
        });
        Schema::table('model_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_credential_id');
            $table->dropColumn(['sort_order', 'capabilities', 'context_window']);
        });
    }
};
