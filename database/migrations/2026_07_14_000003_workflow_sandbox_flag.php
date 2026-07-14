<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOLL §15 P27 — Sandbox: a run flagged sandbox=true simulates every mutating or
 * client/device task instead of performing it (read-only tasks still run). Lets
 * an admin dry-run a workflow without real side effects or device jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_runs', 'sandbox')) {
                $table->boolean('sandbox')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('sandbox');
        });
    }
};
