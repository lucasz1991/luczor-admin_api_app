<?php

namespace App\Services;

use DateTimeInterface;
use Stringable;

/**
 * Bounded, fail-closed DLP inspection for memory content and metadata.
 *
 * Memory metadata is untrusted structured input. The traversal therefore has
 * strict complexity limits and treats unsupported or truncated input as
 * sensitive instead of partially inspecting and then allowing it.
 */
final class MemoryDlp
{
    private const MAX_DEPTH = 8;

    private const MAX_NODES = 1024;

    private const MAX_STRING_BYTES = 65536;

    private const SECRET_PATTERN = '~(?:
        \b(passwor[dt]|api[\s_-]?key|access[\s_-]?token|secret|geheim|kennwort|private[\s_-]?key|authorization|auth[\s_-]?token|iban|kreditkart)\b
        |-----BEGIN[ ]+[A-Z0-9 ]*PRIVATE[ ]KEY-----
        |\b(?:gh[pousr]_|github_pat_|sk-(?:proj-)?|xox[baprs]-)[A-Za-z0-9_-]{16,}\b
        |\bAKIA[0-9A-Z]{16}\b
        |\bAIza[0-9A-Za-z_-]{30,}\b
        |\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b
        |\b[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@
        |\b(?:Bearer|Basic)[ ]+[A-Za-z0-9+/=_-]{12,}\b
    )~ix';

    private const LOCAL_ONLY_SOURCES = [
        'repository',
        'repository_graph',
        'code_graph',
        'raw_code',
        'screen_secret',
    ];

    /** @param array<string,mixed> $payload */
    public static function containsSecretInMemoryPayload(array $payload): bool
    {
        return self::containsSecret([
            'content' => $payload['content'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'feature_key' => $payload['feature_key'] ?? ($payload['memory_key'] ?? null),
            'type' => $payload['type'] ?? null,
            'source_type' => $payload['source_type'] ?? ($payload['source'] ?? null),
            'source_ref' => $payload['source_ref'] ?? null,
            'project_id' => $payload['project_id'] ?? null,
            'agent_id' => $payload['agent_id'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'provenance' => $payload['provenance'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);
    }

    public static function containsSecret(mixed $value): bool
    {
        $nodes = 0;
        $stringBytes = 0;

        return self::inspect($value, 0, $nodes, $stringBytes);
    }

    /**
     * Detect a local-only origin even when an untrusted client nests it below
     * `meta` or provenance. Complexity-limit failures are denied so a deeply
     * nested payload cannot bypass the repository boundary.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function containsLocalOnlySourceInMemoryPayload(array $payload): bool
    {
        $nodes = 0;
        $stringBytes = 0;

        return self::inspectSource([
            'source_type' => $payload['source_type'] ?? ($payload['source'] ?? null),
            'provenance' => $payload['provenance'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ], 0, $nodes, $stringBytes, false);
    }

    private static function inspect(mixed $value, int $depth, int &$nodes, int &$stringBytes): bool
    {
        $nodes++;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return true;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return false;
        }

        if (is_string($value)) {
            $stringBytes += strlen($value);
            if ($stringBytes > self::MAX_STRING_BYTES) {
                return true;
            }

            // Invalid UTF-8 makes the Unicode regex fail. Treat that as an
            // inspection failure instead of silently allowing the value.
            return preg_match(self::SECRET_PATTERN, $value) !== 0;
        }

        if ($value instanceof DateTimeInterface) {
            return self::inspect($value->format(DATE_ATOM), $depth + 1, $nodes, $stringBytes);
        }

        if ($value instanceof Stringable) {
            return self::inspect((string) $value, $depth + 1, $nodes, $stringBytes);
        }

        if (! is_array($value)) {
            return true;
        }

        if ($depth >= self::MAX_DEPTH && $value !== []) {
            return true;
        }

        foreach ($value as $key => $child) {
            if (self::isSensitiveKey((string) $key)
                || self::inspect((string) $key, $depth + 1, $nodes, $stringBytes)
                || self::inspect($child, $depth + 1, $nodes, $stringBytes)) {
                return true;
            }
        }

        return false;
    }

    private static function isSensitiveKey(string $key): bool
    {
        // Split both camelCase and acronym boundaries before removing all
        // punctuation. Applying the value regex directly to `password_hash`
        // is unsafe because `_` is a regex word character and defeats `\b`.
        $spaced = preg_replace(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            ' ',
            $key,
        ) ?? $key;
        $spaced = strtolower(trim(
            preg_replace('/[^a-z0-9]+/i', ' ', $spaced) ?? $spaced,
        ));
        if ($spaced === '') {
            return false;
        }

        if (preg_match(
            '/(?:^| )(?:password|passwd|passphrase|secret|geheim|kennwort|authorization|credential|credentials|iban|credit card|kreditkarte|kreditkart|api key|access key|access token|auth token|refresh token|private key|client secret|db password|database password)(?: |$)/i',
            $spaced,
        ) === 1) {
            return true;
        }

        // Handles acronym-heavy keys such as APIKey or DBPassword even when a
        // producer did not insert a camel-case boundary consistently.
        $compact = str_replace(' ', '', $spaced);

        return in_array($compact, [
            'passwordhash',
            'apikey',
            'accesskey',
            'accesstoken',
            'authtoken',
            'refreshtoken',
            'privatekey',
            'clientsecret',
            'dbpassword',
            'databasepassword',
        ], true);
    }

    private static function inspectSource(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$stringBytes,
        bool $sourceField,
    ): bool {
        $nodes++;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return true;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return false;
        }

        if (is_string($value)) {
            $stringBytes += strlen($value);
            if ($stringBytes > self::MAX_STRING_BYTES || ! mb_check_encoding($value, 'UTF-8')) {
                return true;
            }

            if (! $sourceField) {
                return false;
            }

            $source = strtolower(trim(
                preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value,
                '_'
            ));

            return in_array($source, self::LOCAL_ONLY_SOURCES, true);
        }

        if ($value instanceof DateTimeInterface || $value instanceof Stringable) {
            return self::inspectSource((string) $value, $depth + 1, $nodes, $stringBytes, $sourceField);
        }

        if (! is_array($value) || ($depth >= self::MAX_DEPTH && $value !== [])) {
            return true;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $key) ?? (string) $key);
            $childIsSource = $sourceField || in_array($normalizedKey, ['source', 'sourcetype', 'origintype'], true);
            if (self::inspectSource($child, $depth + 1, $nodes, $stringBytes, $childIsSource)) {
                return true;
            }
        }

        return false;
    }
}
