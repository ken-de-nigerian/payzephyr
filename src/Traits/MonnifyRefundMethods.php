<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for MonnifyDriver. See ADR-0011.
 *
 * $transactionReference is Monnify's transactionReference (the same
 * reference MonnifyDriver::verify() queries by). Monnify requires the
 * caller to generate its own refundReference up front rather than
 * returning a server-generated id, so one is derived from the request's
 * idempotency key.
 */
trait MonnifyRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        $refundReference = $request->idempotencyKey ?? Str::uuid()->toString();

        try {
            $payload = array_filter([
                'transactionReference' => $request->transactionReference,
                'refundReference' => $refundReference,
                'refundAmount' => $request->amount,
                'refundReason' => $request->reason ?? 'Refund requested',
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/api/v1/refunds/initiate-refund', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new RefundException($data['responseMessage'] ?? 'Failed to create Monnify refund');
            }

            $result = $data['responseBody'] ?? [];

            $this->log('info', 'Refund created', [
                'refund_reference' => $refundReference,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['refundReference'] ?? $refundReference,
                transactionReference: $result['transactionReference'] ?? $request->transactionReference,
                status: $result['refundStatus'] ?? 'pending',
                amount: (float) ($result['refundAmount'] ?? $request->amount ?? 0),
                currency: $result['currencyCode'] ?? 'NGN',
                reason: $result['refundReason'] ?? $request->reason,
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
            $response = $this->makeRequest('GET', "/api/v1/refunds/$refundReference", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);
            $data = $this->parseResponse($response);

            if (! ($data['requestSuccessful'] ?? false)) {
                throw new RefundException($data['responseMessage'] ?? 'Failed to fetch Monnify refund');
            }

            $result = $data['responseBody'] ?? [];

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['refundReference'] ?? $refundReference,
                transactionReference: $result['transactionReference'] ?? '',
                status: $result['refundStatus'] ?? 'unknown',
                amount: (float) ($result['refundAmount'] ?? 0),
                currency: $result['currencyCode'] ?? 'NGN',
                reason: $result['refundReason'] ?? null,
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
