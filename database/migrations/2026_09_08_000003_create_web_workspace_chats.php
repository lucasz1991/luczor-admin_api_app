<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_workspace_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('scope', 20);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
        Schema::create('web_workspace_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_workspace_chat_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_id')->unique();
            $table->foreignId('device_job_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_workspace_turns');
        Schema::dropIfExists('web_workspace_chats');
    }
};
