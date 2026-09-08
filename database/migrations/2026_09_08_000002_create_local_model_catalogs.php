<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_model_catalogs', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->json('draft');
            $table->json('published')->nullable();
            $table->unsignedBigInteger('revision');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_model_catalogs');
    }
};
