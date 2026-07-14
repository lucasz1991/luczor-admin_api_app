<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PromptTemplate extends Model
{
    protected $fillable = ['key', 'version', 'task_type', 'role', 'priority', 'body', 'status', 'meta'];
    protected $casts = ['version' => 'integer', 'priority' => 'integer', 'meta' => 'array'];

    /**
     * SOLL §19 — publish a new active version transactionally: archive the
     * currently-active version(s) of this key, then insert the next version.
     *
     * @param array<string,mixed> $attributes
     */
    public static function publish(string $key, string $body, array $attributes = []): self
    {
        return DB::transaction(function () use ($key, $body, $attributes) {
            static::query()->where('key', $key)->where('status', 'active')->update(['status' => 'archived']);
            $version = ((int) static::query()->where('key', $key)->max('version')) + 1;

            return static::create(array_merge($attributes, [
                'key' => $key, 'body' => $body, 'version' => $version, 'status' => 'active',
            ]));
        });
    }

    /**
     * Active, role-tagged prompt rules for a role (plus the 'all' role), ordered
     * by ascending priority. The global luczor.system (role = null) is excluded.
     *
     * @return Collection<int,self>
     */
    public static function activeRolePrompts(string $role): Collection
    {
        return static::query()
            ->where('status', 'active')
            ->whereNotNull('role')
            ->whereIn('role', [$role, 'all'])
            ->orderBy('priority')
            ->orderBy('key')
            ->get();
    }
}
