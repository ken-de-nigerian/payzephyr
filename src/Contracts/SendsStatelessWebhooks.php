<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Interface for drivers whose webhooks can say only "something changed - go
 * and look", with nothing in the body to tell one event from the next.
 *
 * Mollie's classic webhook is a payment id and nothing else, identical for
 * the payment being paid, refunded, charged back or expiring. Deduplicating
 * such a delivery - by its id or by a hash of its body - would process the
 * first status change and silently drop every later one. ProcessWebhook
 * therefore processes every delivery a driver reports as stateless. That is
 * safe precisely because the body carries no state: all a replay or a retry
 * can do is prompt another look at the provider's current, authoritative
 * state.
 */
interface SendsStatelessWebhooks
{
    /**
     * Whether this particular payload is one of the stateless kind.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function isStatelessWebhook(array $payload): bool;
}
