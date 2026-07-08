<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_rankings', function (Blueprint $table) {
            $table->id();
            $table->string('task_type')->index();
            $table->string('model_id');
            $table->string('provider_id')->default('openrouter');
            $table->integer('sample_count')->default(0);
            $table->decimal('success_rate', 5, 4)->default(0);
            $table->integer('avg_latency_ms')->nullable();
            $table->decimal('score', 5, 4)->default(0);
            $table->timestamps();

            $table->unique(['task_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_rankings');
    }
};
