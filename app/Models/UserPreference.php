<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'value'];

    protected $casts = ['value' => 'array'];

    /** SOLL §7.1 — the only keys accepted by GET/PUT /api/v1/preferences. */
    public const ALLOWLIST = [
        'voice_backend',
        'voice_auto_prefers_local',
        'hands_free_strategy',
        'voice_trigger_phrase',
        'voice_end_phrase',
        'voice_continuous_silence_ms',
        'voice_ptt_auto_send',
        'voice_hands_free_auto_send',
        'voice_tts_voice_id',
        'voice_tts_rate',
        'voice_tts_volume',
        'voice_auto_speech',
        'voice_auto_speech_mode',
        'voice_interrupt_mode',
        'agents_claude_mode',
        'agents_codex_mode',
        'agents_bridge_file',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
