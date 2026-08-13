<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
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
            ], fn ($value) => $value !== null);

            $requestOptions = ['json' => $payload];
            if ($request->idempotencyKey) {
                $requestOptions['headers'] = ['Idempotency-Key' => $request->idempotencyKey];
            }

            $response = $this->makeRequest('POST', "transactions/$request->transactionReference/refund", $requestOptions);
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new RefundException($data['message'] ?? 'Failed to create Flutterwave refund');
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
                amount: (float) ($result['amount_refunded'] ?? $request->amount ?? 0),
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
            $response = $this->makeRequest('GET', "refunds/$refundReference");
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new RefundException($data['message'] ?? 'Failed to fetch Flutterwave refund');
            }

            $result = $data['data'][0] ?? $data['data'] ?? [];

            $refundResponse = new RefundResponseDTO(
                refundReference: (string) ($result['id'] ?? $refundReference),
                transactionReference: (string) ($result['transaction_id'] ?? ''),
                status: $result['status'] ?? 'unknown',
                amount: (float) ($result['amount_refunded'] ?? 0),
                currency: $result['currency'] ?? 'NGN',
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
