<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
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

            if ($e instanceof ChargeException && $e->isAmbiguousProviderOutcome()) {
                throw new RefundException(
                    'The refund request reached Paddle but its response was lost, so it may already have been '.
                    'created. '.$this->describeExistingRefunds($request->transactionReference).' '.
                    'Paddle has no idempotency key, so PayZephyr will not retry this for you - reconcile against '.
                    'the adjustments above before issuing another refund. Original error: '.$e->getMessage(),
                    0,
                    $e
                );
            }

            throw new RefundException('Failed to create refund: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Report what refunds Paddle currently holds against a transaction.
     *
     * Paddle's own guidance for a create whose response was lost is to "list
     * or get the entity to check whether it already exists", because the API
     * accepts no client-supplied idempotency key. PayZephyr cannot act on that
     * automatically: an adjustment carries no custom_data and no idempotency
     * field, so a retry of one $10 refund and a deliberate second $10 refund
     * are indistinguishable. Silently collapsing the second into the first
     * would be its own money bug - the merchant believes two refunds went out
     * and the customer received one.
     *
     * So this gathers the facts and puts them in the exception instead, and a
     * human decides. Guarded throughout: this runs while an error is already
     * being reported, and must not replace it with a different one.
     */
    private function describeExistingRefunds(string $transactionId): string
    {
        try {
            $response = $this->makeRequest('GET', '/adjustments', [
                'query' => ['transaction_id' => $transactionId],
            ]);

            $adjustments = $this->parseResponse($response)['data'] ?? [];

            $refunds = array_values(array_filter(
                is_array($adjustments) ? $adjustments : [],
                fn ($adjustment): bool => is_array($adjustment) && ($adjustment['action'] ?? null) === 'refund'
            ));

            if ($refunds === []) {
                return "Paddle currently reports no refund adjustments against transaction [$transactionId], which suggests the refund was not created.";
            }

            $described = implode(', ', array_map(
                fn (array $r): string => sprintf(
                    '%s (%s %s, %s)',
                    $r['id'] ?? 'unknown id',
                    $r['totals']['total'] ?? '?',
                    $r['currency_code'] ?? '?',
                    $r['status'] ?? 'unknown status',
                ),
                $refunds
            ));

            return sprintf(
                'Paddle currently reports %d refund adjustment(s) against transaction [%s]: %s.',
                count($refunds),
                $transactionId,
                $described
            );
        } catch (Throwable $lookupError) {
            return sprintf(
                'PayZephyr could not list the existing adjustments for transaction [%s] to tell you either way (%s).',
                $transactionId,
                $lookupError->getMessage()
            );
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
            throw new RefundException("Cannot issue a partial refund for Paddle transaction [$transactionId]: the transaction did not report a currency_code, so the refund amount cannot be converted safely.");
        }

        return [(string) $lineItems[0]['id'], $currency];
    }

    /**
     * @param  array<string, mixed>  $adjustment
     *
     * @throws ChargeException
     */
    private function mapAdjustmentToResponse(array $adjustment, ?string $reason = null): RefundResponseDTO
    {
        $currency = strtoupper($this->requireString($adjustment, 'currency_code', 'refund'));
        $totals = $this->requireArray($adjustment, 'totals', 'refund');

        return new RefundResponseDTO(
            refundReference: $this->requireString($adjustment, 'id', 'refund'),
            transactionReference: $this->requireString($adjustment, 'transaction_id', 'refund'),
            status: $this->mapAdjustmentStatus((string) ($adjustment['status'] ?? '')),
            amount: $this->fromMinorUnits($this->requireAmount($totals, 'total', 'refund'), $currency),
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
