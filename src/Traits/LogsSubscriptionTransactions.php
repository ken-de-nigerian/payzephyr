<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Carbon\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use Throwable;

/**
 * Persists subscription transactions to the database. Shared by every
 * subscription-capable driver rather than duplicated per driver.
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
        $config = PackageConfig::read();
        $loggingEnabled = $config->flag($config->flag(true, 'logging', 'enabled'), 'subscriptions', 'logging', 'enabled');

        if (! $loggingEnabled) {
            return;
        }

        try {
            $planCode = $planCode ?? $response->metadata['plan_code'] ?? $response->plan;
            $customerEmail = $customerEmail ?? $response->customer;
            $sanitizedMetadata = app(MetadataSanitizer::class)->sanitize($response->metadata);

            $attributes = [
                'provider' => $this->getName(),
                'status' => $response->status,
                'plan_code' => $planCode,
                'customer_email' => $customerEmail,
                'amount' => $response->amount,
                'currency' => $response->currency,
                'next_payment_date' => $response->nextPaymentDate ? Carbon::parse($response->nextPaymentDate)->format('Y-m-d') : null,
                'metadata' => $sanitizedMetadata,
            ];

            // The provider's state as of when the request was sent, so the
            // repository can refuse a response that finished after a newer one.
            $stateAsOf = $this->lastRequestSentAt();
            if ($stateAsOf !== null) {
                $attributes['state_as_of'] = $stateAsOf;
            }

            $this->getSubscriptionRepository()->updateOrCreateAtomic($response->subscriptionCode, $attributes);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to log subscription transaction', [
                'error' => $e->getMessage(),
                'subscription_code' => $response->subscriptionCode,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
