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
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\OPayRefundMethods;
use Throwable;

/**
 * Driver implementation for the Opay payment gateway.
 */
final class OPayDriver extends AbstractDriver implements SupportsRefundsInterface
{
    use OPayRefundMethods;

    protected string $name = 'opay';

    /**
     * Make sure all required OPay credentials are configured.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['merchant_id'])) {
            throw new InvalidConfigurationException('OPay merchant ID is required');
        }
        if (empty($this->config['public_key'])) {
            throw new InvalidConfigurationException('OPay public key is required');
        }
    }

    /**
     * Get the HTTP headers needed for OPay API requests.
     *
     * Note: These headers are used for the Create Payment API.
     * The Status API requires different authentication (HMAC-SHA512 signature)
     * and should override these headers in the verify() method.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->settings()->string('public_key'),
            'MerchantId' => (string) $this->settings()->string('merchant_id'),
        ];
    }

    /**
     * OPay uses 'Idempotency-Key' header.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Create a new payment on OPay.
     *
     * @throws ChargeException If the payment creation fails.
     */
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->setCurrentRequest($request);

        try {
            $reference = $request->reference ?? $this->generateReference('OPAY');
            $amount = $request->getAmountInMinorUnits();
            $callbackUrl = $this->appendQueryParam($request->callbackUrl, 'reference', $reference);

            $payload = [
                'country' => 'NG',
                'reference' => $reference,
                'amount' => [
                    'total' => (string) $amount,
                    'currency' => $request->currency,
                ],
                'callbackUrl' => $callbackUrl,
                'returnUrl' => $callbackUrl,
                'cancelUrl' => $callbackUrl,
                'displayName' => $request->metadata['name'] ?? $request->email,
                'userInfo' => [
                    'userEmail' => $request->email,
                    'userName' => $request->metadata['name'] ?? $request->email,
                ],
                'product' => [
                    'name' => 'Product',
                    'description' => $request->metadata['description'] ?? 'Payment for '.$reference,
                ],
                'metadata' => array_merge($request->metadata, [
                    'reference' => $reference,
                ]),
            ];

            $channels = $this->mapChannels($request);
            if ($channels) {
                $payload['payMethod'] = $channels;
            }

            $payload = array_filter($payload, fn ($value): bool => $value !== null);

            $response = $this->makeRequest('POST', '/api/v1/international/cashier/create', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (($data['code'] ?? '') !== '00000') {
                throw new ChargeException(
                    Payload::of($data)->string('message') ?? Payload::of($data)->string('msg') ?? 'Failed to initialize OPay payment'
                );
            }

            $result = new Payload(is_array($data['data'] ?? null) ? $data['data'] : $data);

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $result->string('cashierUrl') ?? $result->string('paymentUrl') ?? $result->string('checkoutUrl')
                    ?? throw new ChargeException('OPay accepted the payment but returned no checkout URL to send the customer to'),
                accessCode: $result->string('orderNo') ?? $result->string('orderNumber') ?? $reference,
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
     * Verify an OPay payment by transaction reference.
     *
     * @param  string  $reference  The transaction reference
     *
     * @throws VerificationException If the payment can't be found or verified.
     */
    public function verify(string $reference): VerificationResponseDTO
    {
        try {
            $payload = [
                'country' => 'NG',
                'reference' => $reference,
            ];
            $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

            $privateKey = $this->settings()->string('secret_key');
            if ($privateKey === null || $privateKey === '') {
                throw new InvalidConfigurationException('OPay secret key (private key) is required for status API authentication');
            }

            $signature = hash_hmac('sha512', $payloadJson, $privateKey);
            $response = $this->makeRequest('POST', '/api/v1/international/cashier/status', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer '.$signature,
                    'MerchantId' => (string) $this->settings()->string('merchant_id'),
                ],
            ]);

            $data = $this->parseResponse($response);
            if (($data['code'] ?? '') !== '00000') {
                throw new VerificationException(
                    Payload::of($data)->string('message') ?? Payload::of($data)->string('msg') ?? 'Failed to verify OPay transaction'
                );
            }

            $result = is_array($data['data'] ?? null) ? $data['data'] : $data;
            $details = new Payload($result);
            $opayStatus = $details->string('status') ?? $details->string('orderStatus') ?? 'unknown';

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $opayStatus,
            ]);

