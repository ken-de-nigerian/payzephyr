<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Carbon\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;
use Throwable;

/**
 * Persists subscription transactions to the database. Shared by every
 * subscription-capable driver (Paystack, Stripe, PayPal) rather than
 * duplicated per driver - see ADR-0009.
 *
 * Requires the consuming class to also use LogsToPaymentChannel (for log())
 * and to expose getSubscriptionRepository() (AbstractDriver already does).
 */
trait LogsSubscriptionTransactions
{
    /**
     * Log subscription transaction to database.
     *
     * This method respects the config('payments.subscriptions.logging.enabled') setting
     * and handles errors gracefully to prevent breaking subscription operations.
     * It will create a new record or update an existing one if the subscription_code already exists.
     */
    protected function logSubscription(
        SubscriptionRequestDTO $request,
        SubscriptionResponseDTO $response
    ): void {
        $this->logSubscriptionFromResponse($response, $request->plan, $request->customer);
    }

    /**
     * Log subscription transaction from response DTO.
     *
     * This method can be used when we don't have the original request DTO,
     * such as when updating subscription status from webhooks or after fetch operations.
     */
    protected function logSubscriptionFromResponse(
        SubscriptionResponseDTO $response,
        ?string $planCode = null,
        ?string $customerEmail = null
    ): void {
        $config = app('payments.config') ?? config('payments', []);
        $loggingEnabled = $config['subscriptions']['logging']['enabled'] ?? ($config['logging']['enabled'] ?? true);

        if (! $loggingEnabled) {
            return;
        }

        try {
            $planCode = $planCode ?? $response->metadata['plan_code'] ?? $response->plan;
            $customerEmail = $customerEmail ?? $response->customer;

            // Metadata here can carry values a merchant integration passed
            // straight from customer input (e.g. Subscription::metadata())
            // and is echoed back verbatim by every provider - sanitize it
            // the same way PaymentManager::charge() already sanitizes
            // PaymentTransaction::$metadata before persisting, so
            // subscription_transactions doesn't become an unsanitized
            // stored-XSS vector that payment_transactions already guards
            // against.
            $sanitizedMetadata = app(MetadataSanitizer::class)->sanitize($response->metadata);

            // Concurrency-safe create-or-update (lock + create-race retry) -
            // see ADR-0004. Replaces a bare updateOrCreate() that could
            // silently drop this event's data under concurrent webhook
            // delivery for the same subscription_code.
            $this->getSubscriptionRepository()->updateOrCreateAtomic(
                $response->subscriptionCode,
                [
                    'provider' => $this->getName(),
                    'status' => $response->status,
                    'plan_code' => $planCode,
                    'customer_email' => $customerEmail,
                    'amount' => $response->amount,
                    'currency' => $response->currency,
                    'next_payment_date' => $response->nextPaymentDate ? Carbon::parse($response->nextPaymentDate)->format('Y-m-d') : null,
                    'metadata' => $sanitizedMetadata,
                ]
            );
        } catch (Throwable $e) {
            $this->log('error', 'Failed to log subscription transaction', [
                'error' => $e->getMessage(),
                'subscription_code' => $response->subscriptionCode,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
