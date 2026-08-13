<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for MonnifyDriver.
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
            $refundAmount = $request->amount ?? $this->fetchOriginalTransactionAmount($request->transactionReference);

            $payload = array_filter([
                'transactionReference' => $request->transactionReference,
                'refundReference' => $refundReference,
                'refundAmount' => $refundAmount,
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

    /**
     * Fetch the original transaction's amount for a full (no explicit
     * amount) Monnify refund - see the comment in refund() above. Reuses
     * verify() rather than a bespoke lookup, since $transactionReference is
     * documented (see the trait docblock) as the same reference verify()
     * already queries by.
     *
     * @throws RefundException
     */
    private function fetchOriginalTransactionAmount(string $transactionReference): float
    {
        try {
            return $this->verify($transactionReference)->amount;
        } catch (Throwable $e) {
            throw new RefundException(
                "Cannot issue a full refund for Monnify transaction [$transactionReference]: failed to look up the original transaction's amount. Pass an explicit amount() instead. (".$e->getMessage().')',
                0,
                $e
            );
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
