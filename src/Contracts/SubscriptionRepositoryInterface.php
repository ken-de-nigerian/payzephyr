<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

/**
 * Persistence boundary for SubscriptionTransaction, giving the subscription
 * write path the same concurrency-safe pattern TransactionRepositoryInterface
 * already gives PaymentTransaction.
 */
interface SubscriptionRepositoryInterface
{
    /**
     * Atomically create-or-update the subscription row for $subscriptionCode.
     *
     * Locks the existing row before writing if one already exists. If it
     * doesn't, attempts a create; if that create loses a race to a
     * concurrent request (unique constraint violation), re-resolves and
     * updates the now-existing row instead of dropping this write.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreateAtomic(string $subscriptionCode, array $attributes): SubscriptionTransaction;

    /**
     * The codes of the logged subscriptions $customerEmail holds to
     * $planCode with $provider that have not ended - status not cancelled,
     * completed or expired - newest first.
     *
     * The duplicate check reads these for a provider that cannot list
     * subscriptions (HasNoSubscriptionListing), and fetches each from the
     * provider: a logged status is the one at the last call that wrote it.
     *
     * @return list<string>
     */
    public function openSubscriptionCodes(string $provider, string $customerEmail, string $planCode): array;
}
