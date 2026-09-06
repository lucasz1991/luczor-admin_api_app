<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_rankings', function (Blueprint $table): void {
            $table->dropUnique('model_rankings_user_task_model_unique');
        });
    }

    public function down(): void
    {
        Schema::table('model_rankings', function (Blueprint $table): void {
            $table->unique(['user_id', 'task_type', 'model_id'], 'model_rankings_user_task_model_unique');
        });
    }
};
