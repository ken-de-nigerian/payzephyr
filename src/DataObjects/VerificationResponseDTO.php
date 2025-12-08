<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Constants\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

/**
 * Class VerificationResponseDTO
 *
 * Data transfer object for payment verification responses
 */
final readonly class VerificationResponseDTO
{
    public function __construct(
        public string $reference,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $paidAt = null,
        public array $metadata = [],
        public ?string $provider = null,
        public ?string $channel = null,
        public ?string $cardType = null,
        public ?string $bank = null,
        public ?array $customer = null,
    ) {}

    /**
     * Get normalized status using StatusNormalizer.
     */
    protected function getNormalizedStatus(): string
    {
        // Try to use container if available, otherwise use static method
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);

                return $normalizer->normalize($this->status, $this->provider);
            }
        } catch (Throwable) {
            // Fall back to static normalization if container unavailable
        }

        return StatusNormalizer::normalizeStatic($this->status);
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): VerificationResponseDTO
    {
        return new self(
            reference: $data['reference'] ?? '',
            status: $data['status'] ?? 'unknown',
            amount: (float) ($data['amount'] ?? 0),
            currency: strtoupper($data['currency'] ?? ''),
            paidAt: $data['paid_at'] ?? null,
            metadata: $data['metadata'] ?? [],
            provider: $data['provider'] ?? null,
            channel: $data['channel'] ?? null,
            cardType: $data['card_type'] ?? null,
            bank: $data['bank'] ?? null,
            customer: $data['customer'] ?? null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paidAt,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
            'channel' => $this->channel,
            'card_type' => $this->cardType,
            'bank' => $this->bank,
            'customer' => $this->customer,
        ];
    }

    /**
     * Check if payment was successful
     */
    public function isSuccessful(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isSuccessful() ?? false;
    }

    /**
     * Check if payment failed
     */
    public function isFailed(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isFailed() ?? false;
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isPending() ?? false;
    }
}
