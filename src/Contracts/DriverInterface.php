<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

interface DriverInterface
{
    /**
     * @throws PaymentException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO;

    /**
     * @throws PaymentException
     */
    public function verify(string $reference): VerificationResponseDTO;

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    public function validateWebhook(array $headers, string $body): bool;

    public function healthCheck(): bool;

    public function getName(): string;

    /**
     * @return array<int, string>
     */
    public function getSupportedCurrencies(): array;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookReference(array $payload): ?string;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookStatus(array $payload): string;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookChannel(array $payload): ?string;

    public function resolveVerificationId(string $reference, string $providerId): string;
}
