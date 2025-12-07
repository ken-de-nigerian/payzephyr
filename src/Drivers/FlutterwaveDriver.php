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

/**
 * Driver implementation for the Flutterwave payment gateway.
 *
 * This driver handles standard payments via the Standard Payment Link (v3) API
 * and verifies transactions using the unique transaction reference (tx_ref).
 */
class FlutterwaveDriver extends AbstractDriver
{
    protected string $name = 'flutterwave';

    /**
     * Ensure the configuration contains the Secret Key.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Flutterwave secret key is required');
        }
    }

    /**
     * Get the default headers for API requests.
     *
     * Flutterwave uses Bearer Token authentication with the Secret Key.
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['secret_key'],
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Flutterwave uses standard 'Idempotency-Key' header
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Initialize a charge using the Flutterwave Standard Payment Link.
     *
     * Maps the internal ChargeRequestDTO to Flutterwave's payload structure,
     * specifically mapping 'reference' to 'tx_ref' and 'metadata' to 'meta'.
     *
     * @throws ChargeException If the API request fails or returns an error status.
     * @throws RandomException If reference generation fails.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        // Store request so AbstractDriver can access idempotency key
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('FLW');

            $payload = [
                'tx_ref' => $reference,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'redirect_url' => $this->appendQueryParam(
                    $request->callbackUrl ?? $this->config['callback_url'] ?? null,
                    'reference',
                    $reference
                ),
                'customer' => [
                    'email' => $request->email,
                    'name' => $request->customer['name'] ?? 'Customer',
                ],
                'customizations' => [
                    'title' => $request->description ?? 'Payment',
                    'description' => $request->description ?? 'Payment for services',
                ],
                'meta' => $request->metadata,
            ];

            $response = $this->makeRequest('POST', 'payments', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new ChargeException(
                    $data['message'] ?? 'Failed to initialize Flutterwave transaction'
                );
            }

            $result = $data['data'];

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $result['link'],
                accessCode: $reference,
                status: 'pending',
                metadata: $request->metadata,
                provider: $this->getName(),
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Flutterwave charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            // Always clear context to prevent memory leaks
            $this->clearCurrentRequest();
        }
    }

    /**
     * Verify a payment using the Transaction Reference (tx_ref).
     *
     * Note: This uses the 'transactions/verify_by_reference' endpoint rather
     * than the standard ID-based verification.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', 'transactions/verify_by_reference', [
                'query' => ['tx_ref' => $reference],
            ]);

            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new VerificationException(
                    $data['message'] ?? 'Failed to verify Flutterwave transaction'
                );
            }

            $result = $data['data'];

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $result['status'],
            ]);

            return new VerificationResponseDTO(
                reference: $result['tx_ref'],
                status: $this->normalizeStatus($result['status']),
                amount: (float) $result['amount'],
                currency: $result['currency'],
                paidAt: $result['created_at'] ?? null,
                metadata: $result['meta'] ?? [],
                provider: $this->getName(),
                channel: $result['payment_type'] ?? null,
                cardType: $result['card']['type'] ?? null,
                bank: $result['card']['issuer'] ?? null,
                customer: [
                    'email' => $result['customer']['email'] ?? null,
                    'name' => $result['customer']['name'] ?? null,
                ],
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'Verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            throw new VerificationException('Flutterwave verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the webhook signature.
     *
     * Flutterwave uses a "Secret Hash" mechanism. The value sent in the
     * 'verif-hash' header must exactly match the Secret Hash configured in the
     * Flutterwave dashboard (and stored in our config).
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['verif-hash'][0] ?? $headers['Verif-Hash'][0] ?? null;
        if (! $signature) {
            return false;
        }
        $secretHash = $this->config['webhook_secret'] ?? $this->config['secret_key'];

        return hash_equals($signature, $secretHash);
    }

    /**
     * Check API connectivity by querying the Banks endpoint.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', 'banks/NG');

            return $response->getStatusCode() === 200;

        } catch (ClientException) {
            // 4xx means API is reachable
            return true;

        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Normalize Flutterwave-specific statuses to internal standard statuses.
     */
    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'successful' => 'success',
            'failed' => 'failed',
            'pending' => 'pending',
            default => $status,
        };
    }
}
