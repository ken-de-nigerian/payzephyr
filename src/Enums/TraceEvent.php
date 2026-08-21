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

    case CUSTOM = 'custom';

    public function description(): string
    {
        return match ($this) {
            self::PAYMENT_INITIATED => 'Payment flow initiated',
            self::PAYMENT_COMPLETED => 'Payment successfully completed',
            self::PAYMENT_FAILED => 'Payment failed',
            self::PAYMENT_CANCELLED => 'Payment cancelled by user or system',
            self::PAYMENT_REFUNDED => 'Payment refunded',

            self::PROVIDER_REQUEST_SENT => 'Request sent to payment provider',
            self::PROVIDER_RESPONSE_RECEIVED => 'Response received from payment provider',
            self::PROVIDER_TIMEOUT => 'Provider request timed out',
            self::PROVIDER_ERROR => 'Provider returned an error',
            self::PROVIDER_EXCEPTION => 'Exception occurred during provider communication',

            self::WEBHOOK_RECEIVED => 'Webhook received from provider',
            self::WEBHOOK_DUPLICATE => 'Duplicate webhook detected',
            self::WEBHOOK_VALIDATION_FAILED => 'Webhook signature validation failed',
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

            self::CUSTOM => 'Custom trace event',
        };
    }

    /**
     * Whether this event ends the payment flow.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::PAYMENT_COMPLETED,
            self::PAYMENT_FAILED,
            self::PAYMENT_CANCELLED,
            self::RETRY_ABANDONED,
        ], true);
    }

    public function isError(): bool
    {
        return in_array($this, [
            self::PAYMENT_FAILED,
            self::PROVIDER_TIMEOUT,
            self::PROVIDER_ERROR,
            self::PROVIDER_EXCEPTION,
            self::WEBHOOK_VALIDATION_FAILED,
            self::WEBHOOK_PROCESSING_FAILED,
            self::AUTH_FAILED,
            self::VERIFICATION_FAILED,
        ], true);
    }
}
