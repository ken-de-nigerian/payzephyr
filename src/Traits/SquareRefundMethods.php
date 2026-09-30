<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Support\Payload;
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
                    'currency' => $request->currency ?? $this->settings()->string('currencies', 0) ?? 'USD',
                ] : $this->fetchOriginalPaymentAmountMoney($request->transactionReference);

            $payload = array_filter([
                'idempotency_key' => $request->idempotencyKey ?? uniqid('square_refund_', true),
                'payment_id' => $request->transactionReference,
                'amount_money' => $amountMoney,
                'reason' => $request->reason,
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/v2/refunds', ['json' => $payload]);
            $data = $this->parseResponse($response);

            $result = Payload::of($data)->arrayOrNull('refund');

            if ($result === null) {
                throw new RefundException($this->errorDetail($data) ?? 'Failed to create Square refund');
            }

            $refund = new Payload($result);
            $refundId = $this->requireString($result, 'id', 'refund');

            $this->log('info', 'Refund created', [
                'refund_reference' => $refundId,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refundId,
                transactionReference: $refund->string('payment_id') ?? $request->transactionReference,
                status: $refund->string('status') ?? 'pending',
                amount: $this->requireAmount($this->requireArray($result, 'amount_money', 'refund'), 'amount', 'refund') / 100,
                currency: $this->requireString($this->requireArray($result, 'amount_money', 'refund'), 'currency', 'refund'),
                reason: $refund->string('reason') ?? $request->reason,
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
            $response = $this->makeRequest('GET', '/v2/payments/'.rawurlencode($paymentId));
            $data = $this->parseResponse($response);
        } catch (Throwable $e) {
            throw new RefundException(
                "Cannot issue a full refund for Square payment [$paymentId]: failed to look up the original payment's amount. Pass an explicit amount() instead. (".$e->getMessage().')',
                0,
                $e
            );
        }

        $amountMoney = Payload::of($data)->at('payment', 'amount_money');
        $amount = $amountMoney->int('amount');
        $currency = $amountMoney->string('currency');

        if ($amount === null || $currency === null) {
            throw new RefundException(
                "Cannot issue a full refund for Square payment [$paymentId]: the original payment's amount could not be determined. Pass an explicit amount() instead."
            );
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/v2/refunds/'.rawurlencode($refundReference));
            $data = $this->parseResponse($response);

            $result = Payload::of($data)->arrayOrNull('refund');

            if ($result === null) {
                throw new RefundException($this->errorDetail($data) ?? 'Failed to fetch Square refund');
            }

            $refund = new Payload($result);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refund->string('id') ?? $refundReference,
                transactionReference: $refund->string('payment_id') ?? '',
                status: $refund->string('status') ?? 'unknown',
                amount: $this->requireAmount($this->requireArray($result, 'amount_money', 'refund'), 'amount', 'refund') / 100,
                currency: $this->requireString($this->requireArray($result, 'amount_money', 'refund'), 'currency', 'refund'),
                reason: $refund->string('reason'),
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
