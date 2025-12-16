<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Throwable;

/**
 * Driver implementation for the Nowpayments payment gateway.
 */
final class NowPaymentsDriver extends AbstractDriver
{
    protected string $name = 'nowpayments';

    /**
     * Make sure the Nowpayments secret key is configured.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new InvalidConfigurationException('Nowpayments api key is required');
        }
    }

    /**
     * Get the HTTP headers needed for Nowpayments API requests.
     * Nowpayments uses x-api-key header for authentication.
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'x-api-key' => $this->config['api_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Nowpayments uses the standard 'Idempotency-Key' header.
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment on Nowpayments.
     *
     * @throws ChargeException If the payment creation fails.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('NOW');

            $payload = [
                'price_amount' => $request->amount,
                'price_currency' => $request->currency,
                'order_id' => $reference,
                'order_description' => $request->description ?? 'Payment for services',
                'customer_email' => $request->email,
                'ipn_callback_url' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'success_url' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'cancel_url' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
            ];

            $response = $this->makeRequest('POST', '/v1/invoice', [
                'json' => array_filter($payload),
            ]);

            $data = $this->parseResponse($response);

            // NOWPayments invoice endpoint returns 'id' field (invoice ID)
            if (! isset($data['id'])) {
                throw new ChargeException(
                    $data['message'] ?? 'Failed to initialize Nowpayments transaction'
                );
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'invoice_id' => $data['id'],
            ]);

            // Invoice endpoint returns invoice_url for redirect-based payment
            if (! isset($data['invoice_url'])) {
                throw new ChargeException(
                    $data['message'] ?? 'Failed to initialize Nowpayments transaction: invoice_url missing'
                );
            }

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $data['invoice_url'],
                accessCode: (string) $data['id'],
                status: 'pending',
                metadata: array_merge($request->metadata, [
                    'invoice_id' => $data['id'],
                    'payment_status' => 'waiting', // Invoice starts in waiting state
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
     * Check if a Nowpayments payment was successful.
     *
     * When using invoice endpoint, the reference is the order_id.
     * We verify by searching for the payment using the order_id.
     * The invoice_id (stored in accessCode) can also be used if available.
     *
     * @param  string  $reference  The order_id (our reference) or invoice_id/payment_id
     *
     * @throws VerificationException If the payment can't be found or verified.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            // Try to verify using the reference as payment_id/invoice_id first
            // If that fails, we'll need to search by order_id
            // For invoices that become payments, the invoice_id might work as payment lookup
            $response = $this->makeRequest('GET', "/v1/payment/$reference");
            $data = $this->parseResponse($response);

            // NOWPayments payment endpoint returns payment_id
            if (! isset($data['payment_id'])) {
                throw new VerificationException(
                    $data['message'] ?? 'Failed to verify Nowpayments transaction'
                );
            }

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'payment_id' => $data['payment_id'],
                'order_id' => $data['order_id'] ?? null,
                'status' => $data['payment_status'] ?? 'unknown',
            ]);

            $status = $data['payment_status'] ?? 'unknown';

            // Handle timestamp conversion - NOWPayments returns ISO 8601 strings
            $paidAt = null;
            if (isset($data['updated_at'])) {
                $timestamp = is_string($data['updated_at'])
                    ? strtotime($data['updated_at'])
                    : (int) $data['updated_at'];
                if ($timestamp !== false) {
                    $paidAt = date('Y-m-d H:i:s', $timestamp);
                }
            }

            return new VerificationResponseDTO(
                reference: $data['order_id'] ?? $reference,
                status: $status,
                amount: (float) ($data['price_amount'] ?? 0),
                currency: strtoupper($data['price_currency'] ?? 'USD'),
                paidAt: $paidAt,
                metadata: [
                    'payment_id' => $data['payment_id'],
                    'purchase_id' => $data['purchase_id'] ?? null,
                    'pay_currency' => $data['pay_currency'] ?? null,
                    'pay_amount' => $data['pay_amount'] ?? null,
                    'amount_received' => $data['amount_received'] ?? null,
                    'actually_paid' => $data['actually_paid'] ?? $data['amount_received'] ?? null,
                    'outcome_amount' => $data['outcome_amount'] ?? null,
                    'outcome_currency' => $data['outcome_currency'] ?? null,
                    'pay_address' => $data['pay_address'] ?? null,
                    'network' => $data['network'] ?? null,
                    'smart_contract' => $data['smart_contract'] ?? null,
                ],
                provider: $this->getName(),
                channel: $data['pay_currency'] ?? null,
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
     * Verify that a webhook is really from Nowpayments (security check).
     *
     * Nowpayments signs webhooks using HMAC SHA512 with your IPN secret key.
     * The signature comes in the 'x-nowpayments-sig' header.
     * This prevents fake webhooks from malicious actors.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-nowpayments-sig'][0]
            ?? $headers['X-Nowpayments-Sig'][0]
            ?? $headers['x-Nowpayments-Sig'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $ipnSecret = $this->config['ipn_secret'];
        if (! $ipnSecret) {
            $this->log('warning', 'IPN secret key not configured for webhook validation');

            return false;
        }

        $hash = hash_hmac('sha512', $body, $ipnSecret);
        $signatureValid = hash_equals($signature, $hash);

        if (! $signatureValid) {
            $this->log('warning', 'Webhook signature invalid');

            return false;
        }

        $payload = json_decode($body, true) ?? [];
        if (! $this->validateWebhookTimestamp($payload)) {
            $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

            return false;
        }

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * Check if Nowpayments's API is working.
     *
     * Uses the status endpoint to test the API. A 200 or 400/404 response
     * indicates the API is working correctly (it's responding as expected).
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v1/status');
            $statusCode = $response->getStatusCode();

            return $statusCode < 500;
        } catch (Throwable $e) {
            $previous = $e->getPrevious();

            $clientException = null;
            $current = $e;
            while ($current !== null) {
                if ($current instanceof ClientException) {
                    $clientException = $current;
                    break;
                }
                $current = $current->getPrevious();
            }

            if ($clientException !== null) {
                $response = $clientException->getResponse();
                $statusCode = $response->getStatusCode();
                if (in_array($statusCode, [400, 404, 401], true)) {
                    $this->log('info', 'Health check successful (expected 400/404/401 response)', [
                        'status_code' => $statusCode,
                    ]);

                    return true;
                }
            }

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
        $reference = $payload['order_id'] ?? $payload['payment_id'] ?? null;

        // Convert to string if it's an integer (payment_id can be numeric)
        if ($reference !== null && ! is_string($reference)) {
            $reference = (string) $reference;
        }

        return $reference;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['payment_status'] ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['pay_currency'] ?? null;
    }

    /**
     * Resolve the actual ID needed for verification.
     *
     * For invoices: providerId contains invoice_id, but we verify by order_id (reference)
     * After payment, the invoice_id might work for payment lookup, but order_id is more reliable
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        // For NOWPayments invoices, we verify using order_id (reference)
        // The invoice_id in providerId might work, but order_id is the standard way
        // Try invoice_id first if available, otherwise use order_id (reference)
        return ! empty($providerId) ? $providerId : $reference;
    }
}
