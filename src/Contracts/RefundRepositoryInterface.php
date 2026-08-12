<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\Models\RefundTransaction;

/**
 * Persistence boundary for RefundTransaction. Mirrors
 * SubscriptionRepositoryInterface's concurrency-safe upsert pattern (ADR-0004).
 */
interface RefundRepositoryInterface
{
    /**
     * Atomically create-or-update the refund row for $refundReference.
     *
     * Locks the existing row before writing if one already exists. If it
     * doesn't, attempts a create; if that create loses a race to a
     * concurrent request (unique constraint violation), re-resolves and
     * updates the now-existing row instead of dropping this write.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreateAtomic(string $refundReference, array $attributes): RefundTransaction;

    /**
     * Sum the amount of all refunds for a transaction whose status still
     * counts toward the refunded total (pending, processing, or completed -
     * failed/cancelled refunds don't reduce the refundable balance).
     */
    public function sumRefundedAmount(string $transactionReference): float;

    /**
     * Whether a refund for $transactionReference is currently pending or
     * processing (i.e. submitted but not yet resolved). Used to reject a
     * second concurrent refund attempt on the same transaction before its
     * outcome is known - see refunds.prevent_duplicates.
     */
    public function hasInFlightRefund(string $transactionReference): bool;
}
