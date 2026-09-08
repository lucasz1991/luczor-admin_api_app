<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Named priorities reuse the ledger's existing importance field and fingerprint. */
final class MemoryPriority
{
    public const WEIGHTS = ['background' => 0.2, 'normal' => 0.5, 'high' => 0.8, 'critical' => 1.0];

    public const LABELS = ['background' => 'Hintergrund', 'normal' => 'Normal', 'high' => 'Wichtig', 'critical' => 'Kritisch'];

    public static function resolve(array $data): float
    {
        if (isset($data['priority'])) {
            if (! is_string($data['priority']) || ! array_key_exists($data['priority'], self::WEIGHTS)) {
                throw ValidationException::withMessages(['priority' => 'Unknown memory priority.']);
            }

            return self::WEIGHTS[$data['priority']];
        }

        return max(0.0, min(1.0, (float) ($data['importance'] ?? 0.5)));
    }

    public static function name(float $importance): string
    {
        return match (true) {
            $importance >= 0.95 => 'critical',
            $importance >= 0.7 => 'high',
            $importance >= 0.35 => 'normal',
            default => 'background',
        };
    }
}
