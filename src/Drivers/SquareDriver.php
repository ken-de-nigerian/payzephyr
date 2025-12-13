<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Random\RandomException;
use Throwable;

/**
 * Driver implementation for the Square payment gateway.
 */
final class SquareDriver extends AbstractDriver
{
    protected string $name = 'square';

    /**
     * Make sure the Square secret key and location id is configured.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['access_token'])) {
            throw new InvalidConfigurationException('Square access token is required');
        }
        if (empty($this->config['location_id'])) {
            throw new InvalidConfigurationException('Square location ID is required');
        }
    }

    /**
     * Get the HTTP headers needed for Square API requests.
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['access_token'],
            'Content-Type' => 'application/json',
            'Square-Version' => '2024-10-18',
        ];
    }

    /**
     * Square uses the standard 'Idempotency-Key' header.
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment link on Square.
     *
     * @throws ChargeException|RandomException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        $reference = $request->reference ?? $this->generateReference('SQUARE');

        try {

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
                $errorMessage = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? 'Failed to create Square payment link';
                $this->log('error', 'Failed to create payment link', [
                    'reference' => $reference,
                    'errors' => $data['errors'] ?? [],
                ]);
                throw new ChargeException($errorMessage);
            }

            $paymentLinkUrl = $data['payment_link']['url'];

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $paymentLinkUrl,
                accessCode: $data['payment_link']['id'],
                status: 'pending',
                metadata: [
                    'payment_link_id' => $data['payment_link']['id'],
                    'order_id' => $data['payment_link']['order_id'] ?? null,
                ],
                provider: $this->getName(),
            );
        } catch (ChargeException $e) {
            throw $e;
        } catch (ClientException $e) {
            $response = $e->getResponse();

            $statusCode = $response->getStatusCode();
            $responseData = $this->parseResponse($response);
            $errorMessage = $responseData['errors'][0]['detail'] ?? $responseData['errors'][0]['code'] ?? $e->getMessage();

            $baseUrl = $this->config['base_url'] ?? '';
            $isSandboxUrl = str_contains($baseUrl, 'squareupsandbox.com');
            $isProductionUrl = str_contains($baseUrl, 'squareup.com') && ! $isSandboxUrl;

            if ($statusCode === 401 || $statusCode === 403) {
                $hint = '';
                if ($isSandboxUrl) {
                    $hint = ' Make sure you are using a sandbox access token. Check Square Dashboard → Applications → Your App → Sandbox → Access Tokens.';
                } elseif ($isProductionUrl) {
                    $hint = ' Make sure you are using a production access token. Check Square Dashboard → Applications → Your App → Production → Access Tokens.';
                }
                $errorMessage .= $hint;
            }

            $this->log('error', 'Charge failed', [
                'reference' => $reference,
                'status_code' => $statusCode,
                'error' => $errorMessage,
                'errors' => $responseData['errors'] ?? [],
                'error_class' => get_class($e),
            ]);

            throw new ChargeException('Payment initialization failed: '.$errorMessage, 0, $e);
        } catch (Throwable $e) {
            $this->log('error', 'Charge failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new ChargeException('Payment initialization failed: '.$e->getMessage(), 0, $e);
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
            $result = $this->verifyByPaymentId($reference);
            if ($result !== null) {
                return $result;
            }

            $result = $this->verifyByPaymentLinkId($reference);
            if ($result !== null) {
                return $result;
            }

            return $this->verifyByReferenceId($reference);
        } catch (VerificationException $e) {
            throw $e;
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();

                $statusCode = $response->getStatusCode();
                $responseData = $this->parseResponse($response);

                $errorMessage = $responseData['errors'][0]['detail'] ?? $responseData['errors'][0]['code'] ?? $previous->getMessage();

                $this->log('error', 'Verification failed', [
                    'reference' => $reference,
                    'status_code' => $statusCode,
                    'error' => $errorMessage,
                    'errors' => $responseData['errors'] ?? [],
                    'error_class' => get_class($previous),
                ]);

                throw new VerificationException('Payment verification failed: '.$errorMessage, 0, $previous);
            }

            throw new VerificationException('Payment verification failed: '.$e->getMessage(), 0, $e);
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
     * Attempt to verify payment using a direct payment ID.
     *
     * @return VerificationResponseDTO|null Returns null if the reference is not a payment ID or payment not found
     *
     * @throws ChargeException
     */
    private function verifyByPaymentId(string $reference): ?VerificationResponseDTO
    {
        if (! str_starts_with($reference, 'payment_') && strlen($reference) !== 32) {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', "/v2/payments/$reference");
            $data = $this->parseResponse($response);

            if (isset($data['payment'])) {
                return $this->mapFromPayment($data['payment'], $reference);
            }
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === 404) {
                    return null;
                }
            }
            throw $e;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() === 404) {
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
     *
     * @throws ChargeException
     * @throws VerificationException
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

            $actualReference = $order['reference_id'] ?? $reference;

            return $this->mapFromPayment($paymentDetails['payment'], $actualReference);
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === 404) {
                    return null;
                }
            }
            throw $e;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Verify payment by searching orders using reference_id.
     *
     * @throws VerificationException|ChargeException
     */
    private function verifyByReferenceId(string $reference): VerificationResponseDTO
    {
        $orders = $this->searchOrders();

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
     *
     * @throws VerificationException|ChargeException
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
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === 404) {
                    throw new VerificationException('Payment not found');
                }
            }
            throw $e;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() === 404) {
                throw new VerificationException('Payment not found');
            }
            throw $e;
        }
    }

    /**
     * Retrieve an order by ID.
     *
     * @return array Order data
     *
     * @throws VerificationException|ChargeException
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
     *
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
     *
     * @throws ChargeException
     */
    private function getPaymentDetails(string $paymentId): array
    {
        $response = $this->makeRequest('GET', "/v2/payments/$paymentId");

        return $this->parseResponse($response);
    }

    /**
     * Normalize Square-specific status values.
     * Square's APPROVED status means the payment was successful.
     */
    protected function normalizeStatus(string $status): string
    {
        $statusUpper = strtoupper(trim($status));

        if ($statusUpper === 'APPROVED') {
            return 'success';
        }

        return parent::normalizeStatus($status);
    }

    /**
     * Map Square payment data to VerificationResponseDTO.
     */
    private function mapFromPayment(array $payment, string $reference): VerificationResponseDTO
    {
        $status = $this->normalizeStatus($payment['status'] ?? 'pending');

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

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $body, $webhookSignatureKey, true)
        );

        $isValid = hash_equals($signature, $expectedSignature);

        if (! $isValid) {
            $this->log('warning', 'Webhook validation failed', [
                'hint' => 'Ensure SQUARE_WEBHOOK_SIGNATURE_KEY matches the signature key from your Square webhook endpoint',
            ]);

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

    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v2/locations');

            return $response->getStatusCode() === 200;
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                return true;
            }
            if ($previous instanceof ConnectException) {
                return false;
            }

            return true;
        } catch (ClientException) {
            return true;
        } catch (ConnectException) {
            return false;
        }
    }

    /**
     * Extract payment reference from Square webhook payload.
     * Each provider has different webhook structures - this handles Square's format.
     */
    public function extractWebhookReference(array $payload): ?string
    {
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
        return $providerId;
    }
}
