<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;
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
        public float $amount,
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
        return new self(
            subscriptionCode: $data['subscription_code'] ?? '',
            status: $data['status'] ?? 'unknown',
            customer: $data['customer'] ?? '',
            plan: $data['plan'] ?? '',
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            nextPaymentDate: $data['next_payment_date'] ?? null,
            emailToken: $data['email_token'] ?? null,
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
