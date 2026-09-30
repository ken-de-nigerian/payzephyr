<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Support\Payload;
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
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $input = new Payload($data);

        return new self(
            customer: $input->string('customer') ?? '',
            plan: $input->string('plan') ?? '',
            quantity: $input->int('quantity') ?? 1,
            startDate: $input->string('start_date'),
            trialDays: $input->int('trial_days'),
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
            authorization: $input->string('authorization'),
            idempotencyKey: $input->string('idempotency_key') ?? self::generateIdempotencyKey(),
            callbackUrl: $input->string('callback_url'),
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
        ], fn ($value): bool => $value !== null);
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
