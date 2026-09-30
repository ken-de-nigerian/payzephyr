<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\MonnifyRefundMethods;
use Throwable;

/**
 * Driver implementation for the Monnify payment gateway.
 */
final class MonnifyDriver extends AbstractDriver implements SupportsRefundsInterface
{
    use MonnifyRefundMethods;

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

    /**
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
     * Monnify uses 'Idempotency-Key' header
     *
     * @return array<string, string>
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
            $credentials = base64_encode($this->settings()->string('api_key').':'.$this->settings()->string('secret_key'));
            $response = $this->makeRequest('POST', '/api/v1/auth/login', [
                'headers' => ['Authorization' => 'Basic '.$credentials],
            ]);
            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new ChargeException('Failed to authenticate with Monnify');
            }

            $token = Payload::of($data)->string('responseBody', 'accessToken');

            if (! is_string($token) || $token === '') {
                throw new ChargeException('Monnify reported a successful login but returned no access token');
            }

            $this->accessToken = $token;
            $this->tokenExpiry = time() + (Payload::of($data)->int('responseBody', 'expiresIn') ?? 3600) - 60;

            return $token;
        } catch (Throwable $e) {
            $this->log('error', 'Monnify authentication failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new ChargeException('Monnify authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Initialize a transaction on Monnify.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
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
                'redirectUrl' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'metadata' => $request->metadata,
            ];

            $channels = $this->mapChannels($request);
            if ($channels) {
                $payload['paymentMethods'] = $channels;
            }

            $response = $this->makeRequest('POST', '/api/v1/merchant/transactions/init-transaction', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new ChargeException(Payload::of($data)->string('responseMessage') ?? 'Failed to initialize Monnify transaction');
            }

            $result = $this->requireArray($data, 'responseBody', 'charge');
            $this->log('info', 'Charge initialized successfully', ['reference' => $reference]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $this->requireString($result, 'checkoutUrl', 'charge'),
                accessCode: $this->requireString($result, 'transactionReference', 'charge'),
                status: 'pending',
                metadata: $request->metadata,
                provider: $this->getName(),
            );
        } catch (ChargeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Monnify charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Verify a transaction status using the Monnify V2 API.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        $cleanReference = explode('?', $reference)[0];

        try {
            $response = $this->makeRequest('GET', '/api/v2/merchant/transactions/query?paymentReference='.rawurlencode($cleanReference), [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new VerificationException(Payload::of($data)->string('responseMessage') ?? 'Failed to verify Monnify transaction');
            }

            $result = $this->requireArray($data, 'responseBody', 'verify');
            $details = new Payload($result);

            return new VerificationResponseDTO(
                reference: $details->string('paymentReference') ?? $reference,
                status: $this->normalizeStatus($this->requireString($result, 'paymentStatus', 'verify')),
                amount: $this->requireAmount($result, 'amountPaid', 'verify'),
                currency: $this->requireString(
                    ['currency' => $details->string('currency') ?? $details->string('currencyCode')],
                    'currency',
                    'verify',
                ),
                paidAt: $details->string('paidOn'),
                metadata: self::normalizeMetadata($details->get('metaData')),
                provider: $this->getName(),
                channel: $details->string('paymentMethod'),
                customer: [
                    'email' => $details->string('customer', 'email'),
                    'name' => $details->string('customer', 'name'),
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
     * Monnify uses an HMAC SHA512 hash of the request body, signed with the Secret Key.
     * This hash must match the 'monnify-signature' header.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['monnify-signature'][0] ?? $headers['Monnify-Signature'][0] ?? null;
        if (! $signature) {
            return false;
        }
        $hash = hash_hmac('sha512', $body, (string) $this->settings()->string('secret_key'));
        $signatureValid = hash_equals($signature, $hash);

        if (! $signatureValid) {
            return false;
        }

        // No payload replay window: no field in Monnify's payloads is confirmed
        // to be the time of the event rather than of the payment or refund it
        // concerns. A replay is stopped by deduplication instead (ADR-0017).

        return true;
    }

    /**
     * Monnify sends no event identifier, so there is none to return, and
     * ProcessWebhook keys the delivery on a hash of its body instead.
     *
     * The id this used to return was eventData.transactionReference - the
     * transaction an event is about, not the event. A payment and every refund
     * against it shared a key, so the refund's outcome was dropped as a
     * duplicate delivery.
     *
     * A retry or a replay is byte-identical to the original, so the body
     * hash still catches both.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        return null;
    }

    /**
     * Check if the driver can successfully authenticate with the API.
     */
    public function healthCheck(): bool
    {
        try {
            $this->getAccessToken();

            return true;

        } catch (Throwable $e) {
            $previous = $e;
            while ($previous = $previous->getPrevious()) {
                if ($previous instanceof ClientException) {
                    return true;
                }
            }

            $this->log('error', 'Health check failed', ['error' => $e->getMessage(), 'error_class' => get_class($e)]);

            return false;
        }
    }

    /**
     * The part of a webhook body that describes the transaction.
     *
     * Monnify wraps it in `eventData` alongside an `eventType`. Its older
     * format sent the same fields at the top level, so a body without
     * `eventData` is read as it is. The three extractors below used to read
     * only the top level, so for a current-format webhook the reference came
     * back null and the status "unknown", and the transaction was never
     * updated.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function webhookEventData(array $payload): Payload
    {
        $body = new Payload($payload);

        return $body->has('eventData') ? $body->at('eventData') : $body;
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $event = $this->webhookEventData($payload);

        return $event->string('paymentReference') ?? $event->string('transactionReference');
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $this->webhookEventData($payload)->string('paymentStatus') ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $this->webhookEventData($payload)->string('paymentMethod');
    }

    /**
     * Resolve the actual ID needed for verification.
     * Monnify can use either reference or provider ID.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $providerId;
    }
}
