<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

/**
 * Interface DriverInterface
 *
 * All payment provider drivers must implement this interface
 */
interface DriverInterface
{
    /**
     * Initialize a charge/payment
     *
     * @throws PaymentException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO;

    /**
     * Verify a payment transaction
     *
     * @param  string  $reference  Transaction reference
     *
     * @throws PaymentException
     */
    public function verify(string $reference): VerificationResponseDTO;

    /**
     * Validate webhook signature
     *
     * @param  array  $headers  Request headers
     * @param  string  $body  Raw request body
     */
    public function validateWebhook(array $headers, string $body): bool;

    /**
     * Check if the provider is available and healthy
     */
    public function healthCheck(): bool;

    /**
     * Get the provider name
     */
    public function getName(): string;

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array;
}
