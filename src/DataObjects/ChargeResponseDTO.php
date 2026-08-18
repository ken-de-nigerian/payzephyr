<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;
use Throwable;

final readonly class ChargeResponseDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public string $accessCode,
        public string $status,
        public array $metadata = [],
        public ?string $provider = null,
    ) {}

    protected function getNormalizedStatus(): string
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);

                return $normalizer->normalize($this->status, $this->provider);
            }
        } catch (Throwable) {
        }

        return StatusNormalizer::normalizeStatic($this->status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ChargeResponseDTO
    {
        return new self(
            reference: $data['reference'] ?? '',
            authorizationUrl: $data['authorization_url'] ?? '',
            accessCode: $data['access_code'] ?? '',
            status: $data['status'] ?? 'pending',
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
            'reference' => $this->reference,
            'authorization_url' => $this->authorizationUrl,
            'access_code' => $this->accessCode,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
        ];
    }

    public function isSuccessful(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isSuccessful() ?? false;
    }

    public function isPending(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isPending() ?? false;
    }
}
