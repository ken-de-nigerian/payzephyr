<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use InvalidArgumentException;

/**
 * Single source of truth for PayZephyr's optional, database-backed
 * features - what the installer can select, what migration publish tag
 * each one maps to, and what env var tracks whether it's been enabled.
 *
 * Deliberately excludes payment logging and webhook idempotency: both are
 * depended on unconditionally by PaymentManager/ProcessWebhook with their
 * default configuration (payments.logging.enabled and webhook signature
 * verification are both on by default for every provider), so their
 * tables (payment_transactions, webhook_events) are core installation,
 * not optional features - see docs/installation.md#core-vs-optional.
 *
 * Neither optional feature currently depends on the other, or on anything
 * beyond core - $dependencies exists so a future feature can declare one
 * without changing the registry's shape, not because one exists today.
 */
final class Features
{
    /**
     * @return array<string, array{label: string, description: string, migrationTag: string, envVar: string, dependencies: array<int, string>}>
     */
    public static function optional(): array
    {
        return [
            'subscriptions' => [
                'label' => 'Subscriptions',
                'description' => 'Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie',
                'migrationTag' => 'payzephyr-migrations-subscriptions',
                'envVar' => 'PAYZEPHYR_FEATURE_SUBSCRIPTIONS',
                'dependencies' => [],
            ],
            'refunds' => [
                'label' => 'Refunds',
                'description' => 'Full and partial refunds across all 8 providers',
                'migrationTag' => 'payzephyr-migrations-refunds',
                'envVar' => 'PAYZEPHYR_FEATURE_REFUNDS',
                'dependencies' => [],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function optionalKeys(): array
    {
        return array_keys(self::optional());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::optional());
    }

    /**
     * @return array{label: string, description: string, migrationTag: string, envVar: string, dependencies: array<int, string>}
     */
    public static function get(string $key): array
    {
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Unknown PayZephyr feature [$key]. Known features: ".implode(', ', self::optionalKeys()));
        }

        return self::optional()[$key];
    }

    /**
     * Parse a comma-separated --features= value into a validated, deduped
     * list of known feature keys. Case-insensitive and whitespace-tolerant
     * on input; throws on anything that doesn't resolve to a known
     * feature, naming the exact bad token rather than failing silently.
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    public static function parseList(string $value): array
    {
        $tokens = array_filter(array_map('trim', explode(',', $value)), fn (string $t) => $t !== '');

        if ($tokens === []) {
            throw new InvalidArgumentException('--features was given but contained no feature names.');
        }

        $resolved = [];
        foreach ($tokens as $token) {
            $key = strtolower($token);

            if (! self::exists($key)) {
                throw new InvalidArgumentException(
                    "Unknown feature [$token]. Known features: ".implode(', ', self::optionalKeys())
                );
            }

            $resolved[$key] = true;
        }

        return array_keys($resolved);
    }

    /**
     * Resolve $selected against each feature's declared dependencies,
     * returning the full set (selected + transitively required). Detects
     * cycles defensively even though none exist in the registry today.
     *
     * @param  array<int, string>  $selected
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    public static function resolveDependencies(array $selected): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$resolved, &$visiting): void {
            if (isset($resolved[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new InvalidArgumentException("Circular dependency detected involving feature [$key].");
            }

            $visiting[$key] = true;

            foreach (self::get($key)['dependencies'] as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$key]);
            $resolved[$key] = true;
        };

        foreach ($selected as $key) {
            $visit($key);
        }

        return array_keys($resolved);
    }
}
