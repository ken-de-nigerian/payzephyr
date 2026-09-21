<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;

final class StatusNormalizer implements StatusNormalizerInterface
{
    /** @var array<string, array<string, array<int, string>>> */
    protected array $providerMappings = [];

    /** @var array<string, array<int, string>> */
    protected array $defaultMappings = [
        'success' => [
            'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'COMPLETE', 'SUCCESSFUL', 'PAID', 'PAIDOUT', 'OVERPAID', 'CAPTURED',
        ],
        'failed' => [
            'FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED', 'DENIED', 'VOIDED', 'EXPIRED',
            'PAYMENT_FAILED',
        ],
        'pending' => [
            'PENDING', 'PROCESSING', 'PARTIALLY_PAID', 'CREATED', 'SAVED', 'APPROVED',
            'PAYER_ACTION_REQUIRED', 'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CONFIRMATION',
        ],
    ];

    public function normalize(string $status, ?string $provider = null): string
    {
        $status = strtoupper(trim($status));

        if ($provider && isset($this->providerMappings[$provider])) {
            $mapping = $this->providerMappings[$provider];
            foreach ($mapping as $normalizedStatus => $providerStatuses) {
                if (in_array($status, $providerStatuses, true)) {
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
     * @param  array<string, array<int, string>>  $mappings
     */
    public function registerProviderMappings(string $provider, array $mappings): self
    {
        $this->providerMappings[$provider] = $mappings;

        return $this;
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public function getProviderMappings(): array
    {
        return $this->providerMappings;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getDefaultMappings(): array
    {
        return $this->defaultMappings;
    }

    /**
     * Every raw status that normalize() would collapse into $normalized,
     * including the normalized value itself.
     *
     * This exists so that a query scope and an in-memory predicate cannot
     * disagree about the same row. `PaymentTransaction::successful()` used to
     * match a hand-maintained list that had drifted from this vocabulary, so a
     * row stored as `captured`, `overpaid`, `paidout` or `complete` answered
     * true to isSuccessful() while the scope failed to find it - which
     * silently under-counts a reconciliation query. Deriving the list here
     * means adding a provider vocabulary updates both at once.
     *
     * Provider-specific mappings are deliberately excluded. They are not
     * merely redundant here, they are contradictory: `APPROVED` means success
     * for Square and pending for PayPal, so a union would list one string
     * under two opposite meanings. A provider's own vocabulary is applied when
     * the provider is known - at write time by the driver, and per row by
     * PaymentTransaction, which reads its own `provider` column.
     *
     * @return array<int, string>
     */
    public function statusesNormalizingTo(string $normalized): array
    {
        $statuses = $this->defaultMappings[$normalized] ?? [];
        $statuses[] = $normalized;

        return array_values(array_unique(array_map(strtolower(...), $statuses)));
    }

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
