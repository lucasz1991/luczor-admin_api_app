<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_identity_locks', function (Blueprint $table) {
            // Only a one-way hash is retained. The row is both a portable lock
            // target and a durable serialization point for first writes.
            $table->char('identity_hash', 64)->primary();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_identity_locks');
    }
};
