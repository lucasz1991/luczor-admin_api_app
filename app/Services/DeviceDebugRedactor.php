<?php

namespace App\Services;

class DeviceDebugRedactor
{
    public function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            return '[DEPTH_LIMIT]';
        }
        if (is_array($value)) {
            // Browser/network diagnostics can represent headers as name/value rows.
            $entryName = $value['name'] ?? $value['key'] ?? null;
            $sensitiveEntry = is_string($entryName) && $this->sensitiveKey($entryName);
            foreach ($value as $key => $item) {
                $value[$key] = $this->sensitiveKey((string) $key) || ($sensitiveEntry && $key === 'value')
                    ? '[REDACTED]' : $this->clean($item, $depth + 1);
            }

            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match('/^\s*[\[{]/', $value)) {
            $parsed = json_decode($value, true);
            if (is_array($parsed)) {
                return json_encode($this->clean($parsed, $depth + 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return preg_replace([
            '/-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----/',
            '/<(?:think|analysis)>[\s\S]*?(?:<\/(?:think|analysis)>|$)/i',
            '/^([\t ]*(?:proxy[-_]authorization|authorization|set[-_]cookie|cookie)[\t ]*:[\t ]*)[^\r\n]*(?:\r?\n[\t ]+[^\r\n]*)*/im',
            '/Bearer\s+[^\s"\',;]+/i',
            '/\b(?:sk|sk-or-v1)-[a-z0-9_-]+/i',
            '/(\b(?:[a-z_]*password|[a-z_]*secret|[a-z_]*token|api[_-]?key|authorization|cookie)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;}]+)/i',
            '/data:[^;]+;base64,[a-z0-9+\/=]+/i',
            '/(https?:\/\/)[^\/\s:@]+:[^\/\s@]+@/i',
        ], ['[REDACTED]', '[PRIVATE_REASONING_OMITTED]', '$1[REDACTED]', 'Bearer [REDACTED]', '[REDACTED]', '$1[REDACTED]', '[BINARY_OMITTED]', '$1[REDACTED]@'], $value);
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/i', '', $key);

        return (bool) preg_match('/^(?:.*password|.*secret|.*apikey|authorization|proxyauthorization|cookie|setcookie|token|devicekey|accesstoken|refreshtoken|idtoken|sessiontoken|sessionid|reasoning(?:content)?|analysis|imagebase64|base64)$/i', $normalized);
    }
}
