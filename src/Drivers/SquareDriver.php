<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\SquareRefundMethods;
use KenDeNigerian\PayZephyr\Traits\SquareSubscriptionMethods;
use Random\RandomException;
use Throwable;

/**
 * Driver implementation for the Square payment gateway.
 */
final class SquareDriver extends AbstractDriver implements SupportsRefundsInterface, SupportsSubscriptionsInterface
{
    /** Orders per page of the verify-by-reference search; Square allows up to 1000. */
    private const ORDER_SEARCH_PAGE_SIZE = 500;

    /** Default pages the verify-by-reference search reads before giving up. */
    private const ORDER_SEARCH_MAX_PAGES = 10;

    use SquareRefundMethods;
    use SquareSubscriptionMethods;

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
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->settings()->string('access_token'),
            'Content-Type' => 'application/json',
            'Square-Version' => '2024-10-18',
        ];
    }

    /**
     * Square uses the standard 'Idempotency-Key' header.
     *
     * @return array<string, string>
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

            $link = Payload::of($data)->array('payment_link');

            if ($link === []) {
                $errorMessage = $this->errorDetail($data) ?? 'Failed to create Square payment link';
                $this->log('error', 'Failed to create payment link', [
                    'reference' => $reference,
                    'errors' => $data['errors'] ?? [],
                ]);
                throw new ChargeException($errorMessage);
            }

            $paymentLinkUrl = $this->requireString($link, 'url', 'charge');

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $paymentLinkUrl,
                accessCode: $this->requireString($link, 'id', 'charge'),
                status: 'pending',
                metadata: [
                    'payment_link_id' => Payload::of($link)->string('id'),
                    'order_id' => Payload::of($link)->string('order_id'),
                ],
                provider: $this->getName(),
            );
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();

            if (! $previous instanceof ClientException) {
                throw $e;
            }

            $response = $previous->getResponse();

            $statusCode = $response->getStatusCode();
            $responseData = $this->parseResponse($response);
            $errorMessage = $this->errorDetail($responseData) ?? $previous->getMessage();

            $baseUrl = $this->settings()->string('base_url') ?? '';
            $isSandboxUrl = str_contains($baseUrl, 'squareupsandbox.com');
            $isProductionUrl = str_contains($baseUrl, 'squareup.com') && ! $isSandboxUrl;

            if ($statusCode === HttpStatusCodes::UNAUTHORIZED || $statusCode === HttpStatusCodes::FORBIDDEN) {
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
                'error_class' => get_class($previous),
            ]);

            throw new ChargeException('Payment initialization failed: '.$errorMessage, 0, $previous);
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

                $errorMessage = $this->errorDetail($responseData) ?? $previous->getMessage();

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
            $response = $this->makeRequest('GET', '/v2/payments/'.rawurlencode($reference));
            $data = $this->parseResponse($response);

            $payment = Payload::of($data)->array('payment');

            if ($payment !== []) {
                return $this->mapFromPayment($payment, $reference);
            }
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === HttpStatusCodes::NOT_FOUND) {
                    return null;
                }
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
            $paymentLinkResponse = $this->makeRequest('GET', '/v2/online-checkout/payment-links/'.rawurlencode($reference));
            $paymentLinkData = $this->parseResponse($paymentLinkResponse);

            $orderId = Payload::of($paymentLinkData)->string('payment_link', 'order_id');
            if (! $orderId) {
                return null;
            }

            $order = $this->getOrderById($orderId);
            $payment = $this->getPaymentFromOrder($order, $orderId);
            $paymentDetails = $this->getPaymentDetails($payment);

            $actualReference = Payload::of($order)->string('reference_id') ?? $reference;

            return $this->mapFromPayment(Payload::of($paymentDetails)->array('payment'), $actualReference);
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === HttpStatusCodes::NOT_FOUND) {
                    return null;
                }
            }
            throw $e;
        }
    }

    /**
     * Verify payment by searching orders using reference_id.
     *
     * The last resort: a charge stores its payment link id, and verify() goes
     * straight to that whenever the transaction log or the session cache has
     * it. This path serves a verify with nothing but the reference - logging
     * off and the cache expired, or a reference Square did not get from a
     * PayZephyr charge.
     *
     * Square's order search cannot filter on reference_id, so orders are read
     * newest-first, a page at a time, until one matches. It used to read one
     * page, so on a busy account any order that had dropped off it could
     * never be found. The number of pages is bounded
     * (`verify_search_pages`, default 10 of 500) so a reference that matches
     * nothing costs a known number of requests, and the error says how far
     * back it looked.
     *
     * @throws VerificationException|ChargeException
     */
    private function verifyByReferenceId(string $reference): VerificationResponseDTO
    {
        $foundOrder = $this->findOrderByReference($reference);

        $orderId = $this->requireString($foundOrder, 'id', 'verify');
        $order = $this->getOrderById($orderId);
        $payment = $this->getPaymentFromOrder($order, $orderId);
        $paymentDetails = $this->getPaymentDetails($payment);

        return $this->mapFromPayment(Payload::of($paymentDetails)->array('payment'), $reference);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws VerificationException|ChargeException
     */
    private function findOrderByReference(string $reference): array
    {
        $maxPages = max(1, $this->settings()->int('verify_search_pages') ?? self::ORDER_SEARCH_MAX_PAGES);
        $cursor = null;
        $searched = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->searchOrdersPage($cursor);
            $orders = Payload::of($data)->array('orders');
            $searched += count($orders);

            foreach ($orders as $order) {
                $order = Payload::of($order);

                if ($order->string('reference_id') === $reference) {
                    return $order->all();
                }
            }

            $cursor = Payload::of($data)->string('cursor');

            if ($cursor === null || $cursor === '') {
                throw new VerificationException("Payment not found for reference [$reference]");
            }
        }

        throw new VerificationException(
            "Payment not found for reference [$reference] in the $searched most recent Square orders. ".
            'Square cannot search orders by reference, so older orders are not read. Verify with the '.
            'payment link id instead, or raise verify_search_pages.'
        );
    }

    /**
     * One page of the location's orders, newest first.
     *
     * @return array<array-key, mixed>
     *
     * @throws VerificationException|ChargeException
     */
    private function searchOrdersPage(?string $cursor): array
    {
        try {
            $response = $this->makeRequest('POST', '/v2/orders/search', [
                'json' => array_filter([
                    'location_ids' => [$this->config['location_id']],
                    'limit' => self::ORDER_SEARCH_PAGE_SIZE,
                    'cursor' => $cursor,
                    'query' => [
                        'filter' => [
                            'state_filter' => [
                                'states' => ['OPEN', 'COMPLETED', 'CANCELED'],
                            ],
                        ],
                        'sort' => [
                            'sort_field' => 'CREATED_AT',
                            'sort_order' => 'DESC',
                        ],
                    ],
                ], fn ($value) => $value !== null),
            ]);

            return $this->parseResponse($response);
        } catch (ChargeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $response = $previous->getResponse();
                if ($response->getStatusCode() === HttpStatusCodes::NOT_FOUND) {
                    throw new VerificationException('Payment not found');
                }
            }
            throw $e;
        }
    }

    /**
     * Square's own explanation of a rejected request, when it gave one.
     *
     * @param  array<array-key, mixed>  $response
     */
    private function errorDetail(array $response): ?string
    {
        $error = Payload::of($response)->at('errors', 0);

        return $error->string('detail') ?? $error->string('code');
    }

    /**
     * Retrieve an order by ID.
     *
     * @return array<array-key, mixed> Order data
     *
     * @throws VerificationException|ChargeException
     */
    private function getOrderById(string $orderId): array
    {
        $response = $this->makeRequest('GET', '/v2/orders/'.rawurlencode($orderId));
        $data = $this->parseResponse($response);

        $order = Payload::of($data)->array('order');
        if ($order === []) {
            throw new VerificationException("Order not found for ID [$orderId]");
        }

        return $order;
    }

    /**
     * Extract payment ID from an order's tenders.
     *
     * @param  array<array-key, mixed>  $order
     * @return string Payment ID
     *
     * @throws VerificationException
     */
    private function getPaymentFromOrder(array $order, string $orderId): string
    {
        $tenders = Payload::of($order)->array('tenders');
        if ($tenders === []) {
            throw new VerificationException("No payment found for order [$orderId]");
        }

        $paymentId = Payload::of($tenders)->string(0, 'payment_id');
        if (! $paymentId) {
            throw new VerificationException("Payment ID not found for order [$orderId]");
        }

        return $paymentId;
    }

    /**
     * Retrieve payment details by payment ID.
     *
     * @return array<array-key, mixed> Payment data
     *
     * @throws ChargeException
     */
    private function getPaymentDetails(string $paymentId): array
    {
        $response = $this->makeRequest('GET', '/v2/payments/'.rawurlencode($paymentId));

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
     *
     * @param  array<array-key, mixed>  $payment
     *
     * @throws ChargeException
     */
    private function mapFromPayment(array $payment, string $reference): VerificationResponseDTO
    {
        $details = new Payload($payment);
        $status = $this->normalizeStatus($details->string('status') ?? 'pending');

        return new VerificationResponseDTO(
            reference: $details->string('reference_id') ?? $reference,
            status: $status,
            amount: $this->requireAmount($this->requireArray($payment, 'amount_money', 'verify'), 'amount', 'verify') / 100,
            currency: strtoupper($this->requireString($this->requireArray($payment, 'amount_money', 'verify'), 'currency', 'verify')),
            paidAt: $status === 'success' ? ($details->string('updated_at') ?? $details->string('created_at')) : null,
            metadata: [
                'payment_id' => $payment['id'] ?? null,
                'order_id' => $payment['order_id'] ?? null,
            ],
            provider: $this->getName(),
            channel: $details->string('source_type') ?? 'card',
            cardType: $details->string('card_details', 'card', 'card_brand'),
            customer: [
                'email' => $payment['buyer_email_address'] ?? null,
            ],
        );
    }

    /**
     * Validate the webhook signature.
     *
     * Square signs the notification URL followed by the raw body - HMAC
     * SHA-256 with the subscription's signature key, base64-encoded - and
     * sends it in `x-square-hmacsha256-signature`. The URL is part of what is
     * signed, so it has to be byte-for-byte the one registered in the Square
     * dashboard: see notificationUrl().
     *
     * This used to read the legacy `x-square-signature` header and sign the
     * body alone, which matches neither of Square's schemes, so no genuine
     * Square webhook could pass.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-square-hmacsha256-signature'][0]
            ?? $headers['X-Square-HmacSha256-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $webhookSignatureKey = $this->settings()->string('webhook_signature_key');

        if (! $webhookSignatureKey) {
            $this->log('warning', 'Webhook signature key not configured', [
                'hint' => 'Set SQUARE_WEBHOOK_SIGNATURE_KEY in your .env file. Get it from Square Dashboard → Developers → Webhooks → Select endpoint → Signature Key',
            ]);

            return false;
        }

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $this->notificationUrl().$body, $webhookSignatureKey, true)
        );

        $isValid = hash_equals($signature, $expectedSignature);

        if (! $isValid) {
            $this->log('warning', 'Webhook validation failed', [
                'hint' => 'Ensure SQUARE_WEBHOOK_SIGNATURE_KEY matches the signature key from your Square webhook endpoint, and that SQUARE_WEBHOOK_URL (or, if unset, this application\x27s webhook URL) is exactly the notification URL registered there - Square signs the URL along with the body.',
            ]);

            return false;
        }

        $payload = Payload::of(json_decode($body, true))->all();
        // the envelope's created_at, when Square created the event. Every retry repeats it, so the window is the replay window
        // sized to outlast retries, not the five-minute delivery tolerance.
        if (! $this->validateWebhookTimestamp($payload, $this->webhookReplayWindow())) {
            $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

            return false;
        }

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * The notification URL Square signs along with each webhook body.
     *
     * Square computes the signature over the URL registered for the webhook
     * subscription, so a mismatch in scheme, host, port, path or trailing
     * slash fails every delivery. SQUARE_WEBHOOK_URL pins it; without it, the
     * package's own route is used, which is right unless a proxy or a
     * different public hostname sits in front of the application.
     */
    protected function notificationUrl(): string
    {
        $configured = $this->config['webhook_url'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return route('payments.webhook', ['provider' => $this->getName()]);
    }

    /**
     * validateWebhook() rejects an event created longer ago than the replay
     * window, so records older than that can be pruned.
     */
    public function webhookReplayHorizon(): int
    {
        return $this->webhookReplayWindow();
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v2/locations');

            return $response->getStatusCode() === HttpStatusCodes::OK;
        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof ClientException) {
                $this->log('info', 'Health check successful (expected client-error response)', [
                    'error' => $e->getMessage(),
                ]);

                return true;
            }
            if ($previous instanceof ConnectException) {
                $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

                return false;
            }

            $this->log('error', 'Health check failed', ['error' => $e->getMessage(), 'error_class' => get_class($e)]);

            return true;
        }
    }

    /**
     * Extract payment reference from Square webhook payload.
     * Each provider has different webhook structures - this handles Square's format.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $data = Payload::of($payload)->at('data');

        return $data->string('object', 'payment', 'reference_id') ?? $data->string('id');
    }

    /**
     * Extract payment status from Square webhook payload.
     * Returns raw status - StatusNormalizer will convert to standard format.
     */
    public function extractWebhookStatus(array $payload): string
    {
        $body = new Payload($payload);

        return $body->string('data', 'object', 'payment', 'status') ?? $body->string('type') ?? 'unknown';
    }

    /**
     * Extract payment channel/method from Square webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        $sourceType = Payload::of($payload)->string('data', 'object', 'payment', 'source_type');

        return $sourceType !== '' ? $sourceType : null;
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
