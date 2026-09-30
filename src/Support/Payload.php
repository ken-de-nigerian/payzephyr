<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Support;

/**
 * A typed reader over data PayZephyr did not write: a provider's decoded
 * response, a webhook body, a config array.
 *
 * Everything in such an array is `mixed`. Reading `$data['a']['b']` and handing
 * it to something that wants a string works until the day a provider - or
 * someone forging a webhook - sends a number, an array, or nothing there, and
 * then it is a TypeError in the middle of a payment rather than a missing
 * value the caller already handles. Each reader here answers with the type it
 * promises or with null, never with whatever happened to be at that path.
 *
 * Paths are given segment by segment (`string('data', 'customer', 'email')`)
 * rather than dotted, because provider keys contain dots: Flutterwave sends a
 * top-level `event.type`.
 */
final readonly class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * Wrap anything; a value that is not an array reads as empty.
     */
    public static function of(mixed $data): self
    {
        return new self(is_array($data) ? $data : []);
    }

    /**
     * The raw value at a path, or null when any segment is missing.
     */
    public function get(string|int ...$path): mixed
    {
        $current = $this->data;

        foreach ($path as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Whether a path holds a value other than null.
     */
    public function has(string|int ...$path): bool
    {
        return $this->get(...$path) !== null;
    }

    /**
     * A string, or a number rendered as one - providers send ids and
     * references as either. Anything else, including a boolean, is null.
     */
    public function string(string|int ...$path): ?string
    {
        $value = $this->get(...$path);

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * An integer, or a numeric value that is one ("100", 100.0).
     */
    public function int(string|int ...$path): ?int
    {
        $value = $this->get(...$path);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) && (float) $value === floor((float) $value) ? (int) $value : null;
    }

    /**
     * Any numeric value, as a float.
     */
    public function float(string|int ...$path): ?float
    {
        $value = $this->get(...$path);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A boolean only when the value is one; null otherwise, so "absent" is not
     * mistaken for false.
     */
    public function bool(string|int ...$path): ?bool
    {
        $value = $this->get(...$path);

        return is_bool($value) ? $value : null;
    }

    /**
     * An on/off setting, the way one arrives from an env file.
     *
     * `env()` turns "true" and "false" into booleans, but "0", "1", "off" and
     * "yes" reach a config array as strings, and an integer 0 or 1 is common in
     * a hand-written one. All of those are read as the switch they plainly
     * mean. Anything else - absent, null, an array, a word that is not a
     * switch - answers $default, since guessing at it would turn a feature on
     * or off that nobody asked for.
     */
    public function flag(bool $default, string|int ...$path): bool
    {
        $value = $this->get(...$path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    /**
     * The array at a path, or an empty one.
     *
     * @return array<array-key, mixed>
     */
    public function array(string|int ...$path): array
    {
        $value = $this->get(...$path);

        return is_array($value) ? $value : [];
    }

    /**
     * A reader over the array at a path, empty when there is none - so a chain
     * of reads into a missing branch answers null instead of failing.
     */
    public function at(string|int ...$path): self
    {
        return new self($this->array(...$path));
    }

    /**
     * The whole of what this reader wraps.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
