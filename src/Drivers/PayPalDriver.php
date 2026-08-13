<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;
use KenDeNigerian\PayZephyr\Traits\PayPalRefundMethods;
use KenDeNigerian\PayZephyr\Traits\PayPalSubscriptionMethods;
use Throwable;

/**
 * Driver implementation for the PayPal REST API (V2).
 *
 * Implements RequiresAsyncWebhookVerification: signature verification calls
 * PayPal's own API (two outbound HTTP calls - an OAuth token fetch, then the
 * verify-webhook-signature call), so it's deferred to the queued webhook job
 * instead of running synchronously in the request cycle.
 */
final class PayPalDriver extends AbstractDriver implements RequiresAsyncWebhookVerification, SupportsRefundsInterface, SupportsSubscriptionsInterface
{
    use PayPalRefundMethods;
    use PayPalSubscriptionMethods;

    protected string $name = 'paypal';

    /**
     * Cached OAuth2 access token.
     */
    private ?string $accessToken = null;

    /**
     * Timestamp when the current access token expires.
     */
    private ?int $tokenExpiry = null;

    /**
     * PayPal verification always calls PayPal's API - no local-only path
     * exists.
     */
    public function requiresAsyncVerification(): bool
    {
        return true;
    }

    /**
     * Ensure the configuration contains the Client ID and Secret.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['client_id']) || empty($this->config['client_secret'])) {
            throw new InvalidConfigurationException('PayPal client ID and secret are required');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    /**
     * PayPal uses 'PayPal-Request-Id' header instead of standard 'Idempotency-Key'.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['PayPal-Request-Id' => $key];
    }

    /**
     * Get the required decimal precision for a specific currency.
     *
     * PayPal is strict about amount formatting.
     * Zero-decimal currencies (like JPY) must be sent as integers,
     * while others (like USD) usually require two decimal places.
     *
     * @param  string  $currency  ISO currency code
     * @return int Number of decimal places (0 or 2)
     */
    private function getCurrencyDecimals(string $currency): int
    {
        $zeroDecimalCurrencies = [
            'BIF', // Burundian Franc
            'CLP', // Chilean Peso
            'DJF', // Djiboutian Franc
            'GNF', // Guinean Franc
            'JPY', // Japanese Yen
            'KMF', // Comorian Franc
            'KRW', // South Korean Won
            'MGA', // Malagasy Ariary
            'PYG', // Paraguayan Guaraní
            'RWF', // Rwandan Franc
            'UGX', // Ugandan Shilling
            'VND', // Vietnamese Dong
            'VUV', // Vanuatu Vatu
            'XAF', // Central African CFA Franc
            'XOF', // West African CFA Franc
            'XPF', // CFP Franc
        ];

        return in_array(strtoupper($currency), $zeroDecimalCurrencies) ? 0 : 2;
    }

    /**
     * Retrieve a valid Bearer token using Client Credentials flow.
     *
     * The token is cached in memory until it expires to reduce API overhead.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        try {
            $credentials = base64_encode($this->config['client_id'].':'.$this->config['client_secret']);
            $response = $this->makeRequest('POST', '/v1/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic '.$credentials,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['access_token'])) {
                throw new ChargeException('Failed to authenticate with PayPal');
            }

            $this->accessToken = $data['access_token'];
            $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;

            return $this->accessToken;
        } catch (Throwable $e) {
            $this->log('error', 'PayPal authentication failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new ChargeException('PayPal authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a generic Order in PayPal (V2 Checkout).
     *
     * Formats the amount based on currency precision and sets up the
     * application context (return/cancel URLs).
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('PAYPAL');
            if (empty($request->callbackUrl)) {
                throw new InvalidConfigurationException(
                    'PayPal requires a callback URL for its redirect flow. '.
                    'Please use ->callback() in your payment chain to set the callback URL.'
                );
            }

            $callback = $request->callbackUrl;

            $decimals = $this->getCurrencyDecimals($request->currency);

            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $reference,
                    'description' => $request->description ?? 'Payment',
                    'amount' => [
                        'currency_code' => $request->currency,
                        'value' => number_format($request->amount, $decimals, '.', ''),
                    ],
                    'custom_id' => $reference,
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'brand_name' => $this->config['brand_name'] ?? 'Your Store',
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'landing_page' => 'GUEST_CHECKOUT',
                            'user_action' => 'PAY_NOW',
                            'return_url' => $this->appendQueryParam($callback, 'reference', $reference),
                            'cancel_url' => $this->appendQueryParam($callback, 'reference', $reference),
                        ],
                    ],
                ],
            ];

            $response = $this->makeRequest('POST', '/v2/checkout/orders', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new ChargeException('Failed to create PayPal order');
            }

            /** @var array<int, array<string, mixed>> $links */
            $links = $data['links'] ?? [];
            $approveLink = collect($links)->firstWhere('rel', 'approve')
                ?? collect($links)->firstWhere('rel', 'payer-action');

