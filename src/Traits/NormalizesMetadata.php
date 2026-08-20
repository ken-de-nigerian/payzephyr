<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use ArrayObject;
use JsonSerializable;

/**
 * Turns whatever a provider sent as "metadata" into a real array.
 *
 * Providers are inconsistent here in ways that are easy to miss until
 * production. Paystack returns an empty string when a transaction has no
 * metadata, and a JSON-encoded string when it does. Others return null, or
 * omit the key entirely. Eloquent hands back an ArrayObject on a cast column.
 *
 * A plain `$data['metadata'] ?? []` only catches null and missing keys, so an
 * empty string sails straight through into a typed `array` parameter and
 * fatals with a TypeError before the constructor body ever runs. That is a
 * hard crash on a verify call, not a recoverable exception.
 */
trait NormalizesMetadata
{
    /**
     * @return array<string, mixed>
     */
    protected static function normalizeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if ($value instanceof ArrayObject) {
            /** @var array<string, mixed> $copy */
            $copy = $value->getArrayCopy();

            return $copy;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $converted = $value->toArray();

            /** @var array<string, mixed> $converted */
            return is_array($converted) ? $converted : [];
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            /** @var array<string, mixed> $serialized */
            return is_array($serialized) ? $serialized : [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            /** @var array<string, mixed> $decoded */
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
