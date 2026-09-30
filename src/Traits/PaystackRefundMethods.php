<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

/**
 * Refund support for PaystackDriver.
 *
 * Paystack's refund endpoint (POST /refund) queues the refund for
 * asynchronous processing - the initial response status is typically
 * "pending"/"processing", not a final state. Final confirmation arrives via
 * the refund.processed / refund.failed webhook events, which
 * ProcessWebhook::processRefundWebhook() turns into RefundCompleted or
 * RefundFailed and writes back to the local refund row.
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
            $body = new Payload($data);

            if (! $body->flag(false, 'status')) {
                throw new RefundException($body->string('message') ?? 'Failed to create Paystack refund');
            }

            $result = $body->array('data');
            $refund = new Payload($result);
            $refundReference = $refund->string('id');

            if ($refundReference === null) {
                throw new RefundException('Refund reference not found in response. Response: '.json_encode($data));
            }

            $this->log('info', 'Refund created', [
                'refund_reference' => $refundReference,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refundReference,
                transactionReference: $request->transactionReference,
                status: $refund->string('status') ?? 'pending',
                amount: $this->requireAmount($result, 'amount', 'refund') / 100,
                currency: $this->requireString($result, 'currency', 'refund'),
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
            $response = $this->makeRequest('GET', '/refund/'.rawurlencode($refundReference));
            $body = new Payload($this->parseResponse($response));

            if (! $body->flag(false, 'status')) {
                throw new RefundException($body->string('message') ?? 'Failed to fetch Paystack refund');
            }

            $result = $body->array('data');
            $refund = new Payload($result);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refund->string('id') ?? $refundReference,
                transactionReference: $refund->string('transaction', 'reference') ?? $refund->string('transaction_reference') ?? '',
                status: $refund->string('status') ?? 'unknown',
                amount: $this->requireAmount($result, 'amount', 'refund') / 100,
                currency: $this->requireString($result, 'currency', 'refund'),
                reason: $refund->string('customer_note'),
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
