<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('label');
            $table->text('api_key');
            $table->string('base_url')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['provider', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
