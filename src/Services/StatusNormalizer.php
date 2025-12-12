<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;

/**
 * Status normalizer service.
 */
final class StatusNormalizer implements StatusNormalizerInterface
{
    /** @var array<string, array<string, string>> */
    protected array $providerMappings = [];

    /** @var array<string, array<string>> */
    protected array $defaultMappings = [
        'success' => [
            'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'SUCCESSFUL', 'PAID', 'OVERPAID', 'CAPTURED',
        ],
        'failed' => [
            'FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED', 'DENIED', 'VOIDED', 'EXPIRED',
        ],
        'pending' => [
            'PENDING', 'PROCESSING', 'PARTIALLY_PAID', 'CREATED', 'SAVED', 'APPROVED',
            'PAYER_ACTION_REQUIRED', 'REQUIRES_ACTION', 'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CONFIRMATION',
        ],
    ];

    /**
     * Normalize status.
     */
    public function normalize(string $status, ?string $provider = null): string
    {
        $status = strtoupper(trim($status));

        if ($provider && isset($this->providerMappings[$provider])) {
            $mapping = $this->providerMappings[$provider];
            foreach ($mapping as $normalizedStatus => $providerStatuses) {
                if (in_array($status, (array) $providerStatuses, true)) {
                    return $normalizedStatus;
                }
            }
        }

        foreach ($this->defaultMappings as $normalizedStatus => $providerStatuses) {
            if (in_array($status, $providerStatuses, true)) {
                return $normalizedStatus;
            }
        }

        return strtolower($status);
    }

    /**
     * Register provider mappings.
     *
     * @param  array<string, array<string>>  $mappings
     */
    public function registerProviderMappings(string $provider, array $mappings): self
    {
        $this->providerMappings[$provider] = $mappings;

        return $this;
    }

    /**
     * Get provider mappings.
     *
     * @return array<string, array<string, string>>
     */
    public function getProviderMappings(): array
    {
        return $this->providerMappings;
    }

    /**
     * Get default mappings.
     *
     * @return array<string, array<string>>
     */
    public function getDefaultMappings(): array
    {
        return $this->defaultMappings;
    }

    /**
     * Normalize status statically.
     */
    public static function normalizeStatic(string $status): string
    {
        $status = strtoupper(trim($status));

        $defaultMappings = [
            'success' => [
                'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'SUCCESSFUL', 'PAID', 'OVERPAID', 'CAPTURED',
            ],
            'failed' => [
                'FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED', 'DENIED', 'VOIDED', 'EXPIRED',
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
