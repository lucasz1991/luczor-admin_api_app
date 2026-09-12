<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('cloud_enabled')->default(false)->index();
            $table->unsignedBigInteger('cloud_revision')->default(0);
            $table->longText('cloud_snapshot')->nullable();
            $table->string('cloud_updated_by_device', 120)->nullable();
        });

        Schema::create('project_cloud_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('path', 240);
            $table->char('path_key', 64);
            $table->string('storage_key')->nullable();
            $table->unsignedBigInteger('revision');
            $table->unsignedInteger('bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->string('updated_by_device', 120)->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'path_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cloud_files');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['cloud_enabled', 'cloud_revision', 'cloud_snapshot', 'cloud_updated_by_device']);
        });
    }
};
