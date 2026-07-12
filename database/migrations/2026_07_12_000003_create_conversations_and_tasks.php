<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §1.7/1.8 — agent task management + multiple chats per project. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_id');
            $table->uuid('external_id');
            $table->foreignId('project_ref_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'external_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_id');
            $table->uuid('external_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('open');       // open|in_progress|done|cancelled
            $table->string('priority', 16)->default('normal');   // low|normal|high
            $table->foreignId('project_ref_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('conversation_id')->nullable();       // conversations.external_id
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'external_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('conversations');
    }
};
