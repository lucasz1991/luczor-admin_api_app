<?php

namespace App\Services;

use App\Models\MemoryLink;

/** One fail-closed policy shared by projection writers, cleanup and workers. */
final class MemoryProjectionPolicy
{
    /** @var array<int,string> */
    private const INELIGIBLE_STATUSES = [
        'delete_pending',
        'not_required',
        'legacy_review_required',
        'local_only',
    ];

    public static function isEligible(MemoryLink $link, ?\DateTimeInterface $now = null): bool
    {
        return self::isActiveWithinValidity($link, $now)
            && ! in_array($link->projection_status, self::INELIGIBLE_STATUSES, true)
            && self::passesContentPolicy($link);
    }

    public static function isActiveWithinValidity(MemoryLink $link, ?\DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $link->status === 'active'
            && $link->retention !== 'session'
            && (! $link->valid_from || $link->valid_from->lte($now))
            && (! $link->valid_until || $link->valid_until->gt($now))
            && (! $link->expires_at || $link->expires_at->gt($now));
    }

    public static function passesContentPolicy(MemoryLink $link): bool
    {
        if (! in_array($link->retention, ['durable', 'permanent'], true)
            || ! in_array($link->visibility, ['syncable', 'public'], true)
            || $link->sensitivity === 'secret'
            || ! preg_match('/^[a-f0-9]{64}$/', (string) $link->content_hash)) {
            return false;
        }

        $payload = [
            'content' => $link->summary,
            'external_id' => $link->external_id,
            'feature_key' => $link->feature_key,
            'source_type' => $link->source_type,
            'source_ref' => $link->source_ref,
            'project_id' => $link->project_id,
            'session_id' => $link->session_id,
            'provenance' => $link->provenance,
            'meta' => $link->meta,
        ];

        return ! MemoryDlp::containsSecretInMemoryPayload($payload)
            && ! MemoryDlp::containsLocalOnlySourceInMemoryPayload($payload);
    }

    public static function hasExplicitlyIneligibleStatus(MemoryLink $link): bool
    {
        return in_array($link->projection_status, self::INELIGIBLE_STATUSES, true);
    }
}
