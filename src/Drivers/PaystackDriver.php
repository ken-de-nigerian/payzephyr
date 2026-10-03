<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
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
use KenDeNigerian\PayZephyr\Traits\PaystackRefundMethods;
use KenDeNigerian\PayZephyr\Traits\PaystackSubscriptionMethods;
use Throwable;

/**
 * Driver implementation for the Paystack payment gateway.
 */
final class PaystackDriver extends AbstractDriver implements SupportsRefundsInterface, SupportsSubscriptionsInterface
{
    use PaystackRefundMethods;
    use PaystackSubscriptionMethods;

    protected string $name = 'paystack';

    /**
     * Make sure the Paystack secret key is configured.
     */
    protected function validateConfig(): void
    {
        if ($this->credential('secret_key') === null) {
            throw new InvalidConfigurationException('Paystack secret key is required');
        }
    }

    /**
     * Get the HTTP headers needed for Paystack API requests.
     * Paystack uses Bearer token authentication (your secret key).
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->settings()->string('secret_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Paystack uses the standard 'Idempotency-Key' header.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment on Paystack.
     *
     * @throws ChargeException If the payment creation fails.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference();

            $payload = [
                'email' => $request->email,
                'amount' => $request->getAmountInMinorUnits(),
                'currency' => $request->currency,
                'reference' => $reference,
                'callback_url' => $request->callbackUrl,
                'metadata' => $request->metadata,
            ];

            $channels = $this->mapChannels($request);
            if ($channels) {
                $payload['channels'] = $channels;
            }

            $response = $this->makeRequest('POST', '/transaction/initialize', [
                'json' => array_filter($payload),
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new ChargeException(
                    Payload::of($data)->string('message') ?? 'Failed to initialize Paystack transaction'
                );
            }

            $result = is_array($data['data'] ?? null) ? $data['data'] : [];

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $this->requireString($result, 'authorization_url', 'charge'),
                accessCode: $this->requireString($result, 'access_code', 'charge'),
                status: 'pending',
                metadata: $request->metadata,
                provider: $this->getName(),
            );
        } catch (ChargeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Charge failed', [
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);
            throw new ChargeException('Payment initialization failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Check if a Paystack payment was successful.
     *
     * @param  string  $reference  The transaction reference from Paystack
     *
     * @throws VerificationException If the payment can't be found or verified.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/transaction/verify/'.rawurlencode($reference));
            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new VerificationException(
                    Payload::of($data)->string('message') ?? 'Failed to verify Paystack transaction'
                );
            }

            $result = is_array($data['data'] ?? null) ? $data['data'] : [];
            $details = new Payload($result);

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $result['status'] ?? null,
            ]);

            return new VerificationResponseDTO(
                reference: $this->requireString($result, 'reference', 'verify'),
                status: $this->requireString($result, 'status', 'verify'),
                amount: $this->requireAmount($result, 'amount', 'verify') / 100,
                currency: $this->requireString($result, 'currency', 'verify'),
                paidAt: $details->string('paid_at'),
                metadata: self::normalizeMetadata($result['metadata'] ?? null),
                provider: $this->getName(),
                channel: $details->string('channel'),
                cardType: $details->string('authorization', 'card_type'),
                bank: $details->string('authorization', 'bank'),
                customer: [
                    'email' => $details->string('customer', 'email'),
                    'code' => $details->string('customer', 'customer_code'),
                ],
                authorizationCode: $details->string('authorization', 'authorization_code'),
            );
        } catch (VerificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);
            throw new VerificationException('Payment verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify that a webhook is really from Paystack (security check).
     *
     * Paystack signs webhooks using HMAC SHA512 with your secret key.
     * The signature comes in the 'x-paystack-signature' header.
     * This prevents fake webhooks from malicious actors.
     */
    /**
     * Validate webhook with timestamp check
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-paystack-signature'][0]
            ?? $headers['X-Paystack-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $hash = hash_hmac('sha512', $body, (string) $this->settings()->string('secret_key'));
        $signatureValid = hash_equals($signature, $hash);

        if (! $signatureValid) {
            $this->log('warning', 'Webhook signature invalid');

            return false;
        }

        // No payload replay window: nothing in a Paystack webhook says when the
        // event happened. data.created_at is when the transaction was
        // initialized, and a subscription event carries only the subscription's
        // own createdAt, so a window on either rejected real events. A replay is
        // byte-identical to a delivery already recorded and is stopped by
        // deduplication instead (ADR-0016, ADR-0017).

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * Paystack sends no event identifier, so there is none to return, and
     * ProcessWebhook keys the delivery on a hash of its body instead.
     *
     * The id this used to return was data.id - the transaction, subscription
     * or invoice an event is about, not the event. `subscription.create` and
     * `subscription.disable` for one subscription shared a key, so the
     * cancellation was dropped as a duplicate delivery.
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
     * Check if Paystack's API is working.
     *
     * Uses an invalid reference to test the API. A 400 Bad Request response
     * indicates the API is working correctly (it's responding as expected).
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/transaction/verify/invalid_ref_test');
            $statusCode = $response->getStatusCode();

            return ! HttpStatusCodes::isServerError($statusCode);
        } catch (Throwable $e) {
            $previous = $e->getPrevious();

            $clientException = null;
            $current = $e;
            while ($current instanceof \Throwable) {
                if ($current instanceof ClientException) {
                    $clientException = $current;
                    break;
                }
                $current = $current->getPrevious();
            }

            if ($clientException instanceof ClientException) {
                $response = $clientException->getResponse();
                $statusCode = $response->getStatusCode();
                if (in_array($statusCode, [HttpStatusCodes::BAD_REQUEST, HttpStatusCodes::NOT_FOUND], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)', [
                        'status_code' => $statusCode,
                    ]);

                    return true;
                }
            }

            $this->log('error', 'Health check failed', [
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'previous_class' => $previous instanceof Throwable ? $previous::class : null,
            ]);

            return false;
        }
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return Payload::of($payload)->string('data', 'reference');
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return Payload::of($payload)->string('data', 'status') ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return Payload::of($payload)->string('data', 'channel');
    }

    /**
     * Resolve the actual ID needed for verification.
     * Paystack verifies by the main transaction reference, not the access code.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $reference;
    }
}
