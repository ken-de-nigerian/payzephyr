<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Throwable;

/**
 * Driver implementation for the Flutterwave payment gateway.
 */
final class FlutterwaveDriver extends AbstractDriver
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
     * @throws ChargeException If the API request fails or returns an error status.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('FLW');

            $payload = [
                'tx_ref' => $reference,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'redirect_url' => $this->appendQueryParam(
                    $request->callbackUrl,
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

            $channels = $this->mapChannels($request);
            if ($channels) {
                $payload['payment_options'] = $channels;
            }

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
     * Validate the webhook signature.
     *
     * Flutterwave uses a "Secret Hash" mechanism. The value sent in the
     * 'verif-hash' header must exactly match the Secret Hash configured in the
     * Flutterwave dashboard (and stored in our config).
     *
     * The Secret Hash is a simple string comparison - no cryptographic hashing is involved.
     * It's just a shared secret that you configure in both Flutterwave dashboard and your app.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['verif-hash'][0] ?? $headers['Verif-Hash'][0] ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature (verif-hash) missing', [
                'available_headers' => array_keys($headers),
                'hint' => 'Flutterwave webhooks must include the "verif-hash" header',
            ]);

            return false;
        }

        $secretHash = $this->config['webhook_secret'] ?? $this->config['secret_key'] ?? null;

        if (empty($secretHash)) {
            $this->log('warning', 'Webhook secret hash not configured', [
                'hint' => 'Set FLUTTERWAVE_WEBHOOK_SECRET in your .env file, or ensure FLUTTERWAVE_SECRET_KEY is set. Get the Secret Hash from Flutterwave Dashboard → Settings → Webhooks → Secret Hash',
            ]);

            return false;
        }

        $isValid = hash_equals($signature, $secretHash);

        if (! $isValid) {
            $this->log('warning', 'Webhook validation failed', [
                'reason' => 'Secret hash mismatch',
                'hint' => 'The verif-hash header does not match your configured secret. Ensure FLUTTERWAVE_WEBHOOK_SECRET (or FLUTTERWAVE_SECRET_KEY) matches the Secret Hash in Flutterwave Dashboard → Settings → Webhooks',
                'received_hash_length' => strlen($signature),
                'expected_hash_length' => strlen($secretHash),
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

    /**
     * Check API connectivity by querying the Banks endpoint.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', 'banks/NG');
            $statusCode = $response->getStatusCode();

            return $statusCode < 500;

        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if (
                ($e instanceof PaymentException)
                && ($previous instanceof ClientException)
            ) {
                $response = $previous->getResponse();
                $statusCode = $response->getStatusCode();
                if (in_array($statusCode, [400, 404], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)');

                    return true;
                }
            }

            return false;
        }
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['data']['tx_ref'] ?? null;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['data']['status'] ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['payment_type'] ?? null;
    }

    /**
     * Resolve the actual ID needed for verification.
     * Flutterwave verifies by tx_ref, not the internal access code.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $reference;
    }
}
