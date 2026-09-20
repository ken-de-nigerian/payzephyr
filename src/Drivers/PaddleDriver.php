<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
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
     * Currencies Paddle bills in the major unit (no minor unit at all), so
     * amounts are neither multiplied nor divided by 100.
     *
     * @var array<int, string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new InvalidConfigurationException('Paddle API key is required');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['api_key'],
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
            $data = $this->parseResponse($response)['data'] ?? [];

            $checkoutUrl = $data['checkout']['url'] ?? null;
            if (! $checkoutUrl) {
                throw new ChargeException('No checkout URL returned by Paddle. Set an approved default payment link under Paddle > Checkout > Checkout settings.');
            }

            $transactionId = $data['id'] ?? null;
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
                accessCode: (string) $transactionId,
                status: $this->normalizeStatus((string) ($data['status'] ?? 'draft')),
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
                'error_class' => get_class($e),
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
            $data = $this->parseResponse($response)['data'] ?? [];
            $currency = strtoupper($this->requireString($data, 'currency_code', 'verify'));
            $totals = $this->requireArray($this->requireArray($data, 'details', 'verify'), 'totals', 'verify');
            $grandTotal = $this->requireAmount($totals, 'grand_total', 'verify');
            $customData = self::normalizeMetadata($data['custom_data'] ?? null);
            $payment = $data['payments'][0] ?? [];

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $data['status'] ?? null,
            ]);

            return new VerificationResponseDTO(
                reference: (string) ($customData['reference'] ?? $data['id'] ?? $reference),
                status: $this->normalizeStatus((string) ($data['status'] ?? 'unknown')),
                amount: $this->fromMinorUnits($grandTotal, $currency),
                currency: $currency,
                paidAt: $data['billed_at'] ?? null,
                metadata: $customData,
                provider: $this->getName(),
                channel: $payment['method_details']['type'] ?? null,
                cardType: $payment['method_details']['card']['type'] ?? null,
                customer: [
                    'email' => $customData['email'] ?? null,
                    'name' => $payment['method_details']['card']['cardholder_name'] ?? null,
                ],
            );
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
     * Validate the `Paddle-Signature` header: `ts=<unix>;h1=<hmac>`, where the
     * HMAC-SHA256 is taken over "<ts>:<raw body>" with the destination's
     * endpoint secret key.
     *
     * @param  array<string, array<string>>  $headers
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $secret = $this->config['webhook_secret'] ?? null;
        if (empty($secret)) {
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

        $parts = [];
        foreach (explode(';', $header) as $segment) {
            $pair = explode('=', $segment, 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['h1'] ?? null;

        if (! $timestamp || ! $signature || ! ctype_digit($timestamp)) {
            $this->log('warning', 'Webhook signature header malformed');

            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$body, (string) $secret);
        if (! hash_equals($expected, $signature)) {
            $this->log('warning', 'Webhook signature validation failed', [
                'hint' => 'PADDLE_WEBHOOK_SECRET must match the secret key of the notification destination that sent this event.',
            ]);

            return false;
        }

        $config = app('payments.config') ?? config('payments', []);
        $tolerance = (int) ($config['security']['webhook_timestamp_tolerance'] ?? PaymentConstants::WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS);

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
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $data = $payload['data'] ?? [];

        return $data['custom_data']['reference'] ?? $data['id'] ?? null;
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
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookStatus(array $payload): string
    {
        $eventType = (string) ($payload['event_type'] ?? '');

        if (! str_starts_with($eventType, 'transaction.')) {
            return 'unknown';
        }

        return $payload['data']['status'] ?? 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['payments'][0]['method_details']['type'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        $id = $payload['event_id'] ?? $payload['notification_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    /**
     * Paddle timestamps the envelope with `occurred_at`, which the shared
     * field list doesn't know about.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractWebhookTimestamp(array $payload): ?int
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        if (is_string($occurredAt) && strtotime($occurredAt) !== false) {
            return strtotime($occurredAt);
        }

        return parent::extractWebhookTimestamp($payload);
    }

    /**
     * Amounts are expressed in the currency's lowest denomination, as a
     * string, except for currencies that have no minor unit.
     */
    protected function toMinorUnits(float $amount, string $currency): string
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (string) (int) round($amount);
        }

        return (string) (int) round($amount * 100);
    }

    protected function fromMinorUnits(string|int|float $amount, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float) $amount;
        }

        return ((float) $amount) / 100;
    }
}
