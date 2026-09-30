<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

final class MetadataSanitizer
{
    public function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth > PaymentConstants::METADATA_MAX_DEPTH) {
            return null;
        }

        if (is_array($data)) {
            if (count($data) > PaymentConstants::METADATA_MAX_ARRAY_SIZE) {
                return [];
            }

            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitizedKey = $this->sanitizeKey($key);
                if ($sanitizedKey === null) {
                    continue;
                }

                $sanitized[$sanitizedKey] = $this->sanitize($value, $depth + 1);
            }

            return $sanitized;
        }

        if (is_string($data)) {
            return $this->sanitizeString($data);
        }

        if (is_int($data) || is_float($data)) {
            return $data;
        }

        if (is_bool($data)) {
            return $data;
        }

        return null;

    }

    private function sanitizeKey(string $key): ?string
    {
        if (strlen($key) > PaymentConstants::MAX_KEY_LENGTH) {
            return null;
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
            return null;
        }

        return $key;
    }

    private function sanitizeString(string $value): string
    {
        if (strlen($value) > PaymentConstants::METADATA_MAX_STRING_LENGTH) {
            $value = substr($value, 0, PaymentConstants::METADATA_MAX_STRING_LENGTH);
        }

        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

        $dangerousPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/data:text\/html/i',
            '/vbscript:/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            // Null means PCRE gave up on the value. Nothing about it can be
            // vouched for then, so it is dropped rather than stored unsanitized.
            $value = preg_replace($pattern, '', $value) ?? '';
        }

        return $value;
    }
}
