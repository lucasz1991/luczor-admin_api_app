<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal provider credentials: rows with a user_id belong to exactly one
 * user, rows without stay global (organization) credentials as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            // NULL user_id rows are distinct, so global credentials stay unrestricted.
            $table->unique(['user_id', 'provider'], 'provider_credentials_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->dropUnique('provider_credentials_user_provider_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
