<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_use_cases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('model_use_case_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_use_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['model_use_case_id', 'model_profile_id']);
            $table->index(['model_use_case_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_use_case_entries');
        Schema::dropIfExists('model_use_cases');
    }
};
