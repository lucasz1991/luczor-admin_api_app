<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §1.3/1.6 — provider model catalog + account-synced preferences (LWW). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_credential_id')->constrained('provider_credentials')->cascadeOnDelete();
            $table->string('model_id');
            $table->string('display_name')->nullable();
            $table->json('capabilities')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->json('pricing')->nullable();
            $table->string('provider_status', 32)->default('active');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_credential_id', 'model_id']);
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            // updated_at drives last-write-wins; created_at kept for audit.
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('provider_models');
    }
};
