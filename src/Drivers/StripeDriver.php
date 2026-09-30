<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

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
use KenDeNigerian\PayZephyr\Traits\StripeRefundMethods;
use KenDeNigerian\PayZephyr\Traits\StripeSubscriptionMethods;
use Random\RandomException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Driver implementation for the Stripe payment gateway.
 */
final class StripeDriver extends AbstractDriver implements SupportsRefundsInterface, SupportsSubscriptionsInterface
{
    use StripeRefundMethods;
    use StripeSubscriptionMethods;

    protected string $name = 'stripe';

    /**
     * The native Stripe SDK client wrapper.
     *
     * @var StripeClient
     */
    protected $stripe;

    /**
     * Ensure the configuration contains the Secret Key.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Stripe secret key is required');
        }
    }

    /**
     * Initialize the native Stripe SDK client using the config secret.
     */
    protected function initializeClient(): void
    {
        parent::initializeClient();
        $this->stripe = new StripeClient((string) $this->settings()->string('secret_key'));
    }

    /**
     * Inject a mock object for testing purposes. Natively typed `object` so a
     * test double need not extend the SDK's client; it has to behave like one.
     *
     * @param  StripeClient  $stripe
     */
    public function setStripeClient(object $stripe): void
    {
        $this->stripe = $stripe;
    }

    /**
     * Get default headers.
     *
     * Note: The Stripe SDK handles headers internally, but this is kept
     * for consistency with the AbstractDriver interface or manual fallback requests.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->settings()->string('secret_key'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Stripe uses standard 'Idempotency-Key' header
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Initialize a Stripe Checkout Session.
     *
     * This creates a session on Stripe servers and returns the URL the user
     * must visit.
     * It maps the internal ChargeRequestDTO to Stripe's line-item format,
     * converting the amount to minor units (cents) automatically.
     *
     * @throws ChargeException
     * @throws RandomException|InvalidConfigurationException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('STRIPE');
            if (empty($request->callbackUrl)) {
                throw new InvalidConfigurationException(
                    'Stripe requires a callback URL for its redirect flow. '.
                    'Please use ->callback() in your payment chain to set the callback URL.'
                );
            }

            $callback = $request->callbackUrl;

            $successUrl = $this->appendQueryParam($callback, 'status', 'success');
            $successUrl = $this->appendQueryParam($successUrl, 'reference', $reference);

            $cancelUrl = $this->appendQueryParam($callback, 'status', 'cancelled');
            $cancelUrl = $this->appendQueryParam($cancelUrl, 'reference', $reference);

            $paymentMethods = $this->mapChannels($request) ?? ['card'];

            $params = [
                'payment_method_types' => $paymentMethods,
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'product_data' => [
                            'name' => $request->description ?? 'Payment',
                        ],
                        'unit_amount' => $request->getAmountInMinorUnits(),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $reference,
                'customer_email' => $request->email,
                'metadata' => $this->stripeMetadata(array_merge($request->metadata, [
                    'reference' => $reference,
                ])),
            ];

            $options = [];
            if ($request->idempotencyKey) {
                $options['idempotency_key'] = $request->idempotencyKey;
            }

            $session = $this->stripe->checkout->sessions->create($params, $options);

            // Only a hosted session has a URL to send the customer to.
            if ($session->url === null) {
                throw new ChargeException("Stripe created checkout session [$session->id] without a URL to redirect the customer to");
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'session_id' => $session->id,
                'idempotent' => $request->idempotencyKey !== null,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $session->url,
                accessCode: $session->id,
                status: 'pending',
                metadata: [
                    'session_id' => $session->id,
                ],
                provider: $this->getName(),
            );
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
            throw new ChargeException('Stripe charge failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage(), 'error_class' => $e::class]);
            throw new ChargeException('Stripe charge failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->clearCurrentRequest();
        }
    }

    /**
     * Verify a payment by retrieving the PaymentIntent.
     *
     * This method attempts to find the transaction in two ways:
     * 1. Direct retrieval assuming $reference is a Stripe PaymentIntent ID.
     * 2. Fallback search (metadata lookup) if the ID retrieval fails.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {

            if (str_starts_with($reference, 'cs_')) {
                $session = $this->stripe->checkout->sessions->retrieve($reference, [
                    'expand' => ['payment_intent'],
                ]);

                return $this->mapFromCheckoutSession($session);
            }

            if (str_starts_with($reference, 'pi_')) {
                $intent = $this->stripe->paymentIntents->retrieve($reference);

                return $this->mapFromPaymentIntent($intent);
            }

            $sessions = $this->stripe->checkout->sessions->all([
                'limit' => 1,
            ])->data;

            $found = null;

            foreach ($sessions as $session) {
                if (($session->client_reference_id ?? null) === $reference) {
                    $found = $session;
                    break;
                }
            }

            if ($found) {
                $session = $this->stripe->checkout->sessions->retrieve($found->id, [
                    'expand' => ['payment_intent'],
                ]);

                return $this->mapFromCheckoutSession($session);
            }

            $intents = $this->stripe->paymentIntents->all(['limit' => 10])->data;

            foreach ($intents as $intent) {
                if (($intent->metadata['reference'] ?? null) === $reference) {
                    return $this->mapFromPaymentIntent($intent);
                }
            }

            throw new VerificationException("Payment not found for reference [$reference]");
        } catch (VerificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // An API error and anything else fail the same way.
            throw new VerificationException(
                'Stripe verification failed: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Validate the webhook signature using Stripe's utility class.
     *
     * Validates the timestamp and signature against the endpoint's
     * signing secret (whsec_...) to prevent replay attacks and forgery.
     *
     * Note: The body must be the exact raw request body as received.
     * Laravel's Request::getContent() should be used to get the raw body.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['stripe-signature'][0]
            ?? $headers['Stripe-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        if (empty($this->config['webhook_secret'])) {
            $this->log('warning', 'Webhook secret not configured', [
                'hint' => 'Set STRIPE_WEBHOOK_SECRET in your .env file. Get it from Stripe Dashboard → Developers → Webhooks → Select endpoint → Signing secret',
            ]);

            return false;
        }

        try {
            // The replay window is the SDK's, on the t= timestamp inside the
            // signature header. Stripe signs a fresh one for every attempt, so
            // it never rejects a genuine retry. The payload's Event.created is
            // repeated by every retry and is not checked: doing so rejected
            // every Stripe retry more than five minutes after the event.
            Webhook::constructEvent(
                $body,
                $signature,
                (string) $this->settings()->string('webhook_secret'),
                $this->webhookTimestampTolerance()
            );

            $this->log('info', 'Webhook validated successfully');

            return true;
        } catch (SignatureVerificationException $e) {
            $this->log('warning', 'Webhook validation failed', [
                'error' => $e->getMessage(),
                'hint' => 'Ensure STRIPE_WEBHOOK_SECRET matches the signing secret from your Stripe webhook endpoint. The secret should start with "whsec_".',
            ]);

            return false;
        } catch (Throwable $e) {
            $this->log('warning', 'Webhook validation failed', [
                'error' => $e->getMessage(),
                'exception_type' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Check API connectivity by retrieving the account balance.
     */
    /**
     * The SDK rejects a delivery whose signed t= timestamp is older than the
     * tolerance, so records older than that can be pruned.
     */
    public function webhookReplayHorizon(): int
    {
        return $this->webhookTimestampTolerance();
    }

