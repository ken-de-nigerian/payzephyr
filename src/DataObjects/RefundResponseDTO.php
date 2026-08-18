<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\RefundStatus;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class RefundResponseDTO
{
    use NormalizesMetadata;

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
     * Get refund status as enum.
     *
     * An unrecognized provider status falls back to PENDING, not FAILED.
     * "Unrecognized" means the outcome is unknown, and the two questions that
     * fallback answers pull in opposite directions:
     *
     *  - Did the refund succeed? Unknown must not count as yes.
     *  - Did money leave the account? Unknown must be assumed yes, because
     *    RefundStatus::countsTowardRefundedAmount() drives the over-refund
     *    guard - and FAILED is excluded from it. Treating an unknown status as
     *    FAILED therefore silently freed up the whole refundable balance
     *    again, allowing a second refund to over-spend the captured amount.
     *
     * PENDING answers both correctly: it is not success, it counts toward the
     * refunded total, and it is non-terminal, so a later webhook can still
     * resolve it to the real outcome (see RefundStatus::isTerminal()).
     */
    public function getStatus(): RefundStatus
    {
        return RefundStatus::tryFromString($this->status) ?? RefundStatus::PENDING;
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
