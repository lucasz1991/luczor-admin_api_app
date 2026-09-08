<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('secret_hash', 64);
            $table->string('client_id', 120);
            $table->string('name', 120);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('credential')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('master_device_id')->nullable()->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('master_device_id'));
        Schema::dropIfExists('device_pairings');
    }
};