    public function healthCheck(): bool
    {
        try {
            $this->stripe->balance->retrieve();

            return true;

        } catch (AuthenticationException) {

            return true;

        } catch (ApiErrorException $e) {
            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            $statusCode = $e->getHttpStatus();
            if ($statusCode === null) {
                return false;
            }

            return ! HttpStatusCodes::isServerError($statusCode);
        }
    }

    /**
     * @param  Session  $session
     */
    private function mapFromCheckoutSession(object $session): VerificationResponseDTO
    {
        $pi = $session->payment_intent ?? null;
        $piAmount = $pi->amount ?? null;

        $status = match ($session->payment_status) {
            'paid' => 'success',
            'unpaid' => 'pending',
            default => 'failed'
        };

        return new VerificationResponseDTO(
            reference: $session->client_reference_id ?? $session->id,
            status: $status,
            // Never `?? 0`. A checkout session with no amount_total and no
            // payment intent behind it would otherwise verify as a completed
            // payment worth nothing, which reconciles against the provider as
            // a missing N and against the customer as a charge they made.
            amount: $this->requireAmountValue($session->amount_total ?? $piAmount, 'amount_total', 'verify') / 100,
            currency: strtoupper((string) $session->currency),
            paidAt: $session->payment_status === 'paid'
                ? date('Y-m-d H:i:s', $session->created)
                : null,
            metadata: self::normalizeMetadata($session->metadata ?? null),
            provider: $this->getName(),
            channel: implode(',', $session->payment_method_types ?? []),
            customer: [
                'email' => $session->customer_email ?? null,
            ],
        );
    }

    /**
     * @param  PaymentIntent  $intent
     */
    private function mapFromPaymentIntent(object $intent): VerificationResponseDTO
    {
        return new VerificationResponseDTO(
            reference: Payload::of(self::normalizeMetadata($intent->metadata))->string('reference') ?? $intent->id,
            status: $this->normalizeStatus($intent->status),
            amount: $intent->amount / 100,
            currency: strtoupper($intent->currency),
            paidAt: $intent->status === 'succeeded'
                ? date('Y-m-d H:i:s', $intent->created)
                : null,
            metadata: self::normalizeMetadata($intent->metadata ?? null),
            provider: $this->getName(),
            channel: $intent->payment_method_types[0] ?? null,
            customer: [
                'email' => $intent->receipt_email ?? null,
            ],
        );
    }

    /**
     * Metadata in the shape Stripe accepts: string keys to string values.
     *
     * PayZephyr's metadata is a general-purpose bag, and callers put nested
     * values in it - a cart, an address. Sent as it is, a nested value becomes
     * nested form fields and Stripe rejects the whole request. A scalar is
     * sent as its string, null as an empty string, and anything else as JSON,
     * so it survives the round trip readable. Every request that carries
     * metadata - a charge, a refund, a plan, a subscription - goes through
     * here.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, string>
     */
    private function stripeMetadata(array $metadata): array
    {
        $flat = [];

        foreach ($metadata as $key => $value) {
            $flat[(string) $key] = match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) json_encode($value),
            };
        }

        return $flat;
    }

    /**
     * A Stripe SDK object as a typed reader.
     *
     * The SDK answers with StripeObjects: their fields are untyped magic
     * properties, their nested values are more StripeObjects, and an `(array)`
     * cast of one yields the SDK's own internals (`_values`, `_opts`, ...)
     * rather than the data. Encoding it is the SDK's supported way to get the
     * plain data out, and works the same on the stdClass tree a test stands in
     * for one with.
     */
    private function stripePayload(object $object): Payload
    {
        return Payload::of(json_decode((string) json_encode($object), true));
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $object = Payload::of($payload)->at('data', 'object');

        return $object->string('metadata', 'reference') ?? $object->string('client_reference_id');
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        $body = new Payload($payload);

        return $body->string('data', 'object', 'status') ?? $body->string('type') ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return Payload::of($payload)->string('data', 'object', 'payment_method');
    }
}
