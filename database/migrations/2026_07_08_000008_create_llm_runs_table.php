<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('client_id')->nullable();
            $table->string('project_id')->nullable()->index();
            $table->string('task_type')->default('chat.general')->index();
            $table->string('model_id')->index();
            $table->string('provider_id')->default('openrouter');
            $table->string('status')->default('ok'); // ok|error
            $table->boolean('success')->default(true);
            $table->integer('latency_ms')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['task_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_runs');
    }
};
