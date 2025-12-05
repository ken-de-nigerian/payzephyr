<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

class PayPalDriver extends AbstractDriver
{
    protected string $name = 'paypal';

    private ?string $accessToken = null;

    private ?int $tokenExpiry = null;

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

    private function getCurrencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), ['JPY', 'KRW', 'VND']) ? 0 : 2;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        try {
            $credentials = base64_encode($this->config['client_id'].':'.$this->config['client_secret']);
            $response = $this->makeRequest('POST', '/v1/oauth2/token', [
                'headers' => ['Authorization' => 'Basic '.$credentials, 'Content-Type' => 'application/x-www-form-urlencoded'],
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

    public function charge(ChargeRequest $request): ChargeResponse
    {
        try {
            $reference = $request->reference ?? $this->generateReference('PAYPAL');
            $callback = $request->callbackUrl ?? $this->config['callback_url'] ?? null;

            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $reference,
                    'description' => $request->description ?? 'Payment',
                    'amount' => [
                        'currency_code' => $request->currency,
                        // Dynamic decimals
                        'value' => number_format(
                            $request->amount,
                            $this->getCurrencyDecimals($request->currency),
                            '.',
                            ''
                        ),
                    ],
                    'custom_id' => $reference,
                ]],
                'application_context' => [
                    'return_url' => $callback,
                    'cancel_url' => $callback,
                    'brand_name' => $this->config['brand_name'] ?? 'Your Store',
                    'user_action' => 'PAY_NOW',
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'user_action' => 'PAY_NOW',
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

            $approveLink = collect($data['links'] ?? [])->firstWhere('rel', 'approve');
            $this->log('info', 'Charge initialized successfully', ['reference' => $reference, 'order_id' => $data['id']]);

            return new ChargeResponse(
                reference: $reference,
                authorizationUrl: $approveLink['href'] ?? '',
                accessCode: $data['id'],
                status: $this->normalizeStatus($data['status']),
                metadata: ['order_id' => $data['id'], 'links' => $data['links'] ?? []],
                provider: $this->getName(),
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('PayPal charge failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function verify(string $reference): VerificationResponse
    {
        try {
            $response = $this->makeRequest('GET', "/v2/checkout/orders/$reference", ['headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()]]);
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
                metadata: ['order_id' => $data['id'], 'capture_id' => $payments['id'] ?? null],
                provider: $this->getName(),
                customer: ['email' => $data['payer']['email_address'] ?? null, 'name' => $data['payer']['name']['given_name'] ?? null],
            );
        } catch (GuzzleException $e) {
            throw new VerificationException('PayPal verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function validateWebhook(array $headers, string $body): bool
    {
        return true;
    }

    public function healthCheck(): bool
    {
        try {
            $this->getAccessToken();

            return true;
        } catch (Exception) {
            return false;
        }
    }

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
