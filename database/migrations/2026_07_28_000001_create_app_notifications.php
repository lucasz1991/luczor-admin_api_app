<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->string('notification_id', 160);
            $table->string('category', 40)->default('general');
            $table->string('title', 160);
            $table->text('body');
            $table->string('action_url', 2048)->nullable();
            $table->json('data')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'notification_id'], 'app_notifications_user_stable_id_unique');
            $table->index(['user_id', 'id'], 'app_notifications_user_sequence_index');
            $table->index(['user_id', 'read_at'], 'app_notifications_user_unread_index');
            $table->index(['target_device_id', 'id'], 'app_notifications_device_sequence_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->boolean('push_enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('app_notifications');
    }
};
