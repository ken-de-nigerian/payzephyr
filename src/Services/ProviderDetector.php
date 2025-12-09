<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

/**
 * Provider Detector Service
 *
 * This service is responsible for detecting payment providers from transaction
 * references. It follows the Strategy pattern to allow extensibility.
 *
 * Single Responsibility: Only handles provider detection logic.
 */
class ProviderDetector
{
    /**
     * Provider reference prefixes.
     * Can be extended via registerPrefix() method (OCP compliance).
     *
     * @var array<string, string>
     */
    protected array $prefixes = [
        'PAYSTACK' => 'paystack',
        'FLW' => 'flutterwave',
        'MON' => 'monnify',
        'STRIPE' => 'stripe',
        'PAYPAL' => 'paypal',
        'SQUARE' => 'square',
    ];

    /**
     * Detect provider from transaction reference.
     *
     * @param  string  $reference  Transaction reference
     * @return string|null Provider name or null if not detected
     */
    public function detectFromReference(string $reference): ?string
    {
        $upperReference = strtoupper($reference);

        foreach ($this->prefixes as $prefix => $provider) {
            if (str_starts_with($upperReference, $prefix.'_')) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Register a new provider prefix mapping.
     *
     * This allows extending provider detection without modifying this class (OCP compliance).
     *
     * @param  string  $prefix  Reference prefix (e.g., 'SQUARE')
     * @param  string  $provider  Provider name (e.g., 'square')
     * @return $this
     */
    public function registerPrefix(string $prefix, string $provider): self
    {
        $this->prefixes[strtoupper($prefix)] = $provider;

        return $this;
    }

    /**
     * Get all registered prefixes.
     *
     * @return array<string, string>
     */
    public function getPrefixes(): array
    {
        return $this->prefixes;
    }
}
