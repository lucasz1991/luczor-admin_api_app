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

    private const DIRECT_IDENTIFIER_PATTERN = '~(?:
        \b[A-Z0-9.!#$%&\'*+/=?^_`{|}\~-]+@[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?)+\b
        |(?<!\w)(?:\+|00)\d{1,3}(?:[\s()./-]*\d){6,14}\b
        |(?<!\w)0\d{2,5}(?:[\s()./-]*\d){5,10}\b
    )~ixu';

    /**
     * The candidate may include trailing space-separated text. Validation
     * below checks every legal IBAN-length prefix with MOD-97, so an IBAN is
     * still found without treating arbitrary account-like text as one.
     */
    private const IBAN_CANDIDATE_PATTERN = '~(?<![A-Z0-9])[A-Z]{2}[ -]?\d{2}(?:[ -]?[A-Z0-9]){11,40}~iu';

    private const PAYMENT_CARD_CANDIDATE_PATTERN = '~(?<![A-Z0-9])(?:\d[ -]?){12,18}\d(?![A-Z0-9])~iu';

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
     * Only plain-language, non-sensitive recall queries may cross the Cognee
     * boundary. Everything else still receives canonical SQL lexical recall.
     */
    public static function allowsExternalSemanticQuery(string $query): bool
    {
        $query = trim($query);
        if ($query === ''
            || strlen($query) > 2000
            || ! mb_check_encoding($query, 'UTF-8')
            || self::containsSecret($query)
            || self::containsDirectIdentifier($query)) {
            return false;
        }

        // Repository excerpts commonly contain multiline/code delimiters,
        // local paths or source filenames. False positives only disable the
        // optional semantic ranker; SQL recall remains available fail-closed.
        return preg_match('/[\r\n{}\[\]<>=$`\\\\|]/u', $query) !== 1
            && preg_match('~(?:^|[\s(])(?:[A-Za-z]:\\\\|/(?:home|Users|var|srv|opt)/|\.\.?[/\\\\])~u', $query) !== 1
            && preg_match('/\b[\w.-]+\.(?:php|blade\.php|js|jsx|ts|tsx|vue|rs|py|go|java|cs|cpp|c|h)(?::\d+)?\b/iu', $query) !== 1
            && preg_match('/(?:=>|::|->|\b(?:function|interface|namespace|import|export|const|let|var|def|fn)\s+[A-Za-z_$][\w$]*)/u', $query) !== 1;
    }

    /**
     * Decide whether a canonical memory may be copied to an external semantic
     * projection. Direct identifiers are intentionally stricter here than for
     * SQL storage: the user can retain an email address locally without also
     * sending it to Cognee's configured embedding or LLM provider.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function allowsExternalSemanticContent(array $payload): bool
    {
        if (($payload['sensitivity'] ?? 'normal') !== 'normal'
            || self::containsSecretInMemoryPayload($payload)
            || self::containsLocalOnlySourceInMemoryPayload($payload)) {
            return false;
        }

        $nodes = 0;
        $stringBytes = 0;

        return ! self::inspectDirectIdentifier([
            'content' => $payload['content'] ?? null,
            'source_ref' => $payload['source_ref'] ?? null,
            'provenance' => $payload['provenance'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ], 0, $nodes, $stringBytes);
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

    private static function inspectDirectIdentifier(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$stringBytes,
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

            return $stringBytes > self::MAX_STRING_BYTES
                || ! mb_check_encoding($value, 'UTF-8')
                || self::containsDirectIdentifier($value);
        }

        if ($value instanceof DateTimeInterface || $value instanceof Stringable) {
            return self::inspectDirectIdentifier((string) $value, $depth + 1, $nodes, $stringBytes);
        }

        if (! is_array($value) || ($depth >= self::MAX_DEPTH && $value !== [])) {
            return true;
        }

        foreach ($value as $key => $child) {
            if (self::inspectDirectIdentifier((string) $key, $depth + 1, $nodes, $stringBytes)
                || self::inspectDirectIdentifier($child, $depth + 1, $nodes, $stringBytes)) {
                return true;
            }
        }

        return false;
    }

    private static function containsDirectIdentifier(string $value): bool
    {
        // A regex engine error is an inspection failure and therefore denied.
        if (preg_match(self::DIRECT_IDENTIFIER_PATTERN, $value) !== 0) {
            return true;
        }

        return self::containsFinancialIdentifier($value);
    }

    private static function containsFinancialIdentifier(string $value): bool
    {
        $ibanMatchCount = preg_match_all(self::IBAN_CANDIDATE_PATTERN, $value, $ibanMatches);
        if ($ibanMatchCount === false) {
            return true;
        }

        foreach ($ibanMatches[0] as $rawCandidate) {
            $normalized = strtoupper(preg_replace('/[ -]+/', '', (string) $rawCandidate) ?? '');
            $maximumLength = min(34, strlen($normalized));
            for ($length = 15; $length <= $maximumLength; $length++) {
                if (self::isValidIban(substr($normalized, 0, $length))) {
                    return true;
                }
            }
        }

        $cardMatchCount = preg_match_all(self::PAYMENT_CARD_CANDIDATE_PATTERN, $value, $cardMatches);
        if ($cardMatchCount === false) {
            return true;
        }

        foreach ($cardMatches[0] as $rawCandidate) {
            $digits = preg_replace('/\D/', '', (string) $rawCandidate) ?? '';
            if (strlen($digits) >= 13
                && strlen($digits) <= 19
                && count(array_unique(str_split($digits))) > 1
                && self::passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private static function isValidIban(string $candidate): bool
    {
        if (strlen($candidate) < 15
            || strlen($candidate) > 34
            || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/D', $candidate) !== 1) {
            return false;
        }

        $rearranged = substr($candidate, 4).substr($candidate, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            if (ctype_digit($character)) {
                $remainder = (($remainder * 10) + (int) $character) % 97;

                continue;
            }

            $remainder = (($remainder * 100) + (ord($character) - ord('A') + 10)) % 97;
        }

        return $remainder === 1;
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
