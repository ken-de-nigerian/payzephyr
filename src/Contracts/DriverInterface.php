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

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string;

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     * The normalizer will take care of converting this to standard format.
     */
    public function extractWebhookStatus(array $payload): string;

    /**
     * Get the payment channel (e.g., 'card', 'bank_transfer') from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string;

    /**
     * Resolve the actual ID needed for verification (which may differ from the
     * internal reference or the provider's Access Code).
     *
     * @param  string  $reference  The package's unique reference (e.g., PAYSTACK_...)
     * @param  string  $providerId  The provider's internal ID saved during charge (e.g., Paystack access_code)
     */
    public function resolveVerificationId(string $reference, string $providerId): string;
}
