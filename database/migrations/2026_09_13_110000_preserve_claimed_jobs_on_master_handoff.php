<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('authority_epoch')->nullable();
            $table->boolean('reconciliation_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('device_jobs', fn (Blueprint $table) => $table->dropColumn(['authority_epoch', 'reconciliation_required']));
    }
};
