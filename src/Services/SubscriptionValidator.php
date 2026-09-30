<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

final readonly class SubscriptionValidator
{
    /**
     * Validate subscription creation request
     */
    public function validateCreation(
        SubscriptionRequestDTO $request,
        SupportsSubscriptionsInterface $driver
    ): void {
        $preventDuplicates = PackageConfig::read()->flag(false, 'subscriptions', 'prevent_duplicates');

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
            } catch (SubscriptionException $e) {
                // The provider refused or cannot list (PayPal has no such
                // API). The check was asked for and could not be made, so the
                // subscription is not created - and the message names the
                // setting, since the provider's own would not explain why a
                // subscribe call was listing anything.
                throw new SubscriptionException(
                    "Could not check for an existing subscription to plan $request->plan, which ".
                    "payments.subscriptions.prevent_duplicates requires: {$e->getMessage()}",
                    0,
                    $e
                );
            } catch (Throwable $e) {
                Log::warning('Failed to check for duplicate subscriptions', [
                    'error' => $e->getMessage(),
                    'customer' => $request->customer,
                ]);

                $existing = [];
            }

            $subscriptions = (new Payload($existing))->arrayOrNull('data') ?? $existing;

            foreach ($subscriptions as $sub) {
                [$subPlanCode, $subStatus] = $this->planAndStatus($sub);

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
        }

        if ($request->authorization !== null && strlen($request->authorization) < 10) {
            throw new SubscriptionException(
                'Invalid authorization code format. Authorization codes must be at least 10 characters long.'
            );
        }
    }

    /**
     * The plan code and status of one listed subscription.
     *
     * A driver lists subscriptions as the DTOs it maps them to; Paystack lists
     * the provider's own rows, where the plan is an object carrying its code.
     * Reading only the second shape made this check a no-op for every other
     * driver: indexing a DTO as an array is an Error, which the caller logged
     * as "failed to check" and then allowed the duplicate.
     *
     * @return array{0: string|null, 1: string}
     */
    private function planAndStatus(mixed $subscription): array
    {
        if ($subscription instanceof SubscriptionResponseDTO) {
            return [$subscription->plan, $subscription->status];
        }

        $row = Payload::of($subscription);

        return [
            $row->string('plan', 'plan_code') ?? $row->string('plan_code') ?? $row->string('plan'),
            $row->string('status') ?? 'unknown',
        ];
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
