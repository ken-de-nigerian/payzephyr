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
     * memory-exhaustion / stack-overflow DoS vector. Mirrors the same cap
     * already applied to persisted metadata in
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
