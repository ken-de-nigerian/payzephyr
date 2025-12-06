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

/**
 * Driver implementation for the Monnify payment gateway.
 *
 * This driver handles the specific OAuth2-style authentication flow required
 * by Monnify, where an API Key and Secret are exchanged for a Bearer token.
 */
class MonnifyDriver extends AbstractDriver
{
    protected string $name = 'monnify';

    /**
     * Cached bearer token for API requests.
     */
    private ?string $accessToken = null;

    /**
     * Unix timestamp when the current access token expires.
     */
    private ?int $tokenExpiry = null;

    /**
     * Ensure the configuration contains the specific keys required for Monnify.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key']) || empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Monnify API key and secret key are required');
        }
        if (empty($this->config['contract_code'])) {
            throw new InvalidConfigurationException('Monnify contract code is required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
     * Monnify uses 'Idempotency-Key' header
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Retrieve a valid access token, generating a new one if necessary.
     *
     * Monnify requires Basic Auth (base64 encoded API Key + Secret) to obtain
     * a Bearer token.
     * This method caches the token until it expires (minus a 60s buffer).
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        try {
            $credentials = base64_encode($this->config['api_key'].':'.$this->config['secret_key']);
            $response = $this->makeRequest('POST', '/api/v1/auth/login', [
                'headers' => ['Authorization' => 'Basic '.$credentials],
            ]);
            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new ChargeException('Failed to authenticate with Monnify');
            }

            $this->accessToken = $data['responseBody']['accessToken'];
            $this->tokenExpiry = time() + ($data['responseBody']['expiresIn'] ?? 3600) - 60;

            return $this->accessToken;
        } catch (GuzzleException $e) {
            throw new ChargeException('Monnify authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Initialize a transaction on Monnify.
     *
     * Maps the standardized ChargeRequest to the Monnify 'init-transaction' payload.
     * Requires a 'contract_code' from configuration.
     */
    public function charge(ChargeRequest $request): ChargeResponse
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('MON');

            $payload = [
                'amount' => $request->amount,
                'customerName' => $request->customer['name'] ?? 'Customer',
                'customerEmail' => $request->email,
                'paymentReference' => $reference,
                'paymentDescription' => $request->description ?? 'Payment',
                'currencyCode' => $request->currency,
                'contractCode' => $this->config['contract_code'],
                'redirectUrl' => $request->callbackUrl ?? $this->config['callback_url'] ?? null,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
                'metadata' => $request->metadata,
            ];

            $response = $this->makeRequest('POST', '/api/v1/merchant/transactions/init-transaction', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new ChargeException($data['responseMessage'] ?? 'Failed to initialize Monnify transaction');
            }

            $result = $data['responseBody'];
            $this->log('info', 'Charge initialized successfully', ['reference' => $reference]);

            return new ChargeResponse(
                reference: $reference,
                authorizationUrl: $result['checkoutUrl'],
                accessCode: $result['transactionReference'],
                status: 'pending',
                metadata: $request->metadata,
                provider: $this->getName(),
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Monnify charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Verify a transaction status using the Monnify V2 API.
     */
    public function verify(string $reference): VerificationResponse
    {
        try {
            $response = $this->makeRequest('GET', "/api/v2/transactions/$reference", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new VerificationException($data['responseMessage'] ?? 'Failed to verify Monnify transaction');
            }

            $result = $data['responseBody'];

            return new VerificationResponse(
                reference: $result['paymentReference'] ?? $reference,
                status: $this->normalizeStatus($result['paymentStatus']),
                amount: (float) $result['amountPaid'],
                currency: $result['currencyCode'],
                paidAt: $result['paidOn'] ?? null,
                metadata: $result['metaData'] ?? [],
                provider: $this->getName(),
                channel: $result['paymentMethod'] ?? null,
                customer: [
                    'email' => $result['customerEmail'] ?? null,
                    'name' => $result['customerName'] ?? null,
                ],
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Verification failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            throw new VerificationException('Monnify verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the webhook signature.
     *
     * Monnify uses an HMAC SHA512 hash of the request body, signed with the Secret Key.
     * This hash must match the 'monnify-signature' header.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['monnify-signature'][0] ?? $headers['Monnify-Signature'][0] ?? null;
        if (! $signature) {
            return false;
        }
        $hash = hash_hmac('sha512', $body, $this->config['secret_key']);

        return hash_equals($signature, $hash);
    }

    /**
     * Check if the driver can successfully authenticate with the API.
     */
    public function healthCheck(): bool
    {
        try {
            $this->getAccessToken();
            return true;
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx means API is reachable
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Normalize Monnify-specific statuses to internal standard statuses.
     */
    private function normalizeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'OVERPAID' => 'success',
            'PENDING', 'PARTIALLY_PAID' => 'pending',
            'FAILED', 'CANCELLED', 'EXPIRED' => 'failed',
            default => strtolower($status),
        };
    }
}