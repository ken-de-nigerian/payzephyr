<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

/**
 * Trait providing webhook validation functionality.
 */
trait HasWebhookValidation
{
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

        $currentTime = time();
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

                if (is_string($value) && strtotime($value) !== false) {
                    return strtotime($value);
                }

                if (is_numeric($value)) {
                    return (int) $value;
                }
            }
        }

        return null;
    }
}
