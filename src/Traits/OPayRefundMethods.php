<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for OPayDriver. See ADR-0011.
 *
 * $transactionReference is OPay's merchant transaction reference (the same
 * reference OPayDriver::verify() queries by). Like the status endpoint,
 * OPay's refund endpoint is authenticated with an HMAC-SHA512 signature
 * over the request body, using the merchant's secret key.
 */
trait OPayRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        $refundReference = $request->idempotencyKey ?? Str::uuid()->toString();

        try {
            $privateKey = $this->config['secret_key'] ?? null;
            if (empty($privateKey)) {
                throw new InvalidConfigurationException('OPay secret key (private key) is required for refund API authentication');
            }

            $payload = array_filter([
                'country' => 'NG',
                'reference' => $refundReference,
                'orderNo' => $request->transactionReference,
                'amount' => $request->amount !== null ? [
                    'total' => (string) $request->getAmountInMinorUnits(),
                    'currency' => $request->currency ?? $this->config['currencies'][0] ?? 'NGN',
                ] : null,
                'refundReason' => $request->reason,
            ], fn ($value) => $value !== null);

            $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha512', $payloadJson, $privateKey);

            $response = $this->makeRequest('POST', '/api/v1/international/refund/create', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer '.$signature,
                    'MerchantId' => $this->config['merchant_id'],
                ],
            ]);

            $data = $this->parseResponse($response);

            if (($data['code'] ?? '') !== '00000') {
                throw new RefundException($data['message'] ?? $data['msg'] ?? 'Failed to create OPay refund');
            }

            $result = $data['data'] ?? $data;

            $this->log('info', 'Refund created', [
                'refund_reference' => $refundReference,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['refundNo'] ?? $refundReference,
                transactionReference: $result['orderNo'] ?? $request->transactionReference,
                status: $result['status'] ?? 'pending',
                amount: ($result['amount']['total'] ?? 0) / 100,
                currency: $result['amount']['currency'] ?? 'NGN',
                reason: $request->reason,
                metadata: $request->metadata,
                provider: $this->getName(),
            );

            $this->logRefund($request, $refundResponse);

            return $refundResponse;
        } catch (RefundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create refund', ['error' => $e->getMessage()]);
            throw new RefundException('Failed to create refund: '.$e->getMessage(), 0, $e);
        }
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $privateKey = $this->config['secret_key'] ?? null;
            if (empty($privateKey)) {
                throw new InvalidConfigurationException('OPay secret key (private key) is required for refund API authentication');
            }

            $payload = ['country' => 'NG', 'reference' => $refundReference];
            $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha512', $payloadJson, $privateKey);

            $response = $this->makeRequest('POST', '/api/v1/international/refund/status', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer '.$signature,
                    'MerchantId' => $this->config['merchant_id'],
                ],
            ]);

            $data = $this->parseResponse($response);

            if (($data['code'] ?? '') !== '00000') {
                throw new RefundException($data['message'] ?? $data['msg'] ?? 'Failed to fetch OPay refund');
            }

            $result = $data['data'] ?? $data;

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['refundNo'] ?? $refundReference,
                transactionReference: $result['orderNo'] ?? '',
                status: $result['status'] ?? 'unknown',
                amount: ($result['amount']['total'] ?? 0) / 100,
                currency: $result['amount']['currency'] ?? 'NGN',
                reason: null,
                metadata: [],
                provider: $this->getName(),
            );

            $this->logRefundFromResponse($refundResponse);

            return $refundResponse;
        } catch (RefundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch refund', [
                'refund_reference' => $refundReference,
                'error' => $e->getMessage(),
            ]);
            throw new RefundException('Failed to fetch refund: '.$e->getMessage(), 0, $e);
        }
    }
}
