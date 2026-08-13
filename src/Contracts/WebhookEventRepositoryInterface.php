<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Event-level webhook idempotency.
 */
interface WebhookEventRepositoryInterface
{
    /**
     * Record that (provider, eventKey) has been seen, if it hasn't been
     * already.
     *
     * @return bool True if this is the first time this event has been
     *              recorded (the caller should process it). False if it was
     *              already recorded (a duplicate delivery - the caller
     *              should skip processing).
     */
    public function recordIfNew(string $provider, string $eventKey): bool;

    /**
     * Remove a previously recorded (provider, eventKey) marker.
     *
     * recordIfNew() is called before processing runs, so a genuinely
     * duplicate concurrent delivery can be rejected immediately - but that
     * means a failure partway through processing (a listener throwing, a
     * transient DB error) leaves the event marked "seen" even though it was
     * never actually completed. ProcessWebhook calls this on failure so
     * Laravel's own queue retry can re-attempt the same delivery instead of
     * silently skipping it as a duplicate every time.
     */
    public function forget(string $provider, string $eventKey): void;
}
