<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

/**
 * Trait providing log sanitization functionality to prevent sensitive data leakage.
 */
trait HasLogSanitization
{
    /**
     * Sensitive keys to redact from logs.
     *
     * @var array<string>
     */
    protected array $sensitiveKeys = [
        'password',
        'secret',
        'token',
        'api_key',
        'access_token',
        'refresh_token',
        'authorization',
        'signature',
        'card_number',
        'cvv',
        'pin',
        'ssn',
        'account_number',
        'routing_number',
    ];

    /**
     * Recursively sanitize log context to remove sensitive information.
     *
     * Depth-limited to PaymentConstants::LOG_SANITIZATION_MAX_DEPTH: log context
     * routinely includes attacker-influenced data (webhook payloads, provider
     * error bodies), and unbounded recursion over deeply nested input is a
     * memory-exhaustion / stack-overflow DoS vector. See ADR-0002. Mirrors the
     * same cap already applied to persisted metadata in
     * Services\MetadataSanitizer::sanitize().
     *
     * @param  mixed  $data  Data to sanitize
     * @return mixed Sanitized data
     */
    protected function sanitizeLogContext(mixed $data, int $depth = 0): mixed
    {
        if ($depth > PaymentConstants::LOG_SANITIZATION_MAX_DEPTH) {
            return '[MAX_DEPTH_EXCEEDED]';
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = $this->sanitizeLogContext($value, $depth + 1);
                }
            }

            return $sanitized;
        }

        if (is_object($data)) {
            $array = method_exists($data, 'toArray')
                ? $data->toArray()
                : (array) $data;

            return $this->sanitizeLogContext($array, $depth + 1);
        }

        // Deliberately unconditional on string length: the pattern below is
        // anchored (^) with a simple literal alternation, so it costs the
        // same whether $data is 5 characters or 5000 - there's no
        // performance reason to skip it, and a prior length-gate here
        // (checking only strings longer than
        // PaymentConstants::MAX_STRING_LENGTH_FOR_TOKEN_CHECK) meant short
        // real tokens - e.g. a sandbox/test key or "Bearer test123" - could
        // slip through un-redacted.
        if (is_string($data) && preg_match('/^(sk_|pk_|whsec_|Bearer\s+)/i', $data)) {
            return '[REDACTED_TOKEN]';
        }

        return $data;
    }

    /**
     * Check if a key is considered sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if (str_contains($key, strtolower($sensitiveKey))) {
                return true;
            }
        }

        return false;
    }
}