            $status = match (strtoupper($opayStatus)) {
                'SUCCESS', 'SUCCEEDED', 'PAID' => 'success',
                'PENDING', 'PROCESSING' => 'pending',
                default => $this->normalizeStatus($opayStatus),
            };

            return new VerificationResponseDTO(
                reference: $details->string('reference') ?? $details->string('orderNo') ?? $reference,
                status: $status,
                amount: $this->requireAmount($this->requireArray($result, 'amount', 'verify'), 'total', 'verify') / 100,
                currency: $this->requireString($this->requireArray($result, 'amount', 'verify'), 'currency', 'verify'),
                paidAt: ($createTime = $details->int('createTime')) !== null ? date('Y-m-d H:i:s', $createTime) : null,
                metadata: self::normalizeMetadata($details->get('metadata')),
                provider: $this->getName(),
                channel: $details->string('instrumentType'),
                cardType: $details->string('opayCardToken'),
                customer: [
                    'email' => $details->string('customerEmail') ?? $details->string('email'),
                    'name' => $details->string('customerName') ?? $details->string('name'),
                ],
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
     * Verify that a webhook is really from OPay (security check).
     *
     * OPay signs webhooks using HMAC SHA256 with your secret key.
     * The signature comes in the 'x-opay-signature' or 'signature' header.
     */
    public function validateWebhook(array $headers, string $body): bool
    {
        $signature = $headers['x-opay-signature'][0]
            ?? $headers['X-OPay-Signature'][0]
            ?? $headers['signature'][0]
            ?? $headers['Signature'][0]
            ?? null;

        if (! $signature) {
            $this->log('warning', 'Webhook signature missing');

            return false;
        }

        // The secret key only. This used to fall back to the public key, which
        // is not a secret: a webhook signed with it proves nothing.
        $secretKey = $this->settings()->string('secret_key');

        if (! $secretKey) {
            $this->log('warning', 'OPay secret key not configured for webhook validation');

            return false;
        }

        $expectedSignature = hash_hmac('sha256', $body, $secretKey);
        $isValid = hash_equals($signature, $expectedSignature);

        if (! $isValid) {
            $this->log('warning', 'Webhook validation failed', [
                'valid' => false,
            ]);

            return false;
        }

        // No payload replay window: no field in OPay's payloads is confirmed to
        // be the time of the event. A replay is stopped by deduplication
        // instead (ADR-0017).

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * OPay sends no event identifier, so there is none to return, and
     * ProcessWebhook keys the delivery on a hash of its body instead.
     *
     * The id this used to return was payload.transactionId - the transaction
     * an event is about, not the event. Every status change after the first
     * shared its key and was dropped as a duplicate delivery.
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
     * Check if OPay's API is working.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->makeRequest('POST', '/api/v1/international/cashier/status');
            $statusCode = $response->getStatusCode();

            return ! HttpStatusCodes::isServerError($statusCode);
        } catch (Throwable $e) {
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
                    $this->log('info', 'Health check successful (expected 400/404 response)');

                    return true;
                }
            }
            $this->log('error', 'Health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The part of a webhook body that describes the transaction.
     *
     * OPay sends `{"payload": {...}, "sha512": ..., "type": ...}`, with the
     * transaction under `payload`. The extractors below used to read the top
     * level, where none of those fields are, so the reference came back null
     * and the transaction was never updated. A body without `payload` is read
     * as it is.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function webhookTransaction(array $payload): Payload
    {
        $body = new Payload($payload);

        return $body->has('payload') ? $body->at('payload') : $body;
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        $event = $this->webhookTransaction($payload);

        return $event->string('reference') ?? $event->string('orderNo');
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        $event = $this->webhookTransaction($payload);

        return $event->string('status') ?? $event->string('orderStatus') ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        $event = $this->webhookTransaction($payload);

        return $event->string('instrumentType') ?? $event->string('paymentChannel');
    }

    /**
     * Resolve the actual ID needed for verification.
     * OPay verifies by transaction reference.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $reference;
    }
}
