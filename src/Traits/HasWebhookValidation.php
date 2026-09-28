<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
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
     * @param  int  $toleranceSeconds  Allowed time difference (default: 300 = 5 minutes)
     */
    protected function validateWebhookTimestamp(array $payload, int $toleranceSeconds = PaymentConstants::WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS): bool
    {
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
     * Checks the flat, top-level field names shared by providers whose webhook
     * envelope carries the timestamp at the root (Stripe, PayPal, Square,
     * Monnify's nested eventData once unwrapped by a driver
     * override). Providers that nest the timestamp inside a sub-object
     * (Paystack/Flutterwave under `data`, OPay under `payload`, Monnify under
     * `eventData`) must override this method: see extractWebhookTimestampFrom().
     *
     * @param  array<string, mixed>  $payload
     * @return int|null Unix timestamp
     */
    protected function extractWebhookTimestamp(array $payload): ?int
    {
        return $this->matchTimestampField($payload);
    }

    /**
     * Extract a timestamp from a named sub-object of the payload, falling back
     * to the top level if the key is absent.
     *
     * Shared helper for drivers whose provider nests event data under a single
     * well-known key (Paystack/Flutterwave: 'data', OPay: 'payload',
     * Monnify: 'eventData'), so each driver's override stays a one-liner
     * instead of duplicating the field-matching logic below.
     *
     * Deliberately calls matchTimestampField() directly rather than
     * $this->extractWebhookTimestamp(): the latter is the very method a
     * driver override replaces, so calling it here would dispatch back into
     * that override and recurse infinitely.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractWebhookTimestampFrom(array $payload, string $nestedKey): ?int
    {
        $nested = $payload[$nestedKey] ?? null;

        return $this->matchTimestampField(is_array($nested) ? $nested : $payload);
    }

    /**
     * The actual field-name matching logic, factored out of
     * extractWebhookTimestamp() so it can be reused by
     * extractWebhookTimestampFrom() without polymorphic re-dispatch into a
     * driver's override (see that method's docblock). Not itself an override
     * point - drivers needing different field names override
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

                if (is_string($value) && strtotime($value) !== false) {
                    $candidate = strtotime($value);
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
