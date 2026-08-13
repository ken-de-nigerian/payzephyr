<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for MollieDriver.
 *
 * $transactionReference is Mollie's payment id (the same id
 * MollieDriver::verify() uses). Mollie refunds are nested resources under
 * the payment, so both refund() and fetchRefund() need the payment id -
 * fetchRefund() therefore expects "{paymentId}:{refundId}" (mirroring the
 * composite-code approach MollieSubscriptionMethods already uses for
 * subscription codes).
 */
trait MollieRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $paymentId = $request->transactionReference;

            $payload = array_filter([
                'amount' => $request->amount !== null ? [
                    'currency' => $request->currency ?? $this->config['currencies'][0] ?? 'EUR',
                    'value' => number_format($request->amount, 2, '.', ''),
                ] : null,
                'description' => $request->reason,
            ], fn ($value) => $value !== null);

            $requestOptions = ['json' => $payload];
            if ($request->idempotencyKey) {
                $requestOptions['headers'] = ['Idempotency-Key' => $request->idempotencyKey];
            }

            $response = $this->makeRequest('POST', "/v2/payments/$paymentId/refunds", $requestOptions);
            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new RefundException('Refund id not found in response. Response: '.json_encode($data));
            }

            $this->log('info', 'Refund created', [
                'refund_reference' => "$paymentId:{$data['id']}",
                'transaction_reference' => $paymentId,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: "$paymentId:{$data['id']}",
                transactionReference: $paymentId,
                status: $data['status'] ?? 'pending',
                amount: (float) ($data['amount']['value'] ?? $request->amount ?? 0),
                currency: $data['amount']['currency'] ?? 'EUR',
                reason: $data['description'] ?? $request->reason,
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
            [$paymentId, $refundId] = array_pad(explode(':', $refundReference, 2), 2, null);

            if ($paymentId === null || $refundId === null) {
                throw new RefundException("Invalid Mollie refund reference [$refundReference]. Expected format \"{paymentId}:{refundId}\".");
            }

            $response = $this->makeRequest('GET', "/v2/payments/$paymentId/refunds/$refundId");
            $data = $this->parseResponse($response);

            $refundResponse = new RefundResponseDTO(
                refundReference: "$paymentId:$refundId",
                transactionReference: $paymentId,
                status: $data['status'] ?? 'unknown',
                amount: (float) ($data['amount']['value'] ?? 0),
                currency: $data['amount']['currency'] ?? 'EUR',
                reason: $data['description'] ?? null,
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
