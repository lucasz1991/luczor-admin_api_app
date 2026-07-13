<?php

namespace App\Services;

/**
 * SOLL §14 P13 — reduces a step's raw output to a routing outcome (adapted from
 * AI User Factory's WorkflowResultNormalizer::resultOutcome). Kept intentionally
 * small: routing only needs the coarse outcome bucket.
 */
class WorkflowResultNormalizer
{
    public const OUTCOMES = ['success', 'failed', 'partial', 'timeout'];

    /** @param array<string,mixed> $output */
    public static function outcome(array $output, string $fallback = 'success'): string
    {
        $raw = strtolower(trim((string) ($output['outcome'] ?? $output['status'] ?? '')));

        return in_array($raw, self::OUTCOMES, true) ? $raw : $fallback;
    }
}
