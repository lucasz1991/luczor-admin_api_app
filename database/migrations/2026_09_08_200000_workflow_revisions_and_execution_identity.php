<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definition_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('change_summary', 1000)->nullable();
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'version'], 'workflow_revision_version_unique');
        });
        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->string('change_summary', 1000)->nullable();
        });
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_revision_id')->nullable();
            $table->json('definition_snapshot')->nullable();
            $table->uuid('parent_execution_id')->nullable()->unique();
        });
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->uuid('execution_id')->nullable()->unique();
            $table->unsignedInteger('execution_sequence')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->json('resolved_payload')->nullable();
        });
        Schema::table('device_jobs', function (Blueprint $table) {
            $table->uuid('workflow_execution_id')->nullable()->unique();
            $table->timestamp('cancel_requested_at')->nullable();
        });
        Schema::create('workflow_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('action', 40);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('completed');
            $table->longText('response');
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
        });
        DB::table('workflow_definitions')->orderBy('id')->chunkById(100, function ($definitions) {
            foreach ($definitions as $definition) {
                $id = DB::table('workflow_definition_revisions')->insertGetId([
                    'workflow_definition_id' => $definition->id, 'version' => $definition->version,
                    'name' => $definition->name, 'definition' => $definition->definition,
                    'definition_hash' => hash('sha256', $definition->definition),
                    'created_at' => $definition->created_at, 'updated_at' => $definition->updated_at,
                ]);
                DB::table('workflow_definitions')->where('id', $definition->id)->update(['current_revision_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_operations');
        Schema::table('device_jobs', fn (Blueprint $table) => $table->dropColumn(['workflow_execution_id', 'cancel_requested_at']));
        Schema::table('workflow_steps', fn (Blueprint $table) => $table->dropColumn(['execution_id', 'execution_sequence', 'approved_at', 'resolved_payload']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropColumn(['workflow_revision_id', 'definition_snapshot', 'parent_execution_id']));
        Schema::table('workflow_definitions', fn (Blueprint $table) => $table->dropColumn(['current_revision_id', 'change_summary']));
        Schema::dropIfExists('workflow_definition_revisions');
    }
};
