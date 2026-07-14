<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SOLL §15 P27 (Ressourcenmanager) — per-use-case cost/token budget overrides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_use_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('model_use_cases', 'max_input_tokens')) {
                $table->unsignedInteger('max_input_tokens')->nullable()->after('max_attempts');
            }
            if (! Schema::hasColumn('model_use_cases', 'max_cost_usd')) {
                $table->decimal('max_cost_usd', 10, 6)->nullable()->after('max_input_tokens');
            }
        });
    }

    public function down(): void
    {
        Schema::table('model_use_cases', function (Blueprint $table) {
            $table->dropColumn(['max_input_tokens', 'max_cost_usd']);
        });
    }
};
