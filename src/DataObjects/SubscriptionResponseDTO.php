<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class SubscriptionResponseDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $subscriptionCode,
        public string $status,
        public string $customer,
        public string $plan,
        public ?float $amount,
        public string $currency,
        public ?string $nextPaymentDate = null,
        public ?string $emailToken = null,
        public array $metadata = [],
        public ?string $provider = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $input = new Payload($data);

        return new self(
            subscriptionCode: $input->string('subscription_code') ?? '',
            status: $input->string('status') ?? 'unknown',
            customer: $input->string('customer') ?? '',
            plan: $input->string('plan') ?? '',
            amount: $input->float('amount'),
            currency: $input->string('currency') ?? 'NGN',
            nextPaymentDate: $input->string('next_payment_date'),
            emailToken: $input->string('email_token'),
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
            'subscription_code' => $this->subscriptionCode,
            'status' => $this->status,
            'customer' => $this->customer,
            'plan' => $this->plan,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'next_payment_date' => $this->nextPaymentDate,
            'email_token' => $this->emailToken,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
        ];
    }

    /**
     * Get subscription status as enum.
     */
    public function getStatus(): SubscriptionStatus
    {
        return SubscriptionStatus::tryFromString($this->status) ?? SubscriptionStatus::EXPIRED;
    }

    public function isActive(): bool
    {
        $status = $this->getStatus();

        return $status->isBilling();
    }

    public function isCancelled(): bool
    {
        return $this->getStatus() === SubscriptionStatus::CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->getStatus() === SubscriptionStatus::COMPLETED;
    }

    /**
     * Check if subscription can be canceled.
     */
    public function canBeCancelled(): bool
    {
        return $this->getStatus()->canBeCancelled();
    }

    /**
     * Check if subscription can be resumed.
     */
    public function canBeResumed(): bool
    {
        return $this->getStatus()->canBeResumed();
    }
}
