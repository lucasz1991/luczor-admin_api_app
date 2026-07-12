<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SOLL §7.1 — account-synced client preferences (voice + external agents).
 * Only allowlisted keys are accepted; conflicts resolve last-write-wins by the
 * client-supplied updated_at. Device audio/output ids stay LOCAL and are never
 * sent here.
 */
class PreferenceController extends Controller
{
    /** GET /api/v1/preferences -> { key: { value, updated_at } }. */
    public function index(Request $request)
    {
        $rows = UserPreference::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('key', UserPreference::ALLOWLIST)
            ->get(['key', 'value', 'updated_at']);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->key] = [
                'value' => $row->value,
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ];
        }

        return response()->json(['preferences' => $out]);
    }

    /** PUT /api/v1/preferences with { preferences: [ { key, value, updated_at? } ] }. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:64'],
            'preferences.*.key' => ['required', 'string'],
            'preferences.*.value' => ['present'],
            'preferences.*.updated_at' => ['nullable', 'date'],
        ]);

        $userId = $request->user()->id;
        $applied = [];
        $skipped = [];

        foreach ($data['preferences'] as $pref) {
            $key = $pref['key'];
            if (! in_array($key, UserPreference::ALLOWLIST, true)) {
                $skipped[] = $key;
                continue;
            }

            $incoming = isset($pref['updated_at']) ? Carbon::parse($pref['updated_at']) : Carbon::now();
            $existing = UserPreference::where('user_id', $userId)->where('key', $key)->first();

            // Last-write-wins: only apply when the incoming change is not older.
            if ($existing && $existing->updated_at && $existing->updated_at->gt($incoming)) {
                $skipped[] = $key;
                continue;
            }

            UserPreference::updateOrCreate(
                ['user_id' => $userId, 'key' => $key],
                ['value' => $pref['value'], 'updated_at' => $incoming],
            );
            $applied[] = $key;
        }

        return response()->json(['applied' => $applied, 'skipped' => $skipped]);
    }
}
