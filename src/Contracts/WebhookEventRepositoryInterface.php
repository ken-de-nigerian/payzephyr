<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Event-level webhook idempotency. See ADR-0005.
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
}
