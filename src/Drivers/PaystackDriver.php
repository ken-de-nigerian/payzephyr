<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Random\RandomException;

/**
 * PaystackDriver - Handles Payments via Paystack
 *
 * This driver processes payments through Paystack's API.
 * When you initialize a payment, it redirects the customer to Paystack's
 * hosted checkout page where they can pay with card, bank transfer, USSD, etc.
 */
final class PaystackDriver extends AbstractDriver
{
    protected string $name = 'paystack';

    /**
     * Make sure the Paystack secret key is configured.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Paystack secret key is required');
        }
    }

    /**
     * Get the HTTP headers needed for Paystack API requests.
     * Paystack uses Bearer token authentication (your secret key).
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['secret_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Paystack uses the standard 'Idempotency-Key' header.
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment on Paystack.
     *
     * Important: Paystack requires amounts in the smallest currency unit
     * (kobo for NGN, cents for USD). This method automatically converts
     * your amount (e.g., 100.00) to the correct format (10,000).
     *
     * @throws ChargeException If the payment creation fails.
     * @throws RandomException If reference generation fails.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $payload = [
                'email' => $request->email,
                'amount' => $request->getAmountInMinorUnits(),
                'currency' => $request->currency,
                'reference' => $request->reference ?? $this->generateReference(),
                'callback_url' => $request->callbackUrl,
                'metadata' => $request->metadata,
            ];

            $channels = $this->mapChannels($request);
            if ($channels) {
                $payload['channels'] = $channels;
            }

            $response = $this->makeRequest('POST', '/transaction/initialize', [
                'json' => array_filter($payload),
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new ChargeException(
                    $data['message'] ?? 'Failed to initialize Paystack transaction'
                );
            }

            $result = $data['data'];

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $result['reference'],
            ]);

            return new ChargeResponseDTO(
                reference: $result['reference'],
                authorizationUrl: $result['authorization_url'],
                accessCode: $result['access_code'],
                status: 'pending',
                metadata: $request->metadata,
                provider: $this->getName(),
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Paystack charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Check if a Paystack payment was successful.
     *
     * Looks up the transaction by reference and returns the payment details.
     * The amount is automatically converted back from kobo/cents to main units.
     *
     * @param  string  $reference  The transaction reference from Paystack
     *
     * @throws VerificationException If the payment can't be found or verified.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', "/transaction/verify/$reference");
            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new VerificationException(
                    $data['message'] ?? 'Failed to verify Paystack transaction'
                );
            }

            $result = $data['data'];

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $result['status'],
            ]);

            return new VerificationResponseDTO(
                reference: $result['reference'],
                status: $result['status'],
                amount: ($result['amount'] ?? 0) / 100,
                currency: $result['currency'],
                paidAt: $result['paid_at'] ?? null,
                metadata: $result['metadata'] ?? [],
                provider: $this->getName(),
                channel: $result['channel'] ?? null,
                cardType: $result['authorization']['card_type'] ?? null,
                bank: $result['authorization']['bank'] ?? null,
                customer: [
                    'email' => $result['customer']['email'] ?? null,
                    'code' => $result['customer']['customer_code'] ?? null,
                ],
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            throw new VerificationException('Paystack verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify that a webhook is really from Paystack (security check).
     *
     * Paystack signs webhooks using HMAC SHA512 with your secret key.
     * The signature comes in the 'x-paystack-signature' header.
     * This prevents fake webhooks from malicious actors.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-paystack-signature'][0]
            ?? $headers['X-Paystack-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $hash = hash_hmac('sha512', $body, $this->config['secret_key']);

        $isValid = hash_equals($signature, $hash);

        $this->log($isValid ? 'info' : 'warning', 'Webhook validation', [
            'valid' => $isValid,
        ]);

        return $isValid;
    }

    /**
     * Check if Paystack's API is working.
     *
     * Tries to verify a fake reference. If we get a 404 (reference not found),
     * that means the API is working. If we get a 500 or connection error,
     * the API is down.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/transaction/verify/invalid_ref_test');

            // If we get here, the API is reachable
            // Accept both successful responses and 4xx errors (which mean API is working)
            $statusCode = $response->getStatusCode();

            return $statusCode < 500; // API is healthy if it responds with anything < 500

        } catch (ClientException $e) {
            // 4xx errors mean the API is working (just invalid reference)
            return $e->getResponse()->getStatusCode() < 500;

        } catch (GuzzleException $e) {
            // Network errors, timeouts, 5xx errors = unhealthy
            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['data']['reference'] ?? null;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['data']['status'] ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['channel'] ?? null;
    }

    /**
     * Resolve the actual ID needed for verification.
     * Paystack verifies by the main transaction reference, not the access code.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        // Paystack verifies by the main transaction reference, not the access code
        return $reference;
    }
}
