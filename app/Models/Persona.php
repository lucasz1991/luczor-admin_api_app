<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SOLL §15 P27 — an AI personality: a named prompt preset injected right after
 * the global luczor.system prompt. The active persona is selected via the
 * `active_persona` server setting.
 */
class Persona extends Model
{
    protected $fillable = ['slug', 'name', 'prompt', 'active', 'meta'];

    protected $casts = ['active' => 'boolean', 'meta' => 'array'];

    /** Prompt of the currently active persona, or null when none is selected. */
    public static function activePrompt(): ?string
    {
        $slug = trim((string) Setting::getValue('active_persona', ''));
        if ($slug === '') {
            return null;
        }
        $persona = static::query()->where('slug', $slug)->where('active', true)->first();

        return $persona && trim((string) $persona->prompt) !== '' ? $persona->prompt : null;
    }
}
