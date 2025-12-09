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

final class SquareDriver extends AbstractDriver
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
            'Square-Version' => '2024-10-18',
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

            $paymentLinkUrl = $data['payment_link']['url'];
            $isSandbox = str_contains($this->config['base_url'] ?? '', 'squareupsandbox.com');

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $paymentLinkUrl,
                accessCode: $data['payment_link']['id'],
                status: 'pending',
                metadata: [
                    'payment_link_id' => $data['payment_link']['id'],
                    'order_id' => $data['payment_link']['order_id'] ?? null,
                    'is_sandbox' => $isSandbox,
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
     * Square can verify by payment ID, payment link ID, or by searching orders using reference_id.
     * This method tries multiple verification strategies in order.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            // Strategy 1: Try direct payment ID lookup
            $result = $this->verifyByPaymentId($reference);
            if ($result !== null) {
                return $result;
            }

            // Strategy 2: Try payment link ID lookup
            $result = $this->verifyByPaymentLinkId($reference);
            if ($result !== null) {
                return $result;
            }

            // Strategy 3: Fallback to order search by reference_id
            return $this->verifyByReferenceId($reference);
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
     * Attempt to verify payment using a direct payment ID.
     *
     * @return VerificationResponseDTO|null Returns null if the reference is not a payment ID or payment not found
     */
    private function verifyByPaymentId(string $reference): ?VerificationResponseDTO
    {
        // Only attempt if reference looks like a payment ID
        if (! str_starts_with($reference, 'payment_') && strlen($reference) !== 32) {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', "/v2/payments/$reference");
            $data = $this->parseResponse($response);

            if (isset($data['payment'])) {
                return $this->mapFromPayment($data['payment'], $reference);
            }
        } catch (ClientException $e) {
            // If 404, payment not found - return null to try other methods
            if ($e->getResponse()?->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return null;
    }

    /**
     * Attempt to verify payment using a payment link ID.
     *
     * Payment link IDs are typically alphanumeric strings (e.g., JE6RV44VZEML32Z2).
     *
     * @return VerificationResponseDTO|null Returns null if the reference is not a payment link ID or payment not found
     */
    private function verifyByPaymentLinkId(string $reference): ?VerificationResponseDTO
    {
        try {
            $paymentLinkResponse = $this->makeRequest('GET', "/v2/online-checkout/payment-links/$reference");
            $paymentLinkData = $this->parseResponse($paymentLinkResponse);

            $orderId = $paymentLinkData['payment_link']['order_id'] ?? null;
            if (! $orderId) {
                return null;
            }

            $order = $this->getOrderById($orderId);
            $payment = $this->getPaymentFromOrder($order, $orderId);
            $paymentDetails = $this->getPaymentDetails($payment);

            // Use the reference_id from the order if available, otherwise use the passed reference
            $actualReference = $order['reference_id'] ?? $reference;

            return $this->mapFromPayment($paymentDetails['payment'], $actualReference);
        } catch (ClientException $e) {
            // If 404, payment link not found - return null to try other methods
            if ($e->getResponse()?->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Verify payment by searching orders using reference_id.
     *
     * @throws VerificationException
     */
    private function verifyByReferenceId(string $reference): VerificationResponseDTO
    {
        $orders = $this->searchOrders();

        // Find order with matching reference_id
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

        $orderId = $foundOrder['id'];
        $order = $this->getOrderById($orderId);
        $payment = $this->getPaymentFromOrder($order, $orderId);
        $paymentDetails = $this->getPaymentDetails($payment);

        return $this->mapFromPayment($paymentDetails['payment'], $reference);
    }

    /**
     * Search orders using Square's order search API.
     *
     * @return array List of orders
     * @throws VerificationException
     */
    private function searchOrders(): array
    {
        try {
            $response = $this->makeRequest('POST', '/v2/orders/search', [
                'json' => [
                    'location_ids' => [$this->config['location_id']],
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

            return $data['orders'] ?? [];
        } catch (ClientException $e) {
            if ($e->getResponse()?->getStatusCode() === 404) {
                throw new VerificationException('Payment not found');
            }
            throw $e;
        }
    }

    /**
     * Retrieve an order by ID.
     *
     * @return array Order data
     * @throws VerificationException
     */
    private function getOrderById(string $orderId): array
    {
        $response = $this->makeRequest('GET', "/v2/orders/$orderId");
        $data = $this->parseResponse($response);

        $order = $data['order'] ?? null;
        if (! $order) {
            throw new VerificationException("Order not found for ID [$orderId]");
        }

        return $order;
    }

    /**
     * Extract payment ID from an order's tenders.
     *
     * @return string Payment ID
     * @throws VerificationException
     */
    private function getPaymentFromOrder(array $order, string $orderId): string
    {
        $tenders = $order['tenders'] ?? [];
        if (empty($tenders)) {
            throw new VerificationException("No payment found for order [$orderId]");
        }

        $paymentId = $tenders[0]['payment_id'] ?? null;
        if (! $paymentId) {
            throw new VerificationException("Payment ID not found for order [$orderId]");
        }

        return $paymentId;
    }

    /**
     * Retrieve payment details by payment ID.
     *
     * @return array Payment data
     */
    private function getPaymentDetails(string $paymentId): array
    {
        $response = $this->makeRequest('GET', "/v2/payments/$paymentId");
        $data = $this->parseResponse($response);

        return $data;
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
