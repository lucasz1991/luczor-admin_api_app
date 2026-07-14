<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §15 P19 — prompt templates gain a role + priority for the optimizer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('prompt_templates', 'role')) {
                $table->string('role', 40)->nullable()->after('task_type')->index();
            }
            if (! Schema::hasColumn('prompt_templates', 'priority')) {
                $table->unsignedInteger('priority')->default(100)->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->dropColumn(['role', 'priority']);
        });
    }
};
