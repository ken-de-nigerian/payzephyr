<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for PaystackDriver. See ADR-0011.
 *
 * Paystack's refund endpoint (POST /refund) queues the refund for
 * asynchronous processing - the initial response status is typically
 * "pending"/"processing", not a final state. Final confirmation arrives via
 * the refund.processed / refund.failed webhook events (see
 * config('payments.refunds.webhook_events') and
 * ProcessWebhook::processRefundWebhook()).
 */
trait PaystackRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $payload = array_filter([
                'transaction' => $request->transactionReference,
                'amount' => $request->getAmountInMinorUnits(),
                'customer_note' => $request->reason,
            ], fn ($value) => $value !== null);

            $requestOptions = ['json' => $payload];
            if ($request->idempotencyKey) {
                $requestOptions['headers'] = ['Idempotency-Key' => $request->idempotencyKey];
            }

            $response = $this->makeRequest('POST', '/refund', $requestOptions);
            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new RefundException($data['message'] ?? 'Failed to create Paystack refund');
            }

            $result = $data['data'] ?? [];
            $refundReference = $result['id'] ?? null;

            if ($refundReference === null) {
                throw new RefundException('Refund reference not found in response. Response: '.json_encode($data));
            }

            $this->log('info', 'Refund created', [
                'refund_reference' => (string) $refundReference,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: (string) $refundReference,
                transactionReference: $request->transactionReference,
                status: $result['status'] ?? 'pending',
                amount: ($result['amount'] ?? 0) / 100,
                currency: $result['currency'] ?? 'NGN',
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
            $response = $this->makeRequest('GET', "/refund/$refundReference");
            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new RefundException($data['message'] ?? 'Failed to fetch Paystack refund');
            }

            $result = $data['data'] ?? [];

            $refundResponse = new RefundResponseDTO(
                refundReference: (string) ($result['id'] ?? $refundReference),
                transactionReference: (string) ($result['transaction']['reference'] ?? $result['transaction_reference'] ?? ''),
                status: $result['status'] ?? 'unknown',
                amount: ($result['amount'] ?? 0) / 100,
                currency: $result['currency'] ?? 'NGN',
                reason: $result['customer_note'] ?? null,
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
