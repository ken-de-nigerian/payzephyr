<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\GuzzleException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Random\RandomException;

/**
 * Driver implementation for the Paystack payment gateway.
 *
 * This driver handles the Standard Initialization flow, where the user is
 * redirected to Paystack's hosted checkout page.
 */
class PaystackDriver extends AbstractDriver
{
    protected string $name = 'paystack';

    /**
     * Ensure the configuration contains the Secret Key.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Paystack secret key is required');
        }
    }

    /**
     * Get the default headers for API requests.
     *
     * Paystack uses Bearer Token authentication with the Secret Key.
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
     * Paystack uses 'Idempotency-Key' header (standard)
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Initialize a transaction on Paystack.
     *
     * Note: Paystack requires amounts in minor units (e.g., Kobo for NGN).
     * This method utilizes `getAmountInMinorUnits()` to ensure the API
     * receives the correct integer value.
     *
     * @throws ChargeException
     * @throws RandomException
     */
    public function charge(ChargeRequest $request): ChargeResponse
    {
        $this->setCurrentRequest($request);

        try {
            $payload = [
                'email' => $request->email,
                'amount' => $request->getAmountInMinorUnits(),
                'currency' => $request->currency,
                'reference' => $request->reference ?? $this->generateReference(),
                'callback_url' => $request->callbackUrl ?? $this->config['callback_url'] ?? null,
                'metadata' => $request->metadata,
            ];

            if ($request->channels) {
                $payload['channels'] = $request->channels;
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

            return new ChargeResponse(
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
     * Verify a payment using the Transaction Reference.
     *
     * Returns the amount converted back from minor units (kobo) to main units.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponse
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

            return new VerificationResponse(
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
     * Validate the webhook signature.
     *
     * Paystack signs webhooks using HMAC SHA512 with the Secret Key.
     * The signature is sent in the 'x-paystack-signature' header.
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
     * Check API connectivity.
     *
     * Intentionally queries an invalid reference to check if the API is reachable.
     * A 404 response is considered "Healthy" (API is up), whereas a 500 or
     * connection error is considered "Unhealthy".
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/transaction/verify/invalid_ref_test');

            return $response->getStatusCode() < 500;
        } catch (GuzzleException $e) {
            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
