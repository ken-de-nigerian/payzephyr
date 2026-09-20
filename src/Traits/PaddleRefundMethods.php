<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for PaddleDriver.
 *
 * Paddle has no refunds resource: refunds are adjustments with
 * `action: refund` against a completed transaction. $transactionReference is
 * therefore Paddle's transaction id (txn_...), the same id verify() queries by.
 *
 * A full refund adjusts the transaction's grand total (`type: full`). A partial
 * refund must name the transaction item it applies to, so it reads
 * `details.line_items` back from the transaction first; PayZephyr charges
 * create single-item transactions, and a partial refund of a multi-item
 * transaction is rejected rather than guessed at. That same lookup supplies the
 * transaction's `currency_code`, which decides whether the refund amount is
 * multiplied into minor units - a detail no other driver has to get right,
 * and one Paddle would not complain about getting wrong.
 */
trait PaddleRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $payload = [
                'action' => 'refund',
                'transaction_id' => $request->transactionReference,
                'reason' => $request->reason ?? 'Refund requested',
            ];

            if ($request->amount === null) {
                $payload['type'] = 'full';
            } else {
                // Paddle is the only bundled provider where the currency
                // changes the numeric amount sent (zero-decimal currencies are
                // not multiplied by 100), and it accepts any partial amount
                // under the line-item total without complaint. Guessing the
                // currency from config would therefore turn a $25.50 refund
                // into a silent $0.25 one whenever a zero-decimal currency
                // happens to sit first in the configured list. The
                // transaction's own currency_code is authoritative, and
                // resolveRefundTarget() is already fetching that transaction
                // for the line item, so it costs no extra call.
                [$itemId, $transactionCurrency] = $this->resolveRefundTarget($request->transactionReference);

                $payload['type'] = 'partial';
                $payload['items'] = [[
                    'item_id' => $itemId,
                    'type' => 'partial',
                    'amount' => $this->toMinorUnits($request->amount, $transactionCurrency),
                ]];
            }

            $response = $this->makeRequest('POST', '/adjustments', ['json' => $payload]);
            $data = $this->parseResponse($response)['data'] ?? [];

            $this->log('info', 'Refund created', [
                'refund_reference' => $data['id'] ?? null,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = $this->mapAdjustmentToResponse($data, $request->reason);
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
     * Paddle has no get-one-adjustment endpoint, so a single refund is fetched
     * by filtering the list endpoint on its id.
     */
    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/adjustments', [
                'query' => ['id' => $refundReference],
            ]);
            $data = $this->parseResponse($response)['data'] ?? [];
            $adjustment = $data[0] ?? null;

            if (! is_array($adjustment)) {
                throw new RefundException("Paddle adjustment [$refundReference] not found");
            }

            $refundResponse = $this->mapAdjustmentToResponse($adjustment);
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

    /**
     * Resolve the single line item a partial refund applies to, and the
     * transaction's own currency, from one GET of the transaction.
     *
     * @return array{0: string, 1: string} [item_id, currency_code]
     *
     * @throws RefundException
     */
    private function resolveRefundTarget(string $transactionId): array
    {
        try {
            $response = $this->makeRequest('GET', '/transactions/'.rawurlencode($transactionId));
            $transaction = $this->parseResponse($response)['data'] ?? [];
        } catch (Throwable $e) {
            throw new RefundException("Cannot issue a partial refund for Paddle transaction [$transactionId]: failed to look up its line items. (".$e->getMessage().')', 0, $e);
        }

        $lineItems = $transaction['details']['line_items'] ?? [];

        if (count($lineItems) !== 1 || empty($lineItems[0]['id'])) {
            throw new RefundException("Cannot issue a partial refund for Paddle transaction [$transactionId]: expected exactly one line item, found ".count($lineItems).'. Issue a full refund instead, or create the adjustment directly through Paddle.');
        }

        $currency = strtoupper((string) ($transaction['currency_code'] ?? ''));

        if ($currency === '') {
            // Without the transaction's currency the minor-unit conversion is
            // a guess, and guessing wrong under-refunds silently (see the
            // comment in refund()). Refuse rather than send a number that may
            // be off by a factor of 100.
            throw new RefundException("Cannot issue a partial refund for Paddle transaction [$transactionId]: the transaction did not report a currency_code, so the refund amount cannot be converted safely.");
        }

        return [(string) $lineItems[0]['id'], $currency];
    }

    /**
     * @param  array<string, mixed>  $adjustment
     */
    private function mapAdjustmentToResponse(array $adjustment, ?string $reason = null): RefundResponseDTO
    {
        $currency = strtoupper((string) ($adjustment['currency_code'] ?? 'USD'));

        return new RefundResponseDTO(
            refundReference: (string) ($adjustment['id'] ?? ''),
            transactionReference: (string) ($adjustment['transaction_id'] ?? ''),
            status: $this->mapAdjustmentStatus((string) ($adjustment['status'] ?? '')),
            amount: $this->fromMinorUnits($adjustment['totals']['total'] ?? 0, $currency),
            currency: $currency,
            reason: $adjustment['reason'] ?? $reason,
            metadata: [
                'paddle_adjustment_status' => $adjustment['status'] ?? null,
                'paddle_customer_id' => $adjustment['customer_id'] ?? null,
            ],
            provider: $this->getName(),
        );
    }

    /**
     * Paddle's adjustment vocabulary, mapped to the package's RefundStatus
     * values. `pending_approval` and `approved` are Paddle-specific spellings
     * RefundStatus::fromString() doesn't recognize, and an unmapped status
     * silently becomes PENDING, which would report an approved refund as
     * still in flight.
     */
    private function mapAdjustmentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'pending_approval' => 'pending',
            'approved' => 'completed',
            'rejected' => 'failed',
            'reversed' => 'cancelled',
            default => $status !== '' ? $status : 'pending',
        };
    }
}
