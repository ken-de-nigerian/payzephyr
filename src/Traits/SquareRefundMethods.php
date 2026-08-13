<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for SquareDriver.
 *
 * $transactionReference is Square's payment_id (the same id
 * SquareDriver::verifyByPaymentId() resolves).
 */
trait SquareRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $amountMoney = $request->amount !== null
                ? [
                    'amount' => $request->getAmountInMinorUnits(),
                    'currency' => $request->currency ?? $this->config['currencies'][0] ?? 'USD',
                ] : $this->fetchOriginalPaymentAmountMoney($request->transactionReference);

            $payload = array_filter([
                'idempotency_key' => $request->idempotencyKey ?? uniqid('square_refund_', true),
                'payment_id' => $request->transactionReference,
                'amount_money' => $amountMoney,
                'reason' => $request->reason,
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/v2/refunds', ['json' => $payload]);
            $data = $this->parseResponse($response);

            if (! isset($data['refund'])) {
                $errorMessage = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? 'Failed to create Square refund';
                throw new RefundException($errorMessage);
            }

            $result = $data['refund'];

            $this->log('info', 'Refund created', [
                'refund_reference' => $result['id'],
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['id'],
                transactionReference: $result['payment_id'] ?? $request->transactionReference,
                status: $result['status'] ?? 'pending',
                amount: ($result['amount_money']['amount'] ?? 0) / 100,
                currency: $result['amount_money']['currency'] ?? 'USD',
                reason: $result['reason'] ?? $request->reason,
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
     * Fetch the original payment's amount_money for a full (no explicit
     * amount) Square refund - see the comment in refund() above.
     *
     * @return array{amount: int, currency: string}
     *
     * @throws RefundException
     */
    private function fetchOriginalPaymentAmountMoney(string $paymentId): array
    {
        try {
            $response = $this->makeRequest('GET', "/v2/payments/$paymentId");
            $data = $this->parseResponse($response);
        } catch (Throwable $e) {
            throw new RefundException(
                "Cannot issue a full refund for Square payment [$paymentId]: failed to look up the original payment's amount. Pass an explicit amount() instead. (".$e->getMessage().')',
                0,
                $e
            );
        }

        $amountMoney = $data['payment']['amount_money'] ?? null;

        if (! is_array($amountMoney) || ! isset($amountMoney['amount'], $amountMoney['currency'])) {
            throw new RefundException(
                "Cannot issue a full refund for Square payment [$paymentId]: the original payment's amount could not be determined. Pass an explicit amount() instead."
            );
        }

        return [
            'amount' => (int) $amountMoney['amount'],
            'currency' => (string) $amountMoney['currency'],
        ];
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', "/v2/refunds/$refundReference");
            $data = $this->parseResponse($response);

            if (! isset($data['refund'])) {
                $errorMessage = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? 'Failed to fetch Square refund';
                throw new RefundException($errorMessage);
            }

            $result = $data['refund'];

            $refundResponse = new RefundResponseDTO(
                refundReference: $result['id'] ?? $refundReference,
                transactionReference: $result['payment_id'] ?? '',
                status: $result['status'] ?? 'unknown',
                amount: ($result['amount_money']['amount'] ?? 0) / 100,
                currency: $result['amount_money']['currency'] ?? 'USD',
                reason: $result['reason'] ?? null,
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
