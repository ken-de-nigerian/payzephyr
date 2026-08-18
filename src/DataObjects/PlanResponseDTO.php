<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use JsonSerializable;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class PlanResponseDTO implements JsonSerializable
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $planCode,
        public string $name,
        public float $amount,
        public string $interval,
        public string $currency,
        public ?string $description = null,
        public ?int $invoiceLimit = null,
        public array $metadata = [],
        public ?string $provider = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            planCode: $data['plan_code'] ?? $data['id'] ?? '',
            name: $data['name'] ?? '',
            amount: (float) ($data['amount'] ?? 0) / 100,
            interval: $data['interval'] ?? 'monthly',
            currency: $data['currency'] ?? 'NGN',
            description: $data['description'] ?? null,
            invoiceLimit: $data['invoice_limit'] ?? null,
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
            provider: $data['provider'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_code' => $this->planCode,
            'name' => $this->name,
            'amount' => $this->amount,
            'interval' => $this->interval,
            'currency' => $this->currency,
            'description' => $this->description,
            'invoice_limit' => $this->invoiceLimit,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
        ];
    }

    public function isActive(): bool
    {
        $status = $this->metadata['status'] ?? 'active';

        return $status === 'active';
    }

    /**
     * Get amount in major units (already in major units, but provided for consistency)
     */
    public function getAmountInMajorUnits(): float
    {
        return $this->amount;
    }

    /**
     * Implement JsonSerializable for automatic Laravel response serialization
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
