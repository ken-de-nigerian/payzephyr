<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

/**
 * Refund support for FlutterwaveDriver.
 *
 * $transactionReference is Flutterwave's numeric transaction id (the same
 * id FlutterwaveDriver::verify() resolves internally) - not the merchant
 * tx_ref - since Flutterwave's refund endpoint is id-keyed.
 */
trait FlutterwaveRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $payload = array_filter([
                'amount' => $request->amount,
                'comments' => $request->reason,
            ], fn ($value): bool => $value !== null);

            $requestOptions = ['json' => $payload];
            if ($request->idempotencyKey) {
                $requestOptions['headers'] = ['Idempotency-Key' => $request->idempotencyKey];
            }

            $response = $this->makeRequest('POST', 'transactions/'.rawurlencode($request->transactionReference).'/refund', $requestOptions);
            $data = $this->parseResponse($response);
            $body = new Payload($data);

            if ($body->string('status') !== 'success') {
                throw new RefundException($body->string('message') ?? 'Failed to create Flutterwave refund');
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
                amount: $this->requireAmountValue($refund->get('amount_refunded') ?? $request->amount, 'amount_refunded', 'refund'),
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
            $response = $this->makeRequest('GET', 'refunds/'.rawurlencode($refundReference));
            $body = new Payload($this->parseResponse($response));

            if ($body->string('status') !== 'success') {
                throw new RefundException($body->string('message') ?? 'Failed to fetch Flutterwave refund');
            }

            // The refund, or a list holding it.
            $result = $body->arrayOrNull('data', 0) ?? $body->array('data');
            $refund = new Payload($result);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refund->string('id') ?? $refundReference,
                transactionReference: $refund->string('transaction_id') ?? '',
                status: $refund->string('status') ?? 'unknown',
                amount: $this->requireAmount($result, 'amount_refunded', 'fetch refund'),
                currency: $this->requireString($result, 'currency', 'fetch refund'),
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
