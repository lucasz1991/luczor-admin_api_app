<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 120)->unique();
            $table->string('name');
            $table->string('status')->default('offline')->index();
            $table->text('public_key')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metrics')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->string('nonce', 120);
            $table->string('channel_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'expires_at']);
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 60)->index();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->string('risk_level', 30)->default('normal');
            $table->json('rules');
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'type', 'name']);
        });

        Schema::create('device_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_profile', 120);
            $table->string('status')->default('queued')->index();
            $table->string('risk_level', 30)->default('normal');
            $table->boolean('requires_local_approval')->default(true);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->text('signature')->nullable();
            $table->json('result')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status', 'created_at']);
            $table->index(['user_id', 'project_id']);
        });

        Schema::create('device_job_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 20);
            $table->string('reason')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['device_job_id', 'decision']);
        });

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active')->index();
            $table->json('definition');
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'name', 'version']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'project_id', 'status']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 120);
            $table->string('type', 80);
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(2);
            $table->boolean('requires_approval')->default(false);
            $table->json('depends_on')->nullable();
            $table->json('payload')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_run_id', 'step_key']);
            $table->index(['workflow_run_id', 'status', 'position']);
        });

        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();
            $table->string('server', 80);
            $table->string('tool', 120);
            $table->string('risk_level', 30)->default('normal');
            $table->string('status')->default('queued')->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'server', 'tool']);
        });

        Schema::create('performance_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_type', 120);
            $table->string('model_id', 180)->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->decimal('security_score', 5, 4)->nullable();
            $table->decimal('test_pass_rate', 5, 4)->nullable();
            $table->decimal('cost_score', 5, 4)->nullable();
            $table->decimal('hallucination_score', 5, 4)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'project_id', 'task_type']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tool_call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 120)->index();
            $table->string('tool')->nullable();
            $table->string('policy')->nullable();
            $table->string('approval')->nullable();
            $table->string('risk_level', 30)->default('normal');
            $table->string('outcome', 40)->default('recorded');
            $table->char('payload_hash', 64);
            $table->char('result_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_events', 'performance_profiles', 'tool_calls', 'workflow_steps', 'workflow_runs',
            'workflow_definitions', 'device_job_approvals', 'device_jobs', 'policies',
            'device_sessions', 'devices',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
