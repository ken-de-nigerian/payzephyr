<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Traits\RazorpayRefundMethods;
use Throwable;

/**
 * Driver implementation for Razorpay.
 *
 * A charge creates a Payment Link: the only Razorpay product that returns a
 * hosted checkout URL to redirect to (Standard Checkout needs checkout.js on
 * the merchant's own page). The package reference is sent as the link's
 * `reference_id`, which Razorpay requires to be unique, and the link id
 * (plink_...) is the provider id verify() and refunds look the payment up by.
 */
final class RazorpayDriver extends AbstractDriver implements SupportsRefundsInterface
{
    use RazorpayRefundMethods;

    protected string $name = 'razorpay';

    /**
     * Razorpay rejects a Payment Link `reference_id` longer than this.
     */
    private const MAX_REFERENCE_ID_LENGTH = 40;

    /**
     * Razorpay accepts at most 15 notes per entity, each value up to 256 characters.
     */
    private const MAX_NOTES = 15;

    private const MAX_NOTE_LENGTH = 256;

    /**
     * The method groups a Payment Link's `options.checkout.method` can show or hide.
     *
     * @var array<int, string>
     */
    private const CHECKOUT_METHODS = ['card', 'netbanking', 'upi', 'wallet'];

    /**
     * Currencies Razorpay bills without a minor unit.
     *
     * @var array<int, string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Currencies Razorpay bills in thousandths. The last digit of the amount
     * must be 0, so these are only precise to the hundredth.
     *
     * @var array<int, string>
     */
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * Make sure both halves of the API key pair are configured.
     *
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['key_id']) || empty($this->config['key_secret'])) {
            throw new InvalidConfigurationException('Razorpay key id and key secret are required');
        }
    }

    /**
     * Razorpay uses HTTP Basic authentication with the key id and key secret.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode($this->config['key_id'].':'.$this->config['key_secret']),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * The Payment Links API has no idempotency header. Duplicate charges are
     * instead refused by Razorpay requiring `reference_id` to be unique per link.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return [];
    }

    /**
     * Create a Payment Link and hand back its hosted checkout URL.
     *
     * @throws ChargeException
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('RAZORPAY');

            if (strlen($reference) > self::MAX_REFERENCE_ID_LENGTH) {
                throw new ChargeException(
                    'Razorpay references are limited to '.self::MAX_REFERENCE_ID_LENGTH." characters; [$reference] is ".strlen($reference).'.'
                );
            }

            $payload = [
                'amount' => $this->toMinorUnits($request->amount, $request->currency),
                'currency' => strtoupper($request->currency),
                'reference_id' => $reference,
                'description' => $request->description ?? 'Payment',
                'customer' => $this->buildCustomer($request),
                'notify' => ['sms' => false, 'email' => false],
                'notes' => $this->buildNotes($request->metadata, $reference),
            ];

            if ($request->callbackUrl) {
                $payload['callback_url'] = $this->appendQueryParam($request->callbackUrl, 'reference', $reference);
                $payload['callback_method'] = 'get';
            }

            $methods = $this->mapChannels($request);
            if ($methods) {
                $payload['options'] = ['checkout' => ['method' => $this->buildCheckoutMethods($methods)]];
            }

            $response = $this->makeRequest('POST', '/v1/payment_links', ['json' => $payload]);
            $data = $this->parseResponse($response);

            if (empty($data['id']) || empty($data['short_url'])) {
                throw new ChargeException('Razorpay did not return a payment link id and checkout URL');
            }

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
                'razorpay_payment_link_id' => $data['id'],
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: (string) $data['short_url'],
                accessCode: (string) $data['id'],
                status: $this->normalizeStatus((string) ($data['status'] ?? 'created')),
                metadata: array_merge($request->metadata, [
                    'razorpay_payment_link_id' => $data['id'],
                ]),
                provider: $this->getName(),
            );
        } catch (ChargeException $e) {
            $message = $this->describeFailure($e);

            throw $message === $e->getMessage() ? $e : new ChargeException($message, 0, $e);
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
     * Verify a Payment Link.
     *
     * $reference is normally the link id (plink_...) resolved from the stored
     * provider id, but is the package reference itself when neither the
     * session cache nor the transaction log has the link id.
     *
     * @throws VerificationException
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $link = $this->fetchPaymentLink($reference);
            $currency = strtoupper((string) ($link['currency'] ?? ''));
            $payment = $this->findSettledPayment($link);
            $customer = is_array($link['customer'] ?? null) ? $link['customer'] : [];

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $link['status'] ?? null,
            ]);

            return new VerificationResponseDTO(
                reference: (string) ($link['reference_id'] ?? $reference),
                status: $this->normalizeStatus((string) ($link['status'] ?? 'unknown')),
                amount: $this->fromMinorUnits($link['amount'] ?? 0, $currency),
                currency: $currency,
                paidAt: isset($payment['created_at']) ? date('c', (int) $payment['created_at']) : null,
                metadata: self::normalizeMetadata($link['notes'] ?? null),
                provider: $this->getName(),
                channel: $payment['method'] ?? null,
                customer: [
                    'email' => $customer['email'] ?? null,
                    'name' => $customer['name'] ?? null,
                    'contact' => $customer['contact'] ?? null,
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
            throw new VerificationException('Payment verification failed: '.$this->describeFailure($e), 0, $e);
        }
    }

    /**
     * Validate the `X-Razorpay-Signature` header: a hex HMAC-SHA256 of the raw
     * body, keyed with the secret set on the webhook in the Razorpay
     * Dashboard (not the API key secret). There is no unverified fallback.
     *
     * No timestamp window is applied. Razorpay's envelope `created_at` is the
     * entity's creation time, not the event's: a Payment Link paid five
     * minutes after it was created is delivered with a five-minute-old value,
     * and a refund entity carries no settlement time at all. A replayed body is
     * byte-identical, so ProcessWebhook's event-level idempotency skips it
     * instead, the trade-off ADR-0001 made for Mollie's signature path. See
     * ADR-0014.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $secret = $this->config['webhook_secret'] ?? null;
        if (empty($secret)) {
            $this->log('warning', 'Webhook rejected: no webhook secret configured', [
                'hint' => 'Set RAZORPAY_WEBHOOK_SECRET to the secret entered for this webhook in the Razorpay Dashboard.',
            ]);

            return false;
        }

        $signature = $headers['x-razorpay-signature'][0]
            ?? $headers['X-Razorpay-Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        $expected = hash_hmac('sha256', $body, (string) $secret);
        if (! hash_equals($expected, (string) $signature)) {
            $this->log('warning', 'Webhook signature invalid', [
                'hint' => 'RAZORPAY_WEBHOOK_SECRET must match the webhook secret, not the API key secret.',
            ]);

            return false;
        }

        if (! is_array(json_decode($body, true))) {
            $this->log('warning', 'Webhook body is not a JSON object');

            return false;
        }

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * Check that Razorpay's API is reachable with these credentials. A 401
     * (bad keys) counts as unhealthy, since every charge would fail the same way.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/v1/payment_links', ['query' => ['count' => 1]]);

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
     * Only Payment Link events carry the package reference. Refund and
     * payment events sent to the same endpoint must not be read as a
     * transaction status update.
     *
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookReference(array $payload): ?string
    {
        if (! $this->isPaymentLinkEvent($payload)) {
            return null;
        }

        $reference = $payload['payload']['payment_link']['entity']['reference_id'] ?? null;

        return $reference !== null ? (string) $reference : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookStatus(array $payload): string
    {
        if (! $this->isPaymentLinkEvent($payload)) {
            return 'unknown';
        }

        return (string) ($payload['payload']['payment_link']['entity']['status'] ?? 'unknown');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['payload']['payment']['entity']['method'] ?? null;
    }

    /**
     * Razorpay's own event id arrives in the `x-razorpay-event-id` header,
     * which never reaches this method, so the key is built from the event
     * name and the ids it concerns. The key is also the replay defence (see
     * validateWebhook()), so it leaves out the envelope's `created_at`, which
     * is the entity's creation time rather than the event's.
     *
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        $event = $payload['event'] ?? null;
        $subjectId = $payload['payload']['payment_link']['entity']['id']
            ?? $payload['payload']['refund']['entity']['id']
            ?? null;
        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;

        if (! is_string($event) || ($subjectId === null && $paymentId === null)) {
            return parent::extractWebhookEventId($payload);
        }

        return implode(':', array_filter(
            [$event, $subjectId, $paymentId],
            fn ($part) => $part !== null && $part !== ''
        ));
    }

    /**
     * Fetch a Payment Link by its plink_ id, or by the package reference
     * through the list endpoint's `reference_id` filter. That filter is
     * eventually consistent: in test mode it did not yet return a link created
     * two seconds earlier, while fetching by plink_ id always did.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException
     */
    protected function fetchPaymentLink(string $identifier): array
    {
        if (str_starts_with($identifier, 'plink_')) {
            $link = $this->parseResponse(
                $this->makeRequest('GET', '/v1/payment_links/'.rawurlencode($identifier))
            );

            if (empty($link['id'])) {
                throw new VerificationException("Razorpay payment link [$identifier] not found");
            }

            return $link;
        }

        $data = $this->parseResponse($this->makeRequest('GET', '/v1/payment_links', [
            'query' => ['reference_id' => $identifier],
        ]));

        $links = is_array($data['payment_links'] ?? null) ? $data['payment_links'] : [];

        if (count($links) !== 1 || ! is_array($links[0] ?? null)) {
            throw new VerificationException(
                'Expected exactly one Razorpay payment link for reference ['.$identifier.'], found '.count($links)
                .($links === [] ? ". Razorpay's reference_id lookup can trail link creation by a few seconds; the plink_ id is always current." : '')
            );
        }

        // Links in a list response always carry an empty `payments` array,
        // even once paid, so the match is re-fetched by id for the full entity.
        $linkId = (string) ($links[0]['id'] ?? '');

        if (! str_starts_with($linkId, 'plink_')) {
            throw new VerificationException("Razorpay returned a payment link without an id for reference [$identifier]");
        }

        return $this->fetchPaymentLink($linkId);
    }

    /**
     * Amount in the currency's smallest unit, as Razorpay expects it.
     */
    protected function toMinorUnits(float $amount, string $currency): int
    {
        return match ($this->currencyExponent($currency)) {
            0 => (int) round($amount),
            3 => (int) round($amount * 100) * 10,
            default => (int) round($amount * 100),
        };
    }

    protected function fromMinorUnits(int|float|string $amount, string $currency): float
    {
        return ((float) $amount) / (10 ** $this->currencyExponent($currency));
    }

    /**
     * Razorpay notes are flat: at most 15 pairs, values up to 256 characters.
     * Nested metadata cannot be carried and is left out; PayZephyr still logs
     * the full metadata locally. The package reference is always included.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, string>
     */
    protected function buildNotes(array $metadata, string $reference): array
    {
        $notes = ['payzephyr_reference' => $reference];

        foreach ($metadata as $key => $value) {
            if (count($notes) >= self::MAX_NOTES) {
                break;
            }

            if (! is_scalar($value) || (string) $key === 'payzephyr_reference') {
                continue;
            }

            $notes[(string) $key] = mb_substr((string) $value, 0, self::MAX_NOTE_LENGTH);
        }

        return $notes;
    }

    /**
     * AbstractDriver::makeRequest() reports a rejected request only as
     * "Invalid request to payment provider", which hides why Razorpay refused
     * it (a duplicate reference_id, a refund under INR 1.00). Append Razorpay's
     * own error description when the response carries one.
     */
    protected function describeFailure(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->getResponse() !== null) {
                $error = json_decode((string) $current->getResponse()->getBody(), true)['error'] ?? null;

                if (is_array($error) && is_string($error['description'] ?? null) && $error['description'] !== '') {
                    return $e->getMessage().' Razorpay: '.$error['description'];
                }

                break;
            }
        }

        return $e->getMessage();
    }

    /**
     * @return array<string, string>
     */
    private function buildCustomer(ChargeRequestDTO $request): array
    {
        $customer = $request->customer ?? [];
        $name = $customer['name'] ?? null;
        $contact = $customer['phone'] ?? $customer['contact'] ?? null;

        return array_filter([
            'email' => $request->email,
            'name' => is_scalar($name) ? (string) $name : '',
            'contact' => is_scalar($contact) ? (string) $contact : '',
        ], fn (string $value) => $value !== '');
    }

    /**
     * @param  array<int, string>  $methods
     * @return array<string, bool>
     */
    private function buildCheckoutMethods(array $methods): array
    {
        $shown = [];
        foreach (self::CHECKOUT_METHODS as $method) {
            $shown[$method] = in_array($method, $methods, true);
        }

        return $shown;
    }

    /**
     * The payment that settled a link: captured, or since refunded.
     *
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>|null
     */
    private function findSettledPayment(array $link): ?array
    {
        foreach ((is_array($link['payments'] ?? null) ? $link['payments'] : []) as $payment) {
            if (is_array($payment) && in_array($payment['status'] ?? null, ['captured', 'refunded'], true)) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isPaymentLinkEvent(array $payload): bool
    {
        $event = $payload['event'] ?? null;

        return is_string($event) && str_starts_with($event, 'payment_link.');
    }

    private function currencyExponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }
}
