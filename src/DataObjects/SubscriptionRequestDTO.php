<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class SubscriptionRequestDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $customer,
        public string $plan,
        public ?int $quantity = 1,
        public ?string $startDate = null,
        public ?int $trialDays = null,
        public array $metadata = [],
        public ?string $authorization = null,
        public ?string $idempotencyKey = null,
        public ?string $callbackUrl = null,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (empty($this->customer)) {
            throw new InvalidArgumentException('Customer is required');
        }

        if (empty($this->plan)) {
            throw new InvalidArgumentException('Plan is required');
        }

        if ($this->quantity !== null && $this->quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1');
        }

        if ($this->trialDays !== null && $this->trialDays < 0) {
            throw new InvalidArgumentException('Trial days cannot be negative');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customer: $data['customer'] ?? '',
            plan: $data['plan'] ?? '',
            quantity: $data['quantity'] ?? 1,
            startDate: $data['start_date'] ?? null,
            trialDays: $data['trial_days'] ?? null,
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
            authorization: $data['authorization'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? self::generateIdempotencyKey(),
            callbackUrl: $data['callback_url'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'customer' => $this->customer,
            'plan' => $this->plan,
            'quantity' => $this->quantity,
            'start_date' => $this->startDate,
            'trial_days' => $this->trialDays,
            'metadata' => $this->metadata,
            'authorization' => $this->authorization,
            'callback_url' => $this->callbackUrl,
        ], fn ($value) => $value !== null);
    }

    /**
     * Generate a new idempotency key (UUID).
     *
     * @return string A UUID v4 string
     */
    public static function generateIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }
}