            if (! $approveLink) {
                throw new ChargeException('No approval link found in PayPal response');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'order_id' => $data['id'],
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $approveLink['href'],
                accessCode: $data['id'],
                status: $this->normalizeStatus($data['status']),
                metadata: [
                    'order_id' => $data['id'],
                    'links' => $data['links'] ?? [],
                ],
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
     * Verify the status of a specific Order ID.
     *
     * Note: PayPal 'verification' usually involves checking the Order details
     * to see if the funds have been CAPTURED or COMPLETED.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {

            $response = $this->makeRequest('GET', "/v2/checkout/orders/$reference", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new VerificationException("PayPal order not found: $reference");
            }

            $status = strtoupper($data['status']);
            $purchaseUnit = $data['purchase_units'][0] ?? [];
            $amount = $purchaseUnit['amount'] ?? [];

            $captures = $purchaseUnit['payments']['captures'] ?? [];
            $capture = $captures[0] ?? null;

            if ($status === 'APPROVED' && empty($capture)) {
                $capture = $this->captureOrder($reference);
                $status = 'COMPLETED';
            } elseif ($capture && isset($capture['status'])) {
                $captureStatus = strtoupper($capture['status']);
                if ($captureStatus === 'PENDING') {
                    $status = 'APPROVED';
                } elseif ($captureStatus === 'COMPLETED') {
                    $status = 'COMPLETED';
                }
            }

            return new VerificationResponseDTO(
                reference: $purchaseUnit['custom_id'] ?? $reference,
                status: $this->normalizeStatus($status),
                amount: isset($amount['value']) ? (float) $amount['value'] : 0,
                currency: $amount['currency_code'] ?? 'USD',
                paidAt: $capture['create_time'] ?? null,
                metadata: [
                    'order_id' => $data['id'],
                    'capture_id' => $capture['id'] ?? null,
                    'raw' => $data,
                ],
                provider: $this->getName(),
                customer: [
                    'email' => $data['payer']['email_address'] ?? null,
                    'name' => $data['payer']['name']['given_name'] ?? null,
                ],
            );

        } catch (VerificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Verification failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new VerificationException('Payment verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate PayPal webhook signature.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $transmissionId = $headers['paypal-transmission-id'][0] ?? null;
        $transmissionTime = $headers['paypal-transmission-time'][0] ?? null;
        $certUrl = $headers['paypal-cert-url'][0] ?? null;
        $authAlgo = $headers['paypal-auth-algo'][0] ?? null;
        $transmissionSig = $headers['paypal-transmission-sig'][0] ?? null;
        $webhookId = $this->config['webhook_id'] ?? null;

        if (! $transmissionId || ! $transmissionTime || ! $certUrl || ! $authAlgo || ! $transmissionSig || ! $webhookId) {
            $this->log('warning', 'PayPal webhook missing required headers', [
                'has_transmission_id' => (bool) $transmissionId,
                'has_transmission_time' => (bool) $transmissionTime,
                'has_cert_url' => (bool) $certUrl,
                'has_auth_algo' => (bool) $authAlgo,
                'has_transmission_sig' => (bool) $transmissionSig,
                'has_webhook_id' => (bool) $webhookId,
            ]);

            return false;
        }

        try {
            $isValid = $this->verifyWebhookSignatureViaAPI(
                $transmissionId,
                $transmissionTime,
                $certUrl,
                $authAlgo,
                $transmissionSig,
                $webhookId,
                $body
            );

            if (! $isValid) {
                return false;
            }

            $payload = json_decode($body, true) ?? [];
            if (! $this->validateWebhookTimestamp($payload)) {
                $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->log('error', 'PayPal webhook validation failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify webhook signature using PayPal's Webhook Verification API.
     *
     * This is the recommended approach as it delegates the complex certificate
     * validation to PayPal's servers.
     */
    private function verifyWebhookSignatureViaAPI(
        string $transmissionId,
        string $transmissionTime,
        string $certUrl,
        string $authAlgo,
        string $transmissionSig,
        string $webhookId,
        string $body
    ): bool {
        try {
            $response = $this->makeRequest('POST', '/v1/notifications/verify-webhook-signature', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => [
                    'transmission_id' => $transmissionId,
                    'transmission_time' => $transmissionTime,
                    'cert_url' => $certUrl,
                    'auth_algo' => $authAlgo,
                    'transmission_sig' => $transmissionSig,
                    'webhook_id' => $webhookId,
                    'webhook_event' => json_decode($body, true),
                ],
            ]);

            $data = $this->parseResponse($response);

            $isValid = ($data['verification_status'] ?? '') === 'SUCCESS';

            $this->log($isValid ? 'info' : 'warning', 'PayPal webhook validation result', [
                'valid' => $isValid,
                'status' => $data['verification_status'] ?? 'unknown',
            ]);

            return $isValid;
        } catch (Throwable $e) {
            $this->log('error', 'PayPal webhook verification API failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new WebhookException(
                'PayPal webhook verification failed: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Check API connectivity by attempting to generate an access token.
     */
    public function healthCheck(): bool
    {
        try {
            $this->getAccessToken();

            return true;

        } catch (ChargeException $e) {
            $previous = $e;
            while ($previous = $previous->getPrevious()) {
                if ($previous instanceof ClientException) {
                    return true;
                }
            }

            return false;

        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws VerificationException
     */
    private function captureOrder(string $orderId): ?array
    {
        try {
            $response = $this->makeRequest('POST', "/v2/checkout/orders/$orderId/capture", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);

            $data = $this->parseResponse($response);

            return $data['purchase_units'][0]['payments']['captures'][0] ?? null;

        } catch (Throwable $e) {
            $this->log('error', 'PayPal capture failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new VerificationException('PayPal capture failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['resource']['custom_id']
            ?? $payload['resource']['purchase_units'][0]['custom_id']
            ?? null;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['resource']['status'] ?? $payload['event_type'] ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        // PayPal reports the instrument the payer actually used under
        // resource.payment_source, keyed by type ({"card": {...}},
        // {"paypal": {...}}, {"venmo": {...}}, ...). This previously returned
        // a hardcoded 'paypal' without reading the payload at all, which both
        // duplicated the provider name and erased which instrument was used.
        //
        // Null when the field is absent: the channel is genuinely unknown
        // then, and inventing one would record a funding source PayPal never
        // reported.
        $paymentSource = $payload['resource']['payment_source'] ?? null;

        if (! is_array($paymentSource) || $paymentSource === []) {
            return null;
        }

        $type = array_key_first($paymentSource);

        return is_string($type) ? $type : null;
    }
}
