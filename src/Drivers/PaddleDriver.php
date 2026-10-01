<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\PaddleRefundMethods;
use Throwable;

/**
 * Driver implementation for Paddle Billing (not Paddle Classic).
 *
 * A charge creates a transaction with a single non-catalog item priced at the
 * requested amount, and returns Paddle's hosted checkout URL. Paddle's own
 * transaction id (txn_...) is the verification id, so the package reference is
 * carried in `custom_data.reference` and read back from webhooks and verify().
 */
final class PaddleDriver extends AbstractDriver implements SupportsRefundsInterface
{
    use PaddleRefundMethods;

    protected string $name = 'paddle';

    /**
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(): void
    {
        if ($this->credential('api_key') === null) {
            throw new InvalidConfigurationException('Paddle API key is required');
        }
    }

    /**
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
     * Paddle Billing has no idempotency header, so sending one would be a
     * silently ignored (and potentially rejected) unknown header.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return [];
    }

    /**
     * Create a Paddle transaction and hand back its hosted checkout URL.
     *
     * @throws ChargeException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('PADDLE');

            $payload = [
                'items' => [[
                    'quantity' => 1,
                    'price' => [
                        'description' => $request->description ?? 'Payment',
                        'name' => $request->description ?? 'Payment',
                        'unit_price' => [
                            'amount' => $this->toMinorUnits($request->amount, $request->currency),
                            'currency_code' => strtoupper($request->currency),
                        ],
                        'product' => [
                            'name' => $this->config['product_name'] ?? ($request->description ?? 'Payment'),
                            'tax_category' => $this->config['tax_category'] ?? 'standard',
                        ],
                    ],
                ]],
                'currency_code' => strtoupper($request->currency),
                'collection_mode' => 'automatic',
                'custom_data' => array_merge($request->metadata, [
                    'reference' => $reference,
                    'email' => $request->email,
                ]),
            ];

            if ($request->callbackUrl) {
                $payload['checkout'] = [
                    'url' => $this->appendQueryParam($request->callbackUrl, 'reference', $reference),
                ];
            }

            $response = $this->makeRequest('POST', '/transactions', ['json' => $payload]);
            $details = Payload::of($this->parseResponse($response))->at('data');

            $checkoutUrl = $details->string('checkout', 'url');
            if (! $checkoutUrl) {
                throw new ChargeException('No checkout URL returned by Paddle. Set an approved default payment link under Paddle > Checkout > Checkout settings.');
            }

            $transactionId = $details->string('id');
            if (! $transactionId) {
                throw new ChargeException('Paddle returned a transaction without an id; the payment cannot be verified later.');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'paddle_id' => $transactionId,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $checkoutUrl,
                accessCode: $transactionId,
                status: $this->normalizeStatus($details->string('status') ?? 'draft'),
                metadata: array_merge($request->metadata, [
                    'paddle_transaction_id' => $transactionId,
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
     * Verify a transaction. $reference is Paddle's transaction id (txn_...),
     * resolved by resolveVerificationId() from the stored provider id.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/transactions/'.rawurlencode($reference));
            $details = Payload::of($this->parseResponse($response))->at('data');
            $data = $details->all();
            $currency = strtoupper($this->requireString($data, 'currency_code', 'verify'));
            $totals = $this->requireArray($this->requireArray($data, 'details', 'verify'), 'totals', 'verify');
            $grandTotal = $this->requireAmount($totals, 'grand_total', 'verify');
            $customData = self::normalizeMetadata($details->get('custom_data'));
            $payment = $details->at('payments', 0);

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $details->string('status'),
            ]);

            return new VerificationResponseDTO(
                reference: Payload::of($customData)->string('reference') ?? $details->string('id') ?? $reference,
                status: $this->normalizeStatus($details->string('status') ?? 'unknown'),
                amount: $this->fromMinorUnits($grandTotal, $currency),
                currency: $currency,
                paidAt: $details->string('billed_at'),
                metadata: $customData,
                provider: $this->getName(),
                channel: $payment->string('method_details', 'type'),
                cardType: $payment->string('method_details', 'card', 'type'),
                customer: [
                    'email' => $customData['email'] ?? null,
                    'name' => $payment->string('method_details', 'card', 'cardholder_name'),
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
     * Validate the `Paddle-Signature` header: `ts=<unix>;h1=<hmac>`, where the
     * HMAC-SHA256 is taken over "<ts>:<raw body>" with the destination's
     * endpoint secret key.
     *
     * @param  array<string, array<string>>  $headers
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $secret = $this->credential('webhook_secret');
        if ($secret === null) {
            $this->log('warning', 'Webhook rejected: no webhook secret configured', [
                'hint' => 'Set PADDLE_WEBHOOK_SECRET to the endpoint secret key from Paddle > Developer tools > Notifications.',
            ]);

            return false;
        }

        $header = $headers['paddle-signature'][0] ?? $headers['Paddle-Signature'][0] ?? null;
        if (! $header) {
            $this->log('warning', 'Webhook signature missing', [
                'hint' => 'Paddle webhooks always carry a Paddle-Signature header.',
            ]);

            return false;
        }

        // `ts=...;h1=...`, with one h1 per secret while a secret is being
        // rotated. Keeping only the last h1 rejected a delivery whose match was
        // an earlier one.
        $timestamp = null;
        $signatures = [];
        foreach (explode(';', $header) as $segment) {
            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                continue;
            }

            [$name, $value] = [trim($pair[0]), trim($pair[1])];

            if ($name === 'ts') {
                $timestamp = $value;
            } elseif ($name === 'h1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || $signatures === [] || ! ctype_digit($timestamp)) {
            $this->log('warning', 'Webhook signature header malformed');

            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$body, $secret);
        if (array_filter($signatures, fn (string $signature): bool => hash_equals($expected, $signature)) === []) {
            $this->log('warning', 'Webhook signature validation failed', [
                'hint' => 'PADDLE_WEBHOOK_SECRET must match the secret key of the notification destination that sent this event.',
            ]);

            return false;
        }

        $tolerance = $this->webhookTimestampTolerance();

        if (abs(time() - (int) $timestamp) > $tolerance) {
            $this->log('warning', 'Webhook timestamp outside tolerance window - potential replay attack', [
                'timestamp' => (int) $timestamp,
                'tolerance_seconds' => $tolerance,
            ]);

            return false;
        }

        return true;
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/event-types');

            return ! HttpStatusCodes::isServerError($response->getStatusCode());
        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if (($e instanceof PaymentException) && ($previous instanceof ClientException)) {
                $statusCode = $previous->getResponse()->getStatusCode();
                if (in_array($statusCode, [HttpStatusCodes::BAD_REQUEST, HttpStatusCodes::NOT_FOUND], true)) {
                    $this->log('info', 'Health check successful (expected 400/404 response)');

                    return true;
                }
            }

            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Paddle wraps the changed entity in `data`; the package reference lives in
     * that entity's `custom_data`, with the transaction id as the fallback.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $data = Payload::of($payload)->at('data');

        return $data->string('custom_data', 'reference') ?? $data->string('id');
    }

    /**
     * Paddle delivers transaction, subscription and adjustment events to the
     * same endpoint, and their `status` vocabularies are unrelated: a
     * `subscription.created` carries `active`, an `adjustment.updated` carries
     * `approved`, neither of which means anything as a payment status.
     *
     * This is reachable, not theoretical: Paddle copies a transaction's
     * `custom_data` onto the subscription it creates, and onto every
     * transaction that subscription later creates, so a subscription event can
     * legitimately resolve to a known reference via extractWebhookReference()
     * and reach this method. Scoping to `transaction.*` keeps a subscription's
     * lifecycle status from being written over a payment's.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookStatus(array $payload): string
    {
        $eventType = Payload::of($payload)->string('event_type') ?? '';

        if (! str_starts_with($eventType, 'transaction.')) {
            return 'unknown';
        }

        return Payload::of($payload)->string('data', 'status') ?? 'unknown';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return Payload::of($payload)->string('data', 'payments', 0, 'method_details', 'type');
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        return Payload::of($payload)->string('event_id') ?? Payload::of($payload)->string('notification_id');
    }

    /**
     * validateWebhook() rejects a delivery whose signed ts= is older than the
     * tolerance, and Paddle signs a fresh one for every attempt, so records
     * older than that can be pruned.
     */
    public function webhookReplayHorizon(): int
    {
        return $this->webhookTimestampTolerance();
    }

    /**
     * Amounts are expressed in the currency's lowest denomination, as a
     * string, except for currencies that have no minor unit.
     */
    protected function toMinorUnits(float $amount, string $currency): string
    {
        if ($this->isZeroDecimal($currency)) {
            return (string) (int) round($amount);
        }

        return (string) (int) round($amount * 100);
    }

    protected function fromMinorUnits(string|int|float $amount, string $currency): float
    {
        if ($this->isZeroDecimal($currency)) {
            return (float) $amount;
        }

        return ((float) $amount) / 100;
    }

    /**
     * Whether Paddle bills this currency in its major unit - it has no minor
     * unit - so amounts are neither multiplied nor divided by 100.
     *
     * The list is here rather than in a constant so that mutation testing
     * can see it: each code is exercised by a test, and removing one fails.
     */
    private function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true);
    }
}
