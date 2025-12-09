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

class SquareDriver extends AbstractDriver
{
    protected string $name = 'square';

    protected function validateConfig(): void
    {
        if (empty($this->config['access_token'])) {
            throw new InvalidConfigurationException('Square access token is required');
        }
        if (empty($this->config['location_id'])) {
            throw new InvalidConfigurationException('Square location ID is required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['access_token'],
            'Content-Type' => 'application/json',
            'Square-Version' => '2024-01-18', // API version
        ];
    }

    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment link on Square.
     *
     * Square uses Online Checkout Payment Links for redirect-based payments.
     * This creates a payment link that redirects customers to Square's checkout page.
     *
     * @throws ChargeException
     * @throws RandomException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('SQUARE');

            $payload = [
                'idempotency_key' => $request->idempotencyKey ?? uniqid('square_', true),
                'order' => [
                    'location_id' => $this->config['location_id'],
                    'reference_id' => $reference,
                    'line_items' => [
                        [
                            'name' => $request->description ?? 'Payment',
                            'quantity' => '1',
                            'base_price_money' => [
                                'amount' => $request->getAmountInMinorUnits(),
                                'currency' => $request->currency,
                            ],
                        ],
                    ],
                ],
                'redirect_url' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'buyer_email_address' => $request->email,
            ];

            $response = $this->makeRequest('POST', '/v2/online-checkout/payment-links', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['payment_link'])) {
                throw new ChargeException('Failed to create Square payment link');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $data['payment_link']['url'],
                accessCode: $data['payment_link']['id'],
                status: 'pending',
                metadata: [
                    'payment_link_id' => $data['payment_link']['id'],
                    'order_id' => $data['payment_link']['order_id'],
                ],
                provider: $this->getName(),
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Square charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Verify a payment by retrieving the payment details.
     *
     * Square can verify by payment ID or by searching orders using reference_id.
     * This method tries payment ID first, then falls back to order search.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            // Try direct payment ID lookup first (if reference is a payment ID)
            if (str_starts_with($reference, 'payment_') || strlen($reference) === 32) {
                try {
                    $response = $this->makeRequest('GET', "/v2/payments/$reference");
                    $data = $this->parseResponse($response);

                    if (isset($data['payment'])) {
                        return $this->mapFromPayment($data['payment'], $reference);
                    }
                } catch (ClientException $e) {
                    // If 404, try order search instead
                    if ($e->getResponse()?->getStatusCode() !== 404) {
                        throw $e;
                    }
                }
            }

            // Fallback: Search orders by reference_id
            // Square's order search uses POST method
            try {
                $response = $this->makeRequest('POST', '/v2/orders/search', [
                    'json' => [
                        'query' => [
                            'filter' => [
                                'state_filter' => [
                                    'states' => ['OPEN', 'COMPLETED', 'CANCELED'],
                                ],
                            ],
                        ],
                    ],
                ]);

                $data = $this->parseResponse($response);
            } catch (ClientException $e) {
                // If order search fails with 404, payment not found
                if ($e->getResponse()?->getStatusCode() === 404) {
                    throw new VerificationException("Payment not found for reference [$reference]");
                }
                throw $e;
            }

            // Find order with matching reference_id
            $orders = $data['orders'] ?? [];
            $foundOrder = null;

            foreach ($orders as $order) {
                if (($order['reference_id'] ?? null) === $reference) {
                    $foundOrder = $order;
                    break;
                }
            }

            if (! $foundOrder) {
                throw new VerificationException("Payment not found for reference [$reference]");
            }

            // Get payment for the order
            $orderId = $foundOrder['id'];
            $paymentResponse = $this->makeRequest('GET', "/v2/orders/$orderId");
            $paymentData = $this->parseResponse($paymentResponse);

            $payments = $paymentData['order']['tenders'] ?? [];
            if (empty($payments)) {
                throw new VerificationException("No payment found for order [$orderId]");
            }

            $payment = $payments[0]['payment_id'] ?? null;
            if (! $payment) {
                throw new VerificationException("Payment ID not found for order [$orderId]");
            }

            // Get payment details
            $paymentDetailsResponse = $this->makeRequest('GET', "/v2/payments/$payment");
            $paymentDetails = $this->parseResponse($paymentDetailsResponse);

            return $this->mapFromPayment($paymentDetails['payment'], $reference);
        } catch (GuzzleException $e) {
            $this->log('error', 'Verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            throw new VerificationException(
                'Square verification failed: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Map Square payment data to VerificationResponseDTO.
     */
    private function mapFromPayment(array $payment, string $reference): VerificationResponseDTO
    {
        $status = match (strtoupper($payment['status'] ?? '')) {
            'COMPLETED', 'APPROVED' => 'success',
            'FAILED', 'CANCELED' => 'failed',
            default => 'pending',
        };

        return new VerificationResponseDTO(
            reference: $payment['reference_id'] ?? $reference,
            status: $status,
            amount: ($payment['amount_money']['amount'] ?? 0) / 100,
            currency: strtoupper($payment['amount_money']['currency'] ?? 'USD'),
            paidAt: $status === 'success' ? ($payment['updated_at'] ?? $payment['created_at'] ?? null) : null,
            metadata: [
                'payment_id' => $payment['id'] ?? null,
                'order_id' => $payment['order_id'] ?? null,
            ],
            provider: $this->getName(),
            channel: $payment['source_type'] ?? 'card',
            cardType: $payment['card_details']['card']['card_brand'] ?? null,
            customer: [
                'email' => $payment['buyer_email_address'] ?? null,
            ],
        );
    }

    /**
     * Validate the webhook signature.
     *
     * Square uses HMAC SHA256 with base64 encoding for webhook signatures.
     * The signature is sent in the 'x-square-signature' header.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-square-signature'][0]
            ?? $headers['X-Square-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $webhookSignatureKey = $this->config['webhook_signature_key'] ?? null;

        if (! $webhookSignatureKey) {
            $this->log('warning', 'Webhook signature key not configured', [
                'hint' => 'Set SQUARE_WEBHOOK_SIGNATURE_KEY in your .env file. Get it from Square Dashboard → Developers → Webhooks → Select endpoint → Signature Key',
            ]);

            return false;
        }

        // Square uses HMAC SHA256 with base64 encoding
        $expectedSignature = base64_encode(
            hash_hmac('sha256', $body, $webhookSignatureKey, true)
        );

        $isValid = hash_equals($signature, $expectedSignature);

        if ($isValid) {
            $this->log('info', 'Webhook validated successfully');
        } else {
            $this->log('warning', 'Webhook validation failed', [
                'hint' => 'Ensure SQUARE_WEBHOOK_SIGNATURE_KEY matches the signature key from your Square webhook endpoint',
            ]);
        }

        return $isValid;
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v2/locations');

            return $response->getStatusCode() === 200;
        } catch (ClientException) {
            return true; // 4xx means API is reachable
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Extract payment reference from Square webhook payload.
     * Each provider has different webhook structures - this handles Square's format.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        // Square webhook structure: data.object.payment.reference_id
        return $payload['data']['object']['payment']['reference_id']
            ?? $payload['data']['id']
            ?? null;
    }

    /**
     * Extract payment status from Square webhook payload.
     * Returns raw status - StatusNormalizer will convert to standard format.
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['data']['object']['payment']['status']
            ?? $payload['type']
            ?? 'unknown';
    }

    /**
     * Extract payment channel/method from Square webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['object']['payment']['source_type'] ?? 'card';
    }

    /**
     * Resolve the ID needed for verification.
     * Square uses payment ID for verification, not the reference.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        // Square verifies by payment ID, not reference
        return $providerId;
    }
}
