<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §14 P14 — nested workflows: parent link on runs, include-graph, edit-lock. */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: MySQL DDL is not transactional, so guard each change to
        // recover cleanly from a partially-applied run.
        Schema::table('workflow_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_runs', 'parent_workflow_run_id')) {
                $table->unsignedBigInteger('parent_workflow_run_id')->nullable()->after('workflow_definition_id');
            }
            if (! Schema::hasColumn('workflow_runs', 'parent_workflow_step_id')) {
                $table->unsignedBigInteger('parent_workflow_step_id')->nullable()->after('parent_workflow_run_id');
                $table->index('parent_workflow_step_id');
            }
        });

        if (! Schema::hasColumn('workflow_definitions', 'is_locked')) {
            Schema::table('workflow_definitions', function (Blueprint $table) {
                $table->boolean('is_locked')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('workflow_definition_dependencies')) {
            Schema::create('workflow_definition_dependencies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
                $table->foreignId('child_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
                $table->timestamps();
                // Explicit name: the auto-generated one is 79 chars, MySQL caps at 64.
                $table->unique(['parent_definition_id', 'child_definition_id'], 'wdd_parent_child_unique');
                $table->index('child_definition_id');
            });
        } elseif (! Schema::hasIndex('workflow_definition_dependencies', 'wdd_parent_child_unique')) {
            // Patch a partially-created table: the earlier ALTER failed on the
            // over-long auto name AFTER the table + FKs were already created.
            Schema::table('workflow_definition_dependencies', function (Blueprint $table) {
                $table->unique(['parent_definition_id', 'child_definition_id'], 'wdd_parent_child_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definition_dependencies');
        Schema::table('workflow_definitions', fn (Blueprint $t) => $t->dropColumn('is_locked'));
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropIndex(['parent_workflow_step_id']);
            $table->dropColumn(['parent_workflow_run_id', 'parent_workflow_step_id']);
        });
    }
};
