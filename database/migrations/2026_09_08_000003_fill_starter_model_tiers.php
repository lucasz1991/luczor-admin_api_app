<?php

use App\Services\LocalModelTierService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $catalog = DB::table('local_model_catalogs')->where('id', 1)->lockForUpdate()->first();
            if (! $catalog) {
                return;
            }
            $draft = app(LocalModelTierService::class)->upgradeStarterDraft(json_decode($catalog->draft, true, 512, JSON_THROW_ON_ERROR));
            if ($draft === null) {
                return;
            }
            $revision = max((int) $catalog->revision, (int) config('local_models.catalog_version'), (int) config('local_models.policy_version')) + 1;
            $draft['catalog_version'] = $revision;
            $draft['policy_version'] = $revision;
            DB::table('local_model_catalogs')->where('id', 1)->update([
                'draft' => json_encode($draft, JSON_THROW_ON_ERROR), 'revision' => $revision, 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Keep administrator data and published catalogs; rollback must not replace model selections.
    }
};
