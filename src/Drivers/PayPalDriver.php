<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;

/**
 * Driver implementation for the PayPal REST API (V2).
 *
 * This driver utilizes the 'Checkout Orders' API to create and capture payments.
 * It handles the OAuth2 Client Credentials flow for authentication.
 */
class PayPalDriver extends AbstractDriver
{
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
     * Ensure the configuration contains the Client ID and Secret.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['client_id']) || empty($this->config['client_secret'])) {
            throw new InvalidConfigurationException('PayPal client ID and secret are required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
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
        } catch (GuzzleException $e) {
            throw new ChargeException('PayPal authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a generic Order in PayPal (V2 Checkout).
     *
     * Formats the amount based on currency precision and sets up the
     * application context (return/cancel URLs).
     */
    public function charge(ChargeRequest $request): ChargeResponse
    {
        try {
            $reference = $request->reference ?? $this->generateReference('PAYPAL');
            $callback = $request->callbackUrl ?? $this->config['callback_url'] ?? null;

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

            $headers = ['Authorization' => 'Bearer '.$this->getAccessToken()];

            // Add idempotency key if provided
            if ($request->idempotencyKey) {
                $headers['PayPal-Request-Id'] = $request->idempotencyKey;
            }

            $response = $this->makeRequest('POST', '/v2/checkout/orders', [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new ChargeException('Failed to create PayPal order');
            }

            // Check for 'approve' OR 'payer-action'
            $approveLink = collect($data['links'] ?? [])->firstWhere('rel', 'approve')
                ?? collect($data['links'] ?? [])->firstWhere('rel', 'payer-action');

            if (! $approveLink) {
                throw new ChargeException('No approval link found in PayPal response');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'order_id' => $data['id'],
            ]);

            return new ChargeResponse(
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
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('PayPal charge failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify the status of a specific Order ID.
     *
     * Note: PayPal 'verification' usually involves checking the Order details
     * to see if the funds have been CAPTURED or COMPLETED.
     */
    public function verify(string $reference): VerificationResponse
    {
        try {
            // Note: $reference here is expected to be the PayPal Order ID (accessCode),
            // not necessarily the merchant's local reference.
            $response = $this->makeRequest('GET', "/v2/checkout/orders/$reference", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);

            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new VerificationException('PayPal order not found');
            }

            $purchaseUnit = $data['purchase_units'][0] ?? [];
            $amount = $purchaseUnit['amount'] ?? [];
            $payments = $purchaseUnit['payments']['captures'][0] ?? null;

            return new VerificationResponse(
                reference: $purchaseUnit['custom_id'] ?? $reference,
                status: $this->normalizeStatus($data['status']),
                amount: (float) ($amount['value'] ?? 0),
                currency: $amount['currency_code'] ?? 'USD',
                paidAt: $payments['create_time'] ?? null,
                metadata: [
                    'order_id' => $data['id'],
                    'capture_id' => $payments['id'] ?? null,
                ],
                provider: $this->getName(),
                customer: [
                    'email' => $data['payer']['email_address'] ?? null,
                    'name' => $data['payer']['name']['given_name'] ?? null,
                ],
            );
        } catch (GuzzleException $e) {
            throw new VerificationException('PayPal verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate PayPal webhook signature.
     *
     * PayPal uses a complex signature verification process involving:
     * 1. Certificate verification from PayPal's cert URL
     * 2. CRC32 checksum validation
     * 3. Signature validation using the public certificate
     *
     * @link https://developer.paypal.com/api/rest/webhooks/
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        // Extract required headers
        $transmissionId = $headers['paypal-transmission-id'][0] ?? null;
        $transmissionTime = $headers['paypal-transmission-time'][0] ?? null;
        $certUrl = $headers['paypal-cert-url'][0] ?? null;
        $authAlgo = $headers['paypal-auth-algo'][0] ?? null;
        $transmissionSig = $headers['paypal-transmission-sig'][0] ?? null;
        $webhookId = $this->config['webhook_id'] ?? null;

        // Validate all required headers are present
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
            // Use PayPal's official verification API
            return $this->verifyWebhookSignatureViaAPI(
                $transmissionId,
                $transmissionTime,
                $certUrl,
                $authAlgo,
                $transmissionSig,
                $webhookId,
                $body
            );
        } catch (Exception $e) {
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
     *
     * @link https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature
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
        } catch (GuzzleException $e) {
            // If API verification fails, log and reject webhook
            $this->log('error', 'PayPal webhook verification API failed', [
                'error' => $e->getMessage(),
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

        } catch (ClientException) {
            // 4xx means API is reachable
            return true;

        } catch (Exception) {
            return false;
        }
    }

    /**
     * Normalize PayPal V2 statuses to internal standard statuses.
     */
    private function normalizeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'success',
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED' => 'pending',
            'VOIDED', 'CANCELLED' => 'cancelled',
            default => strtolower($status),
        };
    }
}
