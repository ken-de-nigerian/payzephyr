<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Throwable;

/**
 * Driver implementation for the Paystack payment gateway.
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
     * @throws ChargeException If the payment creation fails.
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
        } catch (ChargeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Charge failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new ChargeException('Payment initialization failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Check if a Paystack payment was successful.
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
        } catch (VerificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new VerificationException('Payment verification failed: '.$e->getMessage(), 0, $e);
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
     * Uses an invalid reference to test the API. A 400 Bad Request response
     * indicates the API is working correctly (it's responding as expected).
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/transaction/verify/invalid_ref_test');
            $statusCode = $response->getStatusCode();

            // Any response < 500 means the API is working
            return $statusCode < 500;

        } catch (Throwable $e) {
            // Check if this is a ChargeException (or PaymentException) wrapping a ClientException
            $previous = $e->getPrevious();
            
            // Traverse exception chain to find ClientException
            $clientException = null;
            $current = $e;
            while ($current !== null) {
                if ($current instanceof ClientException) {
                    $clientException = $current;
                    break;
                }
                $current = $current->getPrevious();
            }
            
            // If we found a ClientException with 400/404 status, API is working correctly
            if ($clientException !== null) {
                $statusCode = $clientException->getResponse()?->getStatusCode();
                if (in_array($statusCode, [400, 404], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)', [
                        'status_code' => $statusCode,
                    ]);
                    return true;
                }
            }

            // For any other exception, log and return false
            $this->log('error', 'Health check failed', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'previous_class' => $previous ? get_class($previous) : null,
            ]);
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
        return $reference;
    }
}
