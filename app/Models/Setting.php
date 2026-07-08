<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Server-managed runtime settings, editable in the admin dashboard and exposed
 * to the desktop client via /api/v1/runtime-settings + /bootstrap.
 *
 * The JSON `value` column always holds an object {"v": <actual value>} so the
 * array cast works uniformly for scalars, booleans and arrays.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'label', 'type'];

    protected $casts = ['value' => 'array'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function putValue(string $key, mixed $value, array $attributes = []): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            array_merge($attributes, ['value' => ['v' => $value]])
        );
    }

    /** @return array<string,mixed> */
    public static function asMap(): array
    {
        return static::query()
            ->get()
            ->mapWithKeys(fn (self $s) => [$s->key => ($s->value['v'] ?? null)])
            ->toArray();
    }
}
