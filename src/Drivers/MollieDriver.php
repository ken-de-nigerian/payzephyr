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
 * Driver implementation for the Mollie payment gateway.
 */
final class MollieDriver extends AbstractDriver
{
    protected string $name = 'mollie';

    /**
     * Ensure the configuration contains the required API key.
     *
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new InvalidConfigurationException('Mollie API key is required');
        }
    }

    /**
     * Get the default HTTP headers needed for Mollie API requests.
     *
     * Mollie uses Bearer token authentication with the API key.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['api_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Mollie uses standard 'Idempotency-Key' header.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Initialize a payment with Mollie.
     *
     * Mollie uses a redirect-based flow where customers are sent to a hosted
     * payment page to complete their payment.
     *
     * @param  ChargeRequestDTO  $request  Payment request details
     * @return ChargeResponseDTO Payment response with redirect URL
     *
     * @throws ChargeException If payment initialization fails
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('MOLLIE');

            $payload = [
                'amount' => [
                    'currency' => $request->currency,
                    'value' => $this->formatAmount($request->amount, $request->currency),
                ],
                'description' => $request->description ?? 'Payment',
                'redirectUrl' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'metadata' => array_merge($request->metadata, [
                    'reference' => $reference,
                ]),
            ];

            $methods = $this->mapChannels($request);
            if ($methods) {
                $payload['method'] = $methods;
            }

            $response = $this->makeRequest('POST', '/v2/payments', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            $checkoutUrl = $data['_links']['checkout']['href'] ?? null;
            if (! $checkoutUrl) {
                throw new ChargeException('No checkout URL returned by Mollie');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'mollie_id' => $data['id'],
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $checkoutUrl,
                accessCode: $data['id'],
                status: $this->normalizeStatus($data['status']),
                metadata: array_merge($request->metadata, [
                    'mollie_id' => $data['id'],
                    'reference' => $reference,
                ]),
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
     * Verify a payment by retrieving its details from Mollie.
     *
     * @param  string  $reference  The Mollie payment ID or our internal reference
     * @return VerificationResponseDTO Payment verification details
     *
     * @throws VerificationException If verification fails
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $paymentId = $reference;

            $response = $this->makeRequest('GET', "/v2/payments/$paymentId");
            $data = $this->parseResponse($response);

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $data['status'],
            ]);

            $actualReference = $data['metadata']['reference'] ?? $reference;

            return new VerificationResponseDTO(
                reference: $actualReference,
                status: $this->normalizeStatus($data['status']),
                amount: (float) $data['amount']['value'],
                currency: $data['amount']['currency'],
                paidAt: $data['paidAt'] ?? null,
                metadata: $data['metadata'] ?? [],
                provider: $this->getName(),
                channel: $data['method'] ?? null,
                cardType: $data['details']['cardLabel'] ?? null,
                bank: $data['details']['consumerName'] ?? null,
                customer: [
                    'email' => $data['billingAddress']['email'] ?? null,
                    'name' => $data['billingAddress']['givenName'] ?? null,
                ],
            );
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
     * Validate Mollie webhook.
     *
     * @param  array<string, array<string>>  $headers  Request headers
     * @param  string  $body  Raw request body
     * @return bool True if webhook is valid
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        if (! empty($this->config['webhook_secret'])) {
            return $this->validateWebhookSignature($headers, $body);
        }

        return $this->validateWebhookViaAPI($body);
    }

    /**
     * Validate webhook using HMAC SHA-256 signature.
     *
     * Mollie signs webhooks using HMAC SHA-256 with the webhook secret.
     * The signature comes in the 'X-Mollie-Signature' header.
     *
     * Note: For hook.ping events (test events), we only validate the signature.
     * For payment events, we also validate the timestamp.
     *
     * @param  array<string, array<string>>  $headers  Request headers
     * @param  string  $body  Raw request body
     * @return bool True if signature is valid
     */
    protected function validateWebhookSignature(array $headers, string $body): bool
    {
        $signature = $headers['x-mollie-signature'][0]
            ?? $headers['X-Mollie-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing', [
                'hint' => 'Mollie webhooks should include the X-Mollie-Signature header when webhook_secret is configured',
            ]);

            return false;
        }

        $expectedSignature = hash_hmac('sha256', $body, $this->config['webhook_secret']);
        $isValid = hash_equals($signature, $expectedSignature);

        if (! $isValid) {
            $this->log('warning', 'Webhook signature validation failed', [
                'hint' => 'The X-Mollie-Signature header does not match. Ensure MOLLIE_WEBHOOK_SECRET matches the webhook secret from your Mollie dashboard.',
            ]);

            return false;
        }

        $payload = json_decode($body, true) ?? [];
        
