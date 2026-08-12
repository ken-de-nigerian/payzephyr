<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\RefundStatus;

final readonly class RefundResponseDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $refundReference,
        public string $transactionReference,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $reason = null,
        public array $metadata = [],
        public ?string $provider = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            refundReference: $data['refund_reference'] ?? '',
            transactionReference: $data['transaction_reference'] ?? '',
            status: $data['status'] ?? 'unknown',
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            reason: $data['reason'] ?? null,
            metadata: $data['metadata'] ?? [],
            provider: $data['provider'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'refund_reference' => $this->refundReference,
            'transaction_reference' => $this->transactionReference,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
        ];
    }

    /**
     * Get refund status as enum. Falls back to FAILED for an unrecognized
     * provider status string, since an unrecognized status is not safe to
     * treat as a successful refund.
     */
    public function getStatus(): RefundStatus
    {
        return RefundStatus::tryFromString($this->status) ?? RefundStatus::FAILED;
    }

    public function isCompleted(): bool
    {
        return $this->getStatus() === RefundStatus::COMPLETED;
    }

    public function isPending(): bool
    {
        $status = $this->getStatus();

        return $status === RefundStatus::PENDING || $status === RefundStatus::PROCESSING;
    }

    public function isFailed(): bool
    {
        $status = $this->getStatus();

        return $status === RefundStatus::FAILED || $status === RefundStatus::CANCELLED;
    }
}
