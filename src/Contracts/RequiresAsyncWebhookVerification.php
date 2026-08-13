<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Interface for drivers whose validateWebhook() may perform I/O (an
 * outbound API call) rather than a local HMAC computation.
 *
 * A driver implementing this is verified inside the queued ProcessWebhook
 * job instead of synchronously in WebhookRequest::authorize() whenever
 * requiresAsyncVerification() returns true for its current configuration
 * (some drivers, like Mollie, only need this for one of their possible
 * configurations, not universally).
 */
interface RequiresAsyncWebhookVerification
{
    /**
     * Whether this driver instance's current configuration requires
     * deferred (queued) webhook verification for the current request.
     */
    public function requiresAsyncVerification(): bool;
}
