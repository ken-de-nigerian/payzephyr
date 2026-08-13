<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Throwable;

class SubscriptionValidator
{
    /**
     * Validate subscription creation request
     */
    public function validateCreation(
        SubscriptionRequestDTO $request,
        SupportsSubscriptionsInterface $driver
    ): void {
        $config = app('payments.config') ?? config('payments', []);
        $preventDuplicates = $config['subscriptions']['prevent_duplicates'] ?? false;

        try {
            $plan = $driver->fetchPlan($request->plan);
            $isActive = $plan->isActive();

            if (! $isActive) {
                throw new SubscriptionException("Plan $request->plan is not active");
            }
        } catch (Throwable $e) {
            throw new SubscriptionException("Failed to verify plan $request->plan: {$e->getMessage()}", 0, $e);
        }

        if ($preventDuplicates) {
            try {
                $existing = $driver->listSubscriptions(customer: $request->customer);

                $subscriptions = $existing['data'] ?? $existing;

                foreach ($subscriptions as $sub) {
                    $subPlanCode = $sub['plan']['plan_code'] ?? $sub['plan_code'] ?? null;
                    $subStatus = $sub['status'] ?? 'unknown';

                    if (
                        $subPlanCode === $request->plan &&
                        in_array(strtolower($subStatus), ['active', 'non-renewing'], true)
                    ) {
                        throw new SubscriptionException(
                            "Customer already has an active subscription to plan $request->plan. ".
                            'Please cancel the existing subscription before creating a new one.'
                        );
                    }
                }
            } catch (SubscriptionException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::warning('Failed to check for duplicate subscriptions', [
                    'error' => $e->getMessage(),
                    'customer' => $request->customer,
                ]);
            }
        }

        if ($request->authorization !== null && strlen($request->authorization) < 10) {
            throw new SubscriptionException(
                'Invalid authorization code format. Authorization codes must be at least 10 characters long.'
            );
        }
    }

    /**
     * Validate subscription cancellation.
     *
     * Only the provider-agnostic terminal-state check lives here.
     * Provider-specific requirements (e.g. Paystack's email token format)
     * are the driver's responsibility.
     */
    public function validateCancellation(
        string $subscriptionCode,
        SupportsSubscriptionsInterface $driver
    ): void {
        $subscription = $driver->fetchSubscription($subscriptionCode);

        $status = strtolower($subscription->status);
        $terminalStates = ['cancelled', 'completed', 'expired'];

        if (in_array($status, $terminalStates, true)) {
            throw new SubscriptionException(
                "Cannot cancel subscription $subscriptionCode: subscription is already in terminal state '$status'. ".
                'Terminal states cannot be modified.'
            );
        }
    }
}
