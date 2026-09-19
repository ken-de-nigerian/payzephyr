<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Enums;

enum TraceEvent: string
{
    // Payment lifecycle
    case PAYMENT_INITIATED = 'payment.initiated';
    case PAYMENT_COMPLETED = 'payment.completed';
    case PAYMENT_FAILED = 'payment.failed';
    case PAYMENT_CANCELLED = 'payment.cancelled';
    case PAYMENT_REFUNDED = 'payment.refunded';

    // Fallback-chain decisions
    case PROVIDER_SKIPPED = 'provider.skipped';
    case CHARGE_DUPLICATE_REJECTED = 'charge.duplicate_rejected';
    case CHARGE_AMBIGUOUS = 'charge.ambiguous';

    // Provider communication
    case PROVIDER_REQUEST_SENT = 'provider.request.sent';
    case PROVIDER_RESPONSE_RECEIVED = 'provider.response.received';
    case PROVIDER_TIMEOUT = 'provider.timeout';
    case PROVIDER_ERROR = 'provider.error';
    case PROVIDER_EXCEPTION = 'provider.exception';

    // Webhooks
    case WEBHOOK_RECEIVED = 'webhook.received';
    case WEBHOOK_DUPLICATE = 'webhook.duplicate';
    case WEBHOOK_VALIDATION_FAILED = 'webhook.validation_failed';
    case WEBHOOK_QUEUE_FAILED = 'webhook.queue_failed';
    case WEBHOOK_PROCESSING_FAILED = 'webhook.processing_failed';

    // Retries
    case RETRY_SCHEDULED = 'retry.scheduled';
    case RETRY_EXECUTED = 'retry.executed';
    case RETRY_ABANDONED = 'retry.abandoned';

    // 3DS / authentication
    case AUTH_REQUIRED = 'auth.required';
    case AUTH_COMPLETED = 'auth.completed';
    case AUTH_FAILED = 'auth.failed';

    // Verification
    case VERIFICATION_STARTED = 'verification.started';
    case VERIFICATION_COMPLETED = 'verification.completed';
    case VERIFICATION_FAILED = 'verification.failed';
    case VERIFICATION_NOT_PERSISTED = 'verification.not_persisted';

    case CUSTOM = 'custom';

    public function description(): string
    {
        return match ($this) {
            self::PAYMENT_INITIATED => 'Payment flow initiated',
            self::PAYMENT_COMPLETED => 'Payment successfully completed',
            self::PAYMENT_FAILED => 'Payment failed',
            self::PAYMENT_CANCELLED => 'Payment cancelled by user or system',
            self::PAYMENT_REFUNDED => 'Payment refunded',

            self::PROVIDER_SKIPPED => 'Provider skipped without being contacted',
            self::CHARGE_DUPLICATE_REJECTED => 'Duplicate submission rejected while the first was still in flight',
            self::CHARGE_AMBIGUOUS => 'Charge outcome unknown - the provider may or may not have taken the money',

            self::PROVIDER_REQUEST_SENT => 'Request sent to payment provider',
            self::PROVIDER_RESPONSE_RECEIVED => 'Response received from payment provider',
            self::PROVIDER_TIMEOUT => 'Provider request timed out',
            self::PROVIDER_ERROR => 'Provider returned an error',
            self::PROVIDER_EXCEPTION => 'Exception occurred during provider communication',

            self::WEBHOOK_RECEIVED => 'Webhook received from provider',
            self::WEBHOOK_DUPLICATE => 'Duplicate webhook detected',
            self::WEBHOOK_VALIDATION_FAILED => 'Webhook signature validation failed',
            self::WEBHOOK_QUEUE_FAILED => 'Webhook accepted but never queued, so it will never be retried',
            self::WEBHOOK_PROCESSING_FAILED => 'Webhook processing failed',

            self::RETRY_SCHEDULED => 'Retry scheduled',
            self::RETRY_EXECUTED => 'Retry attempt executed',
            self::RETRY_ABANDONED => 'Retry attempts abandoned',

            self::AUTH_REQUIRED => '3DS or additional authentication required',
            self::AUTH_COMPLETED => 'Authentication completed',
            self::AUTH_FAILED => 'Authentication failed',

            self::VERIFICATION_STARTED => 'Payment verification started',
            self::VERIFICATION_COMPLETED => 'Payment verification completed',
            self::VERIFICATION_FAILED => 'Payment verification failed',
            self::VERIFICATION_NOT_PERSISTED => 'Provider confirmed the payment but the local record could not be updated',

            self::CUSTOM => 'Custom trace event',
        };
    }

    /**
     * Whether this event ends the payment flow.
     *
     * Timeline::terminal() returns the *first* terminal event, so this list
     * has to stay narrow: anything marked terminal that can be followed by a
     * different real outcome would misreport the payment. A single provider
     * failing inside a fallback chain is PROVIDER_ERROR rather than
     * PAYMENT_FAILED for exactly that reason - the next provider may still
     * succeed, and PAYMENT_FAILED is reserved for the chain giving up.
     *
     * CHARGE_DUPLICATE_REJECTED is deliberately absent too. Both submissions
     * share a reference and therefore share a timeline, and the rejected one
     * is not the outcome - the surviving one is.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::PAYMENT_COMPLETED,
            self::PAYMENT_FAILED,
            self::PAYMENT_CANCELLED,
            self::CHARGE_AMBIGUOUS,
            self::RETRY_ABANDONED,
        ], true);
    }

    /**
     * CHARGE_AMBIGUOUS counts as an error, but note that it makes neither
     * Timeline::succeeded() nor Timeline::failed() true. That is the honest
     * answer: nobody knows yet whether the customer was charged, and a
     * timeline that guessed would be worse than one that says so.
     */
    public function isError(): bool
    {
        return in_array($this, [
            self::PAYMENT_FAILED,
            self::CHARGE_AMBIGUOUS,
            self::PROVIDER_TIMEOUT,
            self::PROVIDER_ERROR,
            self::PROVIDER_EXCEPTION,
            self::WEBHOOK_VALIDATION_FAILED,
            self::WEBHOOK_QUEUE_FAILED,
            self::WEBHOOK_PROCESSING_FAILED,
            self::AUTH_FAILED,
            self::VERIFICATION_FAILED,
            self::VERIFICATION_NOT_PERSISTED,
        ], true);
    }
}
