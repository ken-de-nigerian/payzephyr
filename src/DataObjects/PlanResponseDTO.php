<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use JsonSerializable;
use KenDeNigerian\PayZephyr\Support\Payload;
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
        public ?float $amount,
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
        $input = new Payload($data);
        $amount = $input->float('amount');

        return new self(
            planCode: $input->string('plan_code') ?? $input->string('id') ?? '',
            name: $input->string('name') ?? '',
            amount: $amount === null ? null : $amount / 100,
            interval: $input->string('interval') ?? 'monthly',
            currency: $input->string('currency') ?? 'NGN',
            description: $input->string('description'),
            invoiceLimit: $input->int('invoice_limit'),
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
            provider: $input->string('provider'),
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
     * Get amount in major units (already in major units, but provided for consistency).
     *
     * Null for a plan with no fixed price - see the constructor.
     */
    public function getAmountInMajorUnits(): ?float
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
