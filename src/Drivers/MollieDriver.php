<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\SendsStatelessWebhooks;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\MollieRefundMethods;
use KenDeNigerian\PayZephyr\Traits\MollieSubscriptionMethods;
use Throwable;

/**
 * Driver implementation for the Mollie payment gateway.
 *
 * Implements RequiresAsyncWebhookVerification conditionally: when no
 * webhook_secret is configured, validateWebhook() falls back to an
 * outbound API call (validateWebhookViaAPI()) and must be deferred to the
 * queued webhook job.
 */
final class MollieDriver extends AbstractDriver implements RequiresAsyncWebhookVerification, SendsStatelessWebhooks, SupportsRefundsInterface, SupportsSubscriptionsInterface
{
    use MollieRefundMethods;
    use MollieSubscriptionMethods;

    protected string $name = 'mollie';

    /**
     * Only the no-webhook_secret (API-fallback) configuration performs
     * synchronous I/O during verification.
     */
    public function requiresAsyncVerification(): bool
    {
        return $this->credential('webhook_secret') === null;
    }

    /**
     * A classic Mollie webhook is a payment id and nothing else - the same
     * body for paid, refunded, charged back and expired - so it cannot be
     * deduplicated without dropping every status change after the first.
     * A typed event (anything with a `type`, such as `hook.ping`) carries its
     * own event id and is deduplicated normally.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function isStatelessWebhook(array $payload): bool
    {
        return ! isset($payload['type']);
    }

    /**
     * Ensure the configuration contains the required API key.
     *
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(): void
    {
        if ($this->credential('api_key') === null) {
            throw new InvalidConfigurationException('Mollie API key is required');
        }
    }

    /**
     * Get the default HTTP headers needed for Mollie API requests.
     *
     * Mollie uses Bearer token authentication with the API key.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->settings()->string('api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Mollie uses standard 'Idempotency-Key' header.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Initialize a payment with Mollie.
     *
     * Mollie uses a redirect-based flow where customers are sent to a hosted
     * payment page to complete their payment.
     *
     * @param  ChargeRequestDTO  $request  Payment request details
     * @return ChargeResponseDTO Payment response with redirect URL
     *
     * @throws ChargeException If payment initialization fails
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('MOLLIE');

            $payload = [
                'amount' => [
                    'currency' => $request->currency,
                    'value' => $this->formatAmount($request->amount, $request->currency),
                ],
                'description' => $request->description ?? 'Payment',
                'redirectUrl' => $this->appendQueryParam(
                    $request->callbackUrl,
                    'reference',
                    $reference
                ),
                'metadata' => array_merge($request->metadata, [
                    'reference' => $reference,
                ]),
            ];

            $methods = $this->mapChannels($request);
            if ($methods) {
                $payload['method'] = $methods;
            }

            $response = $this->makeRequest('POST', '/v2/payments', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            $checkoutUrl = Payload::of($data)->string('_links', 'checkout', 'href');
            if (! $checkoutUrl) {
                throw new ChargeException('No checkout URL returned by Mollie');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'mollie_id' => $data['id'],
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $checkoutUrl,
                accessCode: $this->requireString($data, 'id', 'charge'),
                status: $this->normalizeStatus($this->requireString($data, 'status', 'charge')),
                metadata: array_merge($request->metadata, [
                    'mollie_id' => $data['id'],
                    'reference' => $reference,
                ]),
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
     * Verify a payment by retrieving its details from Mollie.
     *
     * @param  string  $reference  The Mollie payment ID or our internal reference
     * @return VerificationResponseDTO Payment verification details
     *
     * @throws VerificationException If verification fails
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $paymentId = $reference;

            $response = $this->makeRequest('GET', '/v2/payments/'.rawurlencode($paymentId));
            $data = $this->parseResponse($response);
            $details = new Payload($data);
            $status = $this->requireString($data, 'status', 'verify');
            $amount = $this->requireArray($data, 'amount', 'verify');

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $status,
            ]);

            $actualReference = $details->string('metadata', 'reference') ?? $reference;

            return new VerificationResponseDTO(
                reference: $actualReference,
                status: $this->normalizeStatus($status),
                amount: $this->requireAmount($amount, 'value', 'verify'),
                currency: $this->requireString($amount, 'currency', 'verify'),
                paidAt: $details->string('paidAt'),
                metadata: self::normalizeMetadata($data['metadata'] ?? null),
                provider: $this->getName(),
                channel: $details->string('method'),
                cardType: $details->string('details', 'cardLabel'),
                bank: $details->string('details', 'consumerName'),
                customer: [
                    'email' => $details->string('billingAddress', 'email'),
                    'name' => $details->string('billingAddress', 'givenName'),
                ],
            );
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
     * Validate Mollie webhook.
     *
     * @param  array<string, array<string>>  $headers  Request headers
     * @param  string  $body  Raw request body
     * @return bool True if webhook is valid
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        if ($this->credential('webhook_secret') !== null) {
            return $this->validateWebhookSignature($headers, $body);
        }

        return $this->validateWebhookViaAPI($body);
    }

    /**
     * Validate webhook using HMAC SHA-256 signature.
     *
     * Mollie signs webhooks using HMAC SHA-256 with the webhook secret.
     * The signature comes in the 'X-Mollie-Signature' header.
     *
     * @param  array<string, array<string>>  $headers  Request headers
     * @param  string  $body  Raw request body
     * @return bool True if signature is valid
     */
    protected function validateWebhookSignature(array $headers, string $body): bool
    {
        $signature = $headers['x-mollie-signature'][0]
            ?? $headers['X-Mollie-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing', [
                'hint' => 'Mollie webhooks should include the X-Mollie-Signature header when webhook_secret is configured',
            ]);

            return false;
        }

