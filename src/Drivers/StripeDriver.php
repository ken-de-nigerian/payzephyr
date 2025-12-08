<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use Exception;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Random\RandomException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Driver implementation for the Stripe payment gateway.
 *
 * This driver utilizes the official stripe/stripe-php SDK.
 * It implements the "Stripe Checkout" flow, where the user is redirected to a Stripe-hosted
 * page to complete the transaction securely.
 */
class StripeDriver extends AbstractDriver
{
    protected string $name = 'stripe';

    /**
     * The native Stripe SDK client wrapper.
     *
     * @var StripeClient|object
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
        $this->stripe = new StripeClient($this->config['secret_key']);
    }

    /**
     * Inject a mock object for testing purposes.
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
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['secret_key'],
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Stripe uses standard 'Idempotency-Key' header
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
            $callback = $request->callbackUrl ?? $this->config['callback_url'] ?? null;

            if (empty($callback)) {
                throw new InvalidConfigurationException(
                    'Stripe requires a callback URL for its redirect flow. '.
                    'Please set "callback_url" in your config/payments.php or use ->callback() in your payment chain.'
                );
            }

            // Build the URLs safely using the helper
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
                'metadata' => array_merge($request->metadata, [
                    'reference' => $reference,
                ]),
            ];

            $options = [];
            if ($request->idempotencyKey) {
                $options['idempotency_key'] = $request->idempotencyKey;
            }

            $session = $this->stripe->checkout->sessions->create($params, $options);

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
        } catch (ApiErrorException $e) {
            $this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
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
        } catch (ApiErrorException $e) {
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
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['stripe-signature'][0]
            ?? $headers['Stripe-Signature'][0]
            ?? null;

        if (! $signature || empty($this->config['webhook_secret'])) {
            $this->log('warning', 'Webhook signature or secret missing');

            return false;
        }

        try {
            Webhook::constructEvent(
                $body,
                $signature,
                $this->config['webhook_secret']
            );

            $this->log('info', 'Webhook validated successfully');

            return true;
        } catch (Exception $e) {
            $this->log('warning', 'Webhook validation failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check API connectivity by retrieving the account balance.
     */
    public function healthCheck(): bool
    {
        try {
            $this->stripe->balance->retrieve();

            return true;

        } catch (AuthenticationException) {

            return true;

        } catch (ApiErrorException $e) {
            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            return $e->getHttpStatus() < 500;
        }
    }

    private function mapFromCheckoutSession($session): VerificationResponseDTO
    {
        $pi = $session->payment_intent ?? null;

        $status = match ($session->payment_status) {
            'paid' => 'success',
            'unpaid' => 'pending',
            default => 'failed'
        };

        return new VerificationResponseDTO(
            reference: $session->client_reference_id ?? $session->id,
            status: $status,
            amount: ($session->amount_total ?? $pi?->amount) / 100,
            currency: strtoupper((string) $session->currency),
            paidAt: $session->payment_status === 'paid'
                ? date('Y-m-d H:i:s', $session->created)
                : null,
            metadata: (array) ($session->metadata ?? []),
            provider: $this->getName(),
            channel: implode(',', $session->payment_method_types ?? []),
            customer: [
                'email' => $session->customer_email,
            ],
        );
    }

    private function mapFromPaymentIntent($intent): VerificationResponseDTO
    {
        return new VerificationResponseDTO(
            reference: $intent->metadata['reference'] ?? $intent->id,
            status: $this->normalizeStatus($intent->status),
            amount: $intent->amount / 100,
            currency: strtoupper($intent->currency),
            paidAt: $intent->status === 'succeeded'
                ? date('Y-m-d H:i:s', $intent->created)
                : null,
            metadata: (array) $intent->metadata,
            provider: $this->getName(),
            channel: $intent->payment_method_types[0] ?? null,
            customer: [
                'email' => $intent->receipt_email,
            ],
        );
    }
}
