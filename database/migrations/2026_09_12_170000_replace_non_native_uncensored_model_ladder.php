<?php

use App\Services\LocalModelTierService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = DB::table('local_model_catalogs')->where('id', 1)->lockForUpdate()->first();
            if (! $catalog) {
                return;
            }

            $replacement = app(LocalModelTierService::class)->replaceNonNativeRequestedLadder(
                json_decode($catalog->draft, true, 512, JSON_THROW_ON_ERROR),
            );
            if ($replacement === null) {
                return;
            }

            $revision = max(
                (int) $catalog->revision,
                (int) config('local_models.catalog_version'),
                (int) config('local_models.policy_version'),
            ) + 1;
            $replacement['catalog_version'] = $revision;
            $replacement['policy_version'] = $revision;
            DB::table('local_model_catalogs')->where('id', 1)->update([
                'draft' => json_encode($replacement, JSON_THROW_ON_ERROR),
                'revision' => $revision,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Never restore non-native model IDs or overwrite an administrator's catalog.
    }
};
