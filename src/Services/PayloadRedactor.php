<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

/**
 * Strips sensitive values out of a trace payload before it is stored.
 *
 * Overlaps in intent with Traits\HasLogSanitization, which does the same job
 * for log context with a different field list. Converging them changes what
 * every driver redacts from its logs, so it is deliberately left for its own
 * change rather than smuggled in with the trace feature.
 */
final readonly class PayloadRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * Redact sensitive fields from a payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload, ?int $maxDepth = null): array
    {
        $config = app('payments.config') ?? config('payments', []);

        /** @var array<int, string> $fields */
        $fields = data_get($config, 'trace.redact_fields', []);

        $maxDepth ??= (int) (data_get($config, 'trace.redaction_max_depth') ?? PaymentConstants::METADATA_MAX_DEPTH);

        return $this->redactRecursive($payload, $fields, $maxDepth, 0);
    }

    /**
     * Depth-limited for the same reason MetadataSanitizer is: trace payloads
     * are provider response bodies and webhook payloads, which is to say
     * attacker-influenced input, and unbounded recursion over deeply nested
     * JSON is a memory-exhaustion vector.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function redactRecursive(array $data, array $fields, int $maxDepth, int $currentDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return ['_truncated' => self::REDACTED.' max depth reached'];
        }

        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->shouldRedact($key, $fields)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redactRecursive($value, $fields, $maxDepth, $currentDepth + 1)
                : $value;
        }

        return $redacted;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function shouldRedact(string $key, array $fields): bool
    {
        $keyLower = strtolower($key);

        foreach ($fields as $field) {
            if (str_contains($keyLower, strtolower($field))) {
                return true;
            }
        }

        return false;
    }
}
