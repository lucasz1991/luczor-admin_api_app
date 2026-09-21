<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mirror_manifests', function (Blueprint $table) {
            // Exact merge base of a device upload; the revision alone cannot name an accepted (merged) upload.
            $table->uuid('base_manifest_id')->nullable()->after('base_revision');
            $table->unsignedInteger('conflict_count')->default(0)->after('total_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('project_mirror_manifests', function (Blueprint $table) {
            $table->dropColumn(['base_manifest_id', 'conflict_count']);
        });
    }
};
