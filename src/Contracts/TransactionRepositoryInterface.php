<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

/**
 * Persistence boundary for PaymentTransaction, so business logic
 * (PaymentManager, ProcessWebhook) depends on an abstraction rather than
 * static Eloquent calls - and so the concurrency-safe update pattern lives in
 * exactly one place to be reused, not re-derived per caller.
 */
interface TransactionRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentTransaction;

    public function findByReference(string $reference): ?PaymentTransaction;

    /**
     * Atomically apply $attributes to the transaction matching $reference,
     * unless it does not exist or is already in a successful terminal state.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool True if the update was applied, false if skipped (not
     *              found, or already successful).
     */
    public function updateIfNotSuccessful(string $reference, array $attributes): bool;
}