        // hook.ping events are test events - just validate signature and accept
        $eventType = $payload['type'] ?? null;
        if ($eventType === 'hook.ping') {
            $this->log('info', 'Webhook validated successfully (hook.ping test event)', [
                'event_id' => $payload['id'] ?? null,
            ]);

            return true;
        }

        // For payment events, also validate timestamp
        if (! $this->validateWebhookTimestamp($payload)) {
            $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

            return false;
        }

        $this->log('info', 'Webhook validated successfully via signature verification', [
            'event_type' => $eventType,
        ]);

        return true;
    }

    /**
     * Validate webhook by fetching payment details from API (fallback method).
     *
     * This method is used when webhook_secret is not configured.
     * It fetches the payment from Mollie's API to verify it exists and is legitimate.
     *
     * Note: hook.ping events (test events) are accepted without API verification
     * since they don't contain payment information.
     *
     * @param  string  $body  Raw request body
     * @return bool True if webhook is valid
     */
    protected function validateWebhookViaAPI(string $body): bool
    {
        try {
            $payload = json_decode($body, true);

            if (! $payload) {
                $this->log('warning', 'Webhook payload is invalid JSON');

                return false;
            }

            // hook.ping events are test events - accept them without API verification
            $eventType = $payload['type'] ?? null;
            if ($eventType === 'hook.ping') {
                $this->log('info', 'Webhook validated successfully (hook.ping test event)', [
                    'event_id' => $payload['id'] ?? null,
                    'hint' => 'Consider configuring MOLLIE_WEBHOOK_SECRET for more secure signature-based validation',
                ]);

                return true;
            }

            // For payment events, require payment ID
            if (! isset($payload['id'])) {
                $this->log('warning', 'Webhook missing payment ID');

                return false;
            }

            $paymentId = $payload['id'];

            try {
                $response = $this->makeRequest('GET', "/v2/payments/$paymentId");
                $paymentData = $this->parseResponse($response);

                if (! isset($paymentData['id']) || $paymentData['id'] !== $paymentId) {
                    $this->log('warning', 'Payment verification failed - payment ID mismatch', [
                        'expected' => $paymentId,
                        'received' => $paymentData['id'] ?? null,
                    ]);

                    return false;
                }

                if (! $this->validateWebhookTimestamp($payload)) {
                    $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

                    return false;
                }

                $this->log('info', 'Webhook validated successfully via API verification', [
                    'payment_id' => $paymentId,
                    'payment_status' => $paymentData['status'] ?? 'unknown',
                    'hint' => 'Consider configuring MOLLIE_WEBHOOK_SECRET for more secure signature-based validation',
                ]);

                return true;
            } catch (ClientException $e) {
                $response = $e->getResponse();

                $statusCode = $response->getStatusCode();
                $this->log('warning', 'Webhook validation API call failed', [
                    'payment_id' => $paymentId,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        } catch (Throwable $e) {
            $this->log('error', 'Webhook validation failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Check if Mollie API is accessible.
     *
     * @return bool True if API is healthy
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v2/methods');
            $statusCode = $response->getStatusCode();

            return $statusCode < 500;
        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if (
                ($e instanceof PaymentException)
                && ($previous instanceof ClientException)
            ) {
                $response = $previous->getResponse();
                $statusCode = $response->getStatusCode();
                if (in_array($statusCode, [400, 404], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)');

                    return true;
                }
            }

            $this->log('error', 'Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract the transaction reference from Mollie's webhook payload.
     *
     * @param  array<string, mixed>  $payload  Webhook payload
     * @return string|null Transaction reference or null if not found
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['id'] ?? null;
    }

    /**
     * Extract the payment status from Mollie's webhook payload.
     *
     * Mollie webhook doesn't contain full payment details, just the ID.
     * The actual status should be fetched from the API.
     *
     * @param  array<string, mixed>  $payload  Webhook payload
     * @return string Payment status
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['status'] ?? 'unknown';
    }

    /**
     * Extract the payment channel from Mollie's webhook payload.
     *
     * @param  array<string, mixed>  $payload  Webhook payload
     * @return string|null Payment channel or null if not found
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['method'] ?? null;
    }

    /**
     * Resolve the actual ID needed for verification.
     *
     * Mollie uses the payment ID for verification, not our internal reference.
     *
     * @param  string  $reference  Our internal reference
     * @param  string  $providerId  Mollie's payment ID
     * @return string ID to use for verification
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $providerId;
    }

    /**
     * Format amount to Mollie's required format.
     *
     * Mollie requires amounts as strings with exactly 2 decimal places.
     *
     * @param  float  $amount  Amount in major units
     * @param  string  $currency  Currency code
     * @return string Formatted amount (e.g., "10.00")
     */
    protected function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', '');
    }
}
