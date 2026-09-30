<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use Throwable;

/**
 * Trait providing webhook validation functionality.
 */
trait HasWebhookValidation
{
    /**
     * When the webhook being validated reached the application, if that was
     * not just now. See setWebhookReceivedAt().
     */
    private ?int $webhookReceivedAt = null;

    /**
     * Measure the replay window from when a webhook was received rather than
     * from the moment it is validated.
     *
     * Drivers that verify asynchronously do it in the queued job, which runs
     * whenever a worker gets to it. Measured from then, a queue more than a
     * few minutes behind - a backlog, a deploy, a restarted worker - pushes
     * every genuine delivery outside the window, and the job discards it for
     * good. ProcessWebhook sets this around its validateWebhook() call and
     * clears it afterwards; pass null to measure from now again.
     */
    public function setWebhookReceivedAt(?int $timestamp): void
    {
        $this->webhookReceivedAt = $timestamp;
    }

    /**
     * Validate webhook timestamp to prevent replay attacks.
     *
     * @param  array<string, mixed>  $payload  Webhook payload
     * @param  int|null  $toleranceSeconds  Allowed time difference; null reads
     *                                      payments.security.webhook_timestamp_tolerance
     */
    protected function validateWebhookTimestamp(array $payload, ?int $toleranceSeconds = null): bool
    {
        $toleranceSeconds ??= $this->webhookTimestampTolerance();
        $timestamp = $this->extractWebhookTimestamp($payload);

        if ($timestamp === null) {
            $this->log('warning', 'Webhook timestamp missing or unrecognized - rejecting to prevent replay attacks', [
                'hint' => 'Expected a recognizable timestamp field. If this provider legitimately never sends one, override extractWebhookTimestamp() in its driver rather than disabling this check.',
            ]);

            return false;
        }

        $currentTime = $this->webhookReceivedAt ?? time();
        $timeDifference = abs($currentTime - $timestamp);

        if ($timeDifference > $toleranceSeconds) {
            $this->log('warning', 'Webhook timestamp outside tolerance window', [
                'timestamp' => $timestamp,
                'current_time' => $currentTime,
                'difference_seconds' => $timeDifference,
                'tolerance_seconds' => $toleranceSeconds,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Extract timestamp from webhook payload.
     *
     * Checks the flat, top-level field names. Of the bundled drivers only
     * PayPal (`create_time`) and Square (`created_at`) use it, because theirs
     * are the only payload timestamps that mean "when this event was
     * created" (ADR-0017). A window on any other kind of timestamp - when the
     * transaction or subscription was created - rejects real events.
     *
     * @param  array<string, mixed>  $payload
     * @return int|null Unix timestamp
     */
    protected function extractWebhookTimestamp(array $payload): ?int
    {
        return $this->matchTimestampField($payload);
    }

    /**
     * The field-name matching behind extractWebhookTimestamp(). Not itself an
     * override point - drivers needing different field names override
     * extractWebhookTimestamp() instead.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchTimestampField(array $payload): ?int
    {
        $timestampFields = [
            'timestamp',
            'created_at',
            'createdAt',
            'created',        // Stripe: Event.created
            'create_time',    // PayPal: webhook event notification
            'paid_at',        // Paystack: data.paid_at
            'paidOn',         // Monnify: SUCCESSFUL_TRANSACTION eventData
            'completedOn',    // Monnify: SUCCESSFUL_DISBURSEMENT eventData
            'createdOn',      // Monnify: eventData fallback
            'event_time',
            'eventTime',
            'time',
        ];

        foreach ($timestampFields as $field) {
            if (isset($payload[$field])) {
                $value = $payload[$field];
                $candidate = null;

                $parsed = is_string($value) ? strtotime($value) : false;

                if ($parsed !== false) {
                    $candidate = $parsed;
                } elseif (is_numeric($value)) {
                    $candidate = (int) $value;
                }

                if ($candidate !== null && $this->isPlausibleUnixTimestamp($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Whether $candidate falls within a sane calendar-year range for a real
     * Unix timestamp (2000-01-01 through 2100-01-01), wide enough to never
     * reject a genuine provider timestamp but narrow enough to reject
     * small non-timestamp values (durations, counters) that a generically
     * named field like "time" could otherwise pick up.
     */
    private function isPlausibleUnixTimestamp(int $candidate): bool
    {
        return $candidate >= 946684800 && $candidate < 4102444800;
    }

    /**
     * The configured replay window, in seconds.
     *
     * `payments.security.webhook_timestamp_tolerance` was documented as the
     * window for every provider while only Paddle read it; every other driver
     * used the constant, so widening it did nothing. A value that is not a
     * positive number falls back to the default rather than rejecting every
     * webhook (0) or accepting any age (a negative abs() comparison).
     */
    protected function webhookTimestampTolerance(): int
    {
        $configured = PackageConfig::read()->float('security', 'webhook_timestamp_tolerance');

        return $configured !== null && (int) $configured > 0
            ? (int) $configured
            : PaymentConstants::WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS;
    }

    /**
     * The window, in seconds, for an event-creation timestamp.
     *
     * Unlike a signed delivery timestamp (Stripe's `t=`, Paddle's `ts=`),
     * which is fresh on every attempt, an event's creation time is repeated
     * by every retry, so its window has to outlast the provider's retry
     * schedule or a genuine retry is rejected. Read from
     * `payments.webhook.events.replay_window`; a value that is not a positive
     * number falls back to 72 hours. See ADR-0017.
     */
    protected function webhookReplayWindow(): int
    {
        $configured = PackageConfig::read()->float('webhook', 'events', 'replay_window');

        return $configured !== null && (int) $configured > 0
            ? (int) $configured
            : PaymentConstants::WEBHOOK_REPLAY_WINDOW_SECONDS;
    }

    /**
     * How old, in seconds, a delivery can be before this driver's own checks
     * reject it - or null when nothing does, and deduplication alone stops a
     * replay.
     *
     * payzephyr:webhooks:prune only deletes a provider's deduplication rows
     * once they are older than this, because a row deleted inside the window
     * lets a replay through. Null by default: a driver claims a horizon only
     * if its validateWebhook() actually enforces one.
     */
    public function webhookReplayHorizon(): ?int
    {
        return null;
    }

    /**
     * Whether a failed call to a provider's verification API was the provider
     * answering "this is not genuine", as opposed to not answering at all.
     *
     * Drivers that verify by calling the provider (PayPal, and Mollie without
     * a webhook secret) do it in the queued job, after the provider has
     * already been told 202 and will not send the delivery again. Reading an
     * outage as a rejection there discards a genuine webhook for good. So
     * only a 4xx the request itself earned counts; a timeout, a 5xx, a 429, or
     * a 401/403 against our own credentials is none of the sender's doing,
     * and the caller should throw so the job's retries get another go.
     */
    protected function isDefinitiveVerificationRejection(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ClientException) {
                return ! in_array($current->getResponse()->getStatusCode(), [
                    HttpStatusCodes::UNAUTHORIZED,
                    HttpStatusCodes::FORBIDDEN,
                    HttpStatusCodes::REQUEST_TIMEOUT,
                    HttpStatusCodes::TOO_MANY_REQUESTS,
                ], true);
            }
        }

        return false;
    }
}
