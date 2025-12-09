<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

/**
 * Status Normalizer Service
 *
 * This service is responsible for converting provider-specific payment statuses
 * to standardized internal status values. It follows the Strategy pattern to
 * allow extensibility without modifying existing code (OCP compliance).
 *
 * Single Responsibility: Only handles status normalization logic.
 */
final class StatusNormalizer
{
    /**
     * Provider-specific status mappings.
     * Drivers can extend this by overriding getProviderMappings().
     *
     * @var array<string, array<string, string>>
     */
    protected array $providerMappings = [];

    /**
     * Default status mappings that apply to all providers.
     *
     * @var array<string, array<string>>
     */
    protected array $defaultMappings = [
        'success' => [
            'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'SUCCESSFUL', 'PAID', 'OVERPAID', 'CAPTURED',
        ],
        'failed' => [
            'FAILED', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED', 'DENIED', 'VOIDED', 'EXPIRED',
        ],
        'pending' => [
            'PENDING', 'PROCESSING', 'PARTIALLY_PAID', 'CREATED', 'SAVED', 'APPROVED',
            'PAYER_ACTION_REQUIRED', 'REQUIRES_ACTION', 'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CONFIRMATION',
        ],
    ];

    /**
     * Normalize a provider-specific status to internal standard status.
     *
     * This method first checks for provider-specific mappings, then falls back
     * to default mappings. This allows drivers to extend behavior without
     * modifying this class (OCP compliance).
     *
     * @param  string  $status  The provider-specific status value
     * @param  string|null  $provider  Optional provider name for provider-specific mappings
     * @return string Normalized status value
     */
    public function normalize(string $status, ?string $provider = null): string
    {
        $status = strtoupper(trim($status));

        // Check provider-specific mappings first
        if ($provider && isset($this->providerMappings[$provider])) {
            $mapping = $this->providerMappings[$provider];
            foreach ($mapping as $normalizedStatus => $providerStatuses) {
                if (in_array($status, (array) $providerStatuses, true)) {
                    return $normalizedStatus;
                }
            }
        }

        // Fall back to default mappings
        foreach ($this->defaultMappings as $normalizedStatus => $providerStatuses) {
            if (in_array($status, $providerStatuses, true)) {
                return $normalizedStatus;
            }
        }

        // Return lowercase version if no mapping found
        return strtolower($status);
    }

    /**
     * Register-provider-specific status mappings.
     *
     * This allows drivers to extend the normalization logic without modifying
     * the core class (OCP compliance).
     *
     * @param  string  $provider  Provider name (e.g., 'paystack', 'stripe')
     * @param  array<string, array<string>>  $mappings  Status mappings ['normalized' => ['PROVIDER_STATUS1', 'PROVIDER_STATUS2']]
     * @return $this
     */
    public function registerProviderMappings(string $provider, array $mappings): self
    {
        $this->providerMappings[$provider] = $mappings;

        return $this;
    }

    /**
     * Get all registered provider mappings.
     *
     * @return array<string, array<string, array<string>>>
     */
    public function getProviderMappings(): array
    {
        return $this->providerMappings;
    }

    /**
     * Get default status mappings.
     *
     * @return array<string, array<string>>
     */
    public function getDefaultMappings(): array
    {
        return $this->defaultMappings;
    }

    /**
     * Static helper to normalize status using default mappings only.
     * Useful for DTOs that can't access the container.
     *
     * @param  string  $status  The status to normalize
     * @return string Normalized status
     */
    public static function normalizeStatic(string $status): string
    {
        $status = strtoupper(trim($status));

        $defaultMappings = [
            'success' => [
                'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'SUCCESSFUL', 'PAID', 'OVERPAID', 'CAPTURED',
            ],
            'failed' => [
                'FAILED', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED', 'DENIED', 'VOIDED', 'EXPIRED',
            ],
            'pending' => [
                'PENDING', 'PROCESSING', 'PARTIALLY_PAID', 'CREATED', 'SAVED', 'APPROVED',
                'PAYER_ACTION_REQUIRED', 'REQUIRES_ACTION', 'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CONFIRMATION',
            ],
        ];

        foreach ($defaultMappings as $normalizedStatus => $providerStatuses) {
            if (in_array($status, $providerStatuses, true)) {
                return $normalizedStatus;
            }
        }

        return strtolower($status);
    }
}