        $signature = str_replace('sha256=', '', $signature);
        $expectedSignature = hash_hmac('sha256', $body, $this->requiredCredential('webhook_secret'));
        $isValid = hash_equals($signature, $expectedSignature);

        if (! $isValid) {
            $this->log('warning', 'Webhook signature validation failed', [
                'hint' => 'The X-Mollie-Signature header does not match. Ensure MOLLIE_WEBHOOK_SECRET matches the webhook secret from your Mollie dashboard.',
            ]);

            return false;
        }

        $event = Payload::of(json_decode($body, true));

        $eventType = $event->string('type');
        if ($eventType === 'hook.ping') {
            $this->log('info', 'Webhook validated successfully (hook.ping test event)', [
                'event_id' => $event->string('id'),
            ]);

            return true;
        }

        $this->log('info', 'Webhook validated successfully via signature verification', [
            'event_type' => $eventType,
        ]);

        return true;
    }

    /**
     * Validate webhook by fetching payment details from API (fallback method).
     *
     * This method is used when webhook_secret is not configured.
     * It fetches the payment from Mollie's API to verify it exists and is legitimate.
     *
     * There is no replay window here. The body is only a payment id, and
     * everything acted on is fetched from Mollie's authenticated API, so a
     * replayed ping can do no more than re-read the payment's current state.
     * The fetched Payment's `createdAt` is when the payment was created, not
     * when this event happened: checking it rejected every payment paid,
     * expired or refunded more than five minutes after it was created.
     *
     * @param  string  $body  Raw request body
     * @return bool True if webhook is valid
     *
     * @throws WebhookException When Mollie could not be asked (network failure,
     *                          5xx, rate limit, our API key rejected). This runs
     *                          in the queued job, where false would discard a
     *                          genuine delivery; throwing lets the job retry.
     */
    protected function validateWebhookViaAPI(string $body): bool
    {
        try {
            $event = Payload::of(json_decode($body, true));

            if ($event->all() === []) {
                $this->log('warning', 'Webhook payload is invalid JSON');

                return false;
            }

            // A typed event - hook.ping, or any of Mollie's next-gen webhooks -
            // is always signed, and there is no secret here to check it with.
            // The API path verifies a classic webhook by looking its payment
            // up; a typed event has no payment to look up, so accepting it
            // meant accepting whatever anyone posted, and handing it to the
            // application's WebhookReceived listeners.
            $eventType = $event->string('type');
            if ($eventType !== null) {
                $this->log('warning', 'Rejected a typed Mollie webhook that cannot be verified without a webhook secret', [
                    'event_type' => $eventType,
                    'hint' => 'Mollie signs typed webhooks. Set MOLLIE_WEBHOOK_SECRET to the secret shown when the webhook was created.',
                ]);

                return false;
            }

            $paymentId = $event->string('id');

            if ($paymentId === null) {
                $this->log('warning', 'Webhook missing payment ID');

                return false;
            }

            $response = $this->makeRequest('GET', '/v2/payments/'.rawurlencode($paymentId));
            $paymentData = $this->parseResponse($response);

            if (! isset($paymentData['id']) || $paymentData['id'] !== $paymentId) {
                $this->log('warning', 'Payment verification failed - payment ID mismatch', [
                    'expected' => $paymentId,
                    'received' => $paymentData['id'] ?? null,
                ]);

                return false;
            }

            $this->log('info', 'Webhook validated successfully via API verification', [
                'payment_id' => $paymentId,
                'payment_status' => $paymentData['status'] ?? 'unknown',
                'hint' => 'Consider configuring MOLLIE_WEBHOOK_SECRET for more secure signature-based validation',
            ]);

            return true;
        } catch (Throwable $e) {
            if ($this->isDefinitiveVerificationRejection($e)) {
                // Typically a 404: the pinged payment id does not exist on
                // this account, which is what a forged ping looks like.
                $this->log('warning', 'Webhook validation failed: Mollie rejected the payment lookup', [
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            $this->log('error', 'Webhook validation could not reach Mollie', [
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);

            throw new WebhookException('Mollie webhook verification failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if Mollie API is accessible.
     *
     * @return bool True if API is healthy
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v2/methods');
            $statusCode = $response->getStatusCode();

            return ! HttpStatusCodes::isServerError($statusCode);
        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if (
                ($e instanceof PaymentException)
                && ($previous instanceof ClientException)
            ) {
                $response = $previous->getResponse();
                $statusCode = $response->getStatusCode();
                if (in_array($statusCode, [HttpStatusCodes::BAD_REQUEST, HttpStatusCodes::NOT_FOUND], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)');

                    return true;
                }
            }

            $this->log('error', 'Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract the transaction reference from Mollie's webhook payload.
     *
     * @param  array<array-key, mixed>  $payload  Webhook payload
     * @return string|null Transaction reference or null if not found
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return Payload::of($payload)->string('id');
    }

    /**
     * Extract the payment status from Mollie's webhook payload.
     *
     * Mollie webhook doesn't contain full payment details, just the ID.
     * The actual status should be fetched from the API.
     *
     * @param  array<array-key, mixed>  $payload  Webhook payload
     * @return string Payment status
     */
    public function extractWebhookStatus(array $payload): string
    {
        return Payload::of($payload)->string('status') ?? 'unknown';
    }

    /**
     * Extract the payment channel from Mollie's webhook payload.
     *
     * @param  array<array-key, mixed>  $payload  Webhook payload
     * @return string|null Payment channel or null if not found
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return Payload::of($payload)->string('method');
    }

    /**
     * Resolve the actual ID needed for verification.
     *
     * Mollie uses the payment ID for verification, not our internal reference.
     *
     * @param  string  $reference  Our internal reference
     * @param  string  $providerId  Mollie's payment ID
     * @return string ID to use for verification
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $providerId;
    }

    /**
     * Format amount to Mollie's required format.
     *
     * Mollie requires amounts as strings with exactly 2 decimal places.
     *
     * @param  float  $amount  Amount in major units
     * @param  string  $currency  Currency code
     * @return string Formatted amount (e.g., "10.00")
     */
    protected function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', '');
    }
}
