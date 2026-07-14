<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOLL §15 P27 — Skill-System: reusable prompt/task bundles. A skill is either
 * a reusable instruction fragment (kind=prompt, injected like a scoped role) or
 * a named workflow bundle (kind=workflow, launched on demand). The memory scope
 * 'skill' already exists; this table makes skills first-class + admin-managed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skills')) {
            return;
        }
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 20)->default('prompt');      // prompt | workflow
            $table->text('prompt')->nullable();                 // kind=prompt payload
            $table->foreignId('workflow_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tags')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['active', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
