<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

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
        return in_array(strtolower($this->status), ['success', 'succeeded', 'completed', 'successful']);
    }

    /**
     * Check if the payment is still waiting for the customer to complete it.
     */
    public function isPending(): bool
    {
        return strtolower($this->status) === 'pending';
    }
}
