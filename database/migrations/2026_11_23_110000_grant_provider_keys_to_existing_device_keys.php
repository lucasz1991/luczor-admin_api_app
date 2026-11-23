<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every user manages their own Claude API key from the desktop. Device keys
 * issued before that feature lack the `provider.keys` ability and would get a
 * 403 until re-paired. A key that may already send chat requests through the
 * proxy (`proxy.use`) may also manage its owner's personal provider key; the
 * endpoints stay scoped to that owner and never return the key.
 */
return new class extends Migration
{
    private const ABILITY = 'provider.keys';

    public function up(): void
    {
        $this->each(function (array $abilities): ?array {
            if (! in_array('proxy.use', $abilities, true) || in_array(self::ABILITY, $abilities, true)) {
                return null;
            }
            $abilities[] = self::ABILITY;

            return $abilities;
        });
    }

    public function down(): void
    {
        $this->each(function (array $abilities): ?array {
            if (! in_array(self::ABILITY, $abilities, true)) {
                return null;
            }

            return array_values(array_filter($abilities, static fn ($ability) => $ability !== self::ABILITY));
        });
    }

    /** @param  callable(array<int, mixed>): ?array<int, mixed>  $change */
    private function each(callable $change): void
    {
        DB::table('api_keys')->select(['id', 'abilities'])->orderBy('id')->chunkById(200, function ($keys) use ($change): void {
            foreach ($keys as $key) {
                $abilities = json_decode((string) $key->abilities, true);
                if (! is_array($abilities)) {
                    continue;
                }
                $next = $change(array_values($abilities));
                if ($next !== null) {
                    DB::table('api_keys')->where('id', $key->id)->update(['abilities' => json_encode($next)]);
                }
            }
        });
    }
};
