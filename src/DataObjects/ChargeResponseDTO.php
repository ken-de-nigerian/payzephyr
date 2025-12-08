<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Constants\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

/**
 * ChargeResponseDTO - Payment Initialization Response
 *
 * This class holds the response after you initialize a payment.
 * It contains the payment reference, the URL to redirect the customer to,
 * and the current status (usually 'pending' until they pay).
 */
final readonly class ChargeResponseDTO
{
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public string $accessCode,
        public string $status,
        public array $metadata = [],
        public ?string $provider = null,
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
    public static function fromArray(array $data): ChargeResponseDTO
    {
        return new self(
            reference: $data['reference'] ?? '',
            authorizationUrl: $data['authorization_url'] ?? '',
            accessCode: $data['access_code'] ?? '',
            status: $data['status'] ?? 'pending',
            metadata: $data['metadata'] ?? [],
            provider: $data['provider'] ?? null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'authorization_url' => $this->authorizationUrl,
            'access_code' => $this->accessCode,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
        ];
    }

    /**
     * Check if the payment was successfully created and is ready for the customer to pay.
     */
    public function isSuccessful(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isSuccessful() ?? false;
    }

    /**
     * Check if the payment is still waiting for the customer to complete it.
     */
    public function isPending(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isPending() ?? false;
    }
}
