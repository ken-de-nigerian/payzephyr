<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

class RefundValidator
{
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {}

    /**
     * Validate a refund request.
     *
     * Two independent checks:
     * 1. In-flight duplicate guard (config('payments.refunds.prevent_duplicates'),
     *    default true): rejects a second refund attempt on a transaction
     *    that already has a pending/processing refund, protecting against
     *    accidental double-submission (e.g. a double-clicked "refund"
     *    button) before the first attempt's outcome is known. This does
     *    NOT block a *new* refund once an earlier one has reached a
     *    terminal state (completed/failed/cancelled) - sequential partial
     *    refunds remain fully supported.
     * 2. Over-refund guard: best-effort, since the original
     *    PaymentTransaction row may not exist (a different process/DB
     *    logged it, or logging was disabled), in which case this check is
     *    skipped rather than blocking the refund - it is not this
     *    validator's job to require local logging to be enabled for
     *    refunds to work.
     */
    public function validateRefund(RefundRequestDTO $request): void
    {
        $config = app('payments.config') ?? config('payments', []);
        $preventDuplicates = $config['refunds']['prevent_duplicates'] ?? true;

        if ($preventDuplicates && $this->refundRepository->hasInFlightRefund($request->transactionReference)) {
            throw new RefundException(
                "A refund is already in progress for transaction {$request->transactionReference}. ".
                'Wait for it to resolve before submitting another.'
            );
        }

        try {
            $transaction = $this->transactionRepository->findByReference($request->transactionReference);
        } catch (Throwable $e) {
            Log::warning('Failed to look up original transaction for refund validation', [
                'error' => $e->getMessage(),
                'transaction_reference' => $request->transactionReference,
            ]);

            return;
        }

        if ($transaction === null) {
            return;
        }

        $originalAmount = (float) $transaction->amount;
        $alreadyRefunded = $this->refundRepository->sumRefundedAmount($request->transactionReference);
        $remaining = $originalAmount - $alreadyRefunded;

        if ($remaining <= 0) {
            throw new RefundException(
                "Transaction {$request->transactionReference} has already been fully refunded."
            );
        }

        $requestedAmount = $request->amount ?? $remaining;

        if ($requestedAmount > $remaining + 0.01) {
            throw new RefundException(
                "Refund amount ($requestedAmount) exceeds the remaining refundable balance ($remaining) for transaction {$request->transactionReference}."
            );
        }
    }
}
