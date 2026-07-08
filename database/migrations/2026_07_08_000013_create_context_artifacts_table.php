<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('context_id')->unique();
            $table->string('project_id')->nullable()->index();
            $table->string('task_type')->default('chat.general')->index();
            $table->string('feature_key')->nullable()->index();
            $table->string('repo_id')->nullable()->index();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 80)->nullable()->index();
            $table->json('changed_files')->nullable();
            $table->json('code')->nullable();
            $table->json('memory')->nullable();
            $table->json('budget')->nullable();
            $table->json('score_explain')->nullable();
            $table->json('source_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_artifacts');
    }
};
