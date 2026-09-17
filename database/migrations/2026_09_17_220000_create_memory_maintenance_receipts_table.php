<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_maintenance_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->json('metadata');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_maintenance_receipts');
    }
};
