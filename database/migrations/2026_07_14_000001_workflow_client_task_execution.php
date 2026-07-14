<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOLL §14 P15b — client-task execution: a workflow step that runs on a device
 * references its device_job bundle via external_run_type/external_run_id
 * (planned in P15, required now that the executor dispatches client tasks).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guards — MySQL DDL is not transactional (see workflow_nesting).
        Schema::table('workflow_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_steps', 'external_run_type')) {
                $table->string('external_run_type', 40)->nullable()->after('error');
            }
            if (! Schema::hasColumn('workflow_steps', 'external_run_id')) {
                $table->string('external_run_id', 120)->nullable()->after('external_run_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['external_run_type', 'external_run_id']);
        });
    }
};
