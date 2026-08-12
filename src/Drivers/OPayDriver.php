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
            'Authorization' => 'Bearer '.$this->config['public_key'],
            'MerchantId' => $this->config['merchant_id'],
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

            $payload = array_filter($payload, fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/api/v1/international/cashier/create', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (($data['code'] ?? '') !== '00000') {
                throw new ChargeException(
                    $data['message'] ?? $data['msg'] ?? 'Failed to initialize OPay payment'
                );
            }

            $result = $data['data'] ?? $data;

            $this->log('info', 'Charge initialized successfully', [
                'reference' => $reference,
            ]);

            return new ChargeResponseDTO(
                reference: $reference,
                authorizationUrl: $result['cashierUrl'] ?? $result['paymentUrl'] ?? $result['checkoutUrl'],
                accessCode: $result['orderNo'] ?? $result['orderNumber'] ?? $reference,
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

            $privateKey = $this->config['secret_key'] ?? null;
            if (empty($privateKey)) {
                throw new InvalidConfigurationException('OPay secret key (private key) is required for status API authentication');
            }

            $signature = hash_hmac('sha512', $payloadJson, $privateKey);
            $response = $this->makeRequest('POST', '/api/v1/international/cashier/status', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer '.$signature,
                    'MerchantId' => $this->config['merchant_id'],
                ],
            ]);

            $data = $this->parseResponse($response);
            if (($data['code'] ?? '') !== '00000') {
                throw new VerificationException(
                    $data['message'] ?? $data['msg'] ?? 'Failed to verify OPay transaction'
                );
            }

            $result = $data['data'] ?? $data;

            $this->log('info', 'Payment verified', [
                'reference' => $reference,
                'status' => $result['status'] ?? $result['orderStatus'] ?? 'unknown',
            ]);

            $opayStatus = $result['status'] ?? $result['orderStatus'] ?? 'unknown';
            $status = match (strtoupper($opayStatus)) {
                'SUCCESS', 'SUCCEEDED', 'PAID' => 'success',
                'PENDING', 'PROCESSING' => 'pending',
                default => $this->normalizeStatus($opayStatus),
            };

            return new VerificationResponseDTO(
                reference: $result['reference'] ?? $result['orderNo'] ?? $reference,
                status: $status,
                amount: ($result['amount']['total'] ?? 0) / 100,
                currency: $result['amount']['currency'] ?? 'NGN',
                paidAt: isset($result['createTime']) ? date('Y-m-d H:i:s', $result['createTime']) : null,
                metadata: $result['metadata'] ?? [],
                provider: $this->getName(),
                channel: $result['instrumentType'] ?? null,
                cardType: $result['opayCardToken'] ?? null,
                customer: [
                    'email' => $result['customerEmail'] ?? $result['email'] ?? null,
                    'name' => $result['customerName'] ?? $result['name'] ?? null,
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

        $secretKey = $this->config['secret_key'] ?? $this->config['public_key'] ?? null;

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

        $payload = json_decode($body, true) ?? [];
        if (! $this->validateWebhookTimestamp($payload)) {
            $this->log('warning', 'Webhook timestamp validation failed - potential replay attack');

            return false;
        }

        $this->log('info', 'Webhook validated successfully');

        return true;
    }

    /**
     * OPay nests transaction data (including `timestamp`) under `payload`,
     * not at the top level of the webhook body. See ADR-0001.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractWebhookTimestamp(array $payload): ?int
    {
        return $this->extractWebhookTimestampFrom($payload, 'payload');
    }

    /**
     * OPay nests the transaction id (used for event-level idempotency) under
     * `payload`. See ADR-0005.
     *
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        $inner = $payload['payload'] ?? [];
        $id = $inner['transactionId'] ?? $inner['reference'] ?? null;

        return $id !== null ? (string) $id : parent::extractWebhookEventId($payload);
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
            while ($current !== null) {
                if ($current instanceof ClientException) {
                    $clientException = $current;
                    break;
                }
                $current = $current->getPrevious();
            }

            if ($clientException !== null) {
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
     * Get the transaction reference from a raw webhook payload.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['reference'] ?? $payload['orderNo'] ?? null;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['status'] ?? $payload['orderStatus'] ?? 'unknown';
    }

    /**
     * Get the payment channel from a raw webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['instrumentType'] ?? $payload['paymentChannel'] ?? null;
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
