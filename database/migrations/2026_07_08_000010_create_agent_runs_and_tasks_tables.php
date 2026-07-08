<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('project_id')->nullable()->index();
            $table->string('workflow_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('task_type')->default('chat.general')->index();
            $table->string('feature_key')->nullable()->index();
            $table->string('status')->default('queued')->index();
            $table->string('context_id')->nullable()->index();
            $table->string('model_id')->nullable();
            $table->string('provider_id')->nullable();
            $table->text('goal')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();
            $table->string('task_type')->default('chat.general')->index();
            $table->string('feature_key')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open')->index();
            $table->unsignedInteger('priority')->default(100);
            $table->json('acceptance_criteria')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
        Schema::dropIfExists('agent_runs');
    }
};
