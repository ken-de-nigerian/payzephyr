<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\HasNoSubscriptionListing;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

final readonly class SubscriptionValidator
{
    public function __construct(private ?SubscriptionRepositoryInterface $subscriptions = null) {}

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

        if ($preventDuplicates && $driver instanceof HasNoSubscriptionListing) {
            $this->rejectLoggedDuplicate($request, $driver);
        } elseif ($preventDuplicates) {
            try {
                $existing = $driver->listSubscriptions(customer: $request->customer);
            } catch (SubscriptionException $e) {
                // The provider refused to list. The check was asked for and
                // could not be made, so the subscription is not created - and
                // the message names the setting, since the provider's own
                // would not explain why a subscribe call was listing anything.
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
                if (
                    $sub instanceof SubscriptionResponseDTO &&
                    $sub->plan === $request->plan &&
                    in_array(strtolower($sub->status), ['active', 'non-renewing'], true)
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
     * The duplicate check for a provider that cannot list subscriptions.
     *
     * The candidates are the customer's logged subscriptions to the plan that
     * have not ended; each is fetched for its current status. Without the
     * log there are no candidates to fetch, and the check cannot be made.
     *
     * @throws SubscriptionException
     */
    private function rejectLoggedDuplicate(SubscriptionRequestDTO $request, HasNoSubscriptionListing $driver): void
    {
        $config = PackageConfig::read();

        if (! $config->flag($config->flag(true, 'logging', 'enabled'), 'subscriptions', 'logging', 'enabled')) {
            throw new SubscriptionException(
                "Could not check for an existing subscription to plan $request->plan: {$driver->getName()} cannot list ".
                'subscriptions, so payments.subscriptions.prevent_duplicates reads the subscription log, and '.
                'payments.subscriptions.logging.enabled is off.'
            );
        }

        $duplicate = false;

        try {
            $codes = ($this->subscriptions ?? app(SubscriptionRepositoryInterface::class))
                ->openSubscriptionCodes($driver->getName(), $request->customer, $request->plan);

            foreach ($codes as $code) {
                if (in_array(strtolower($driver->fetchSubscription($code)->status), ['active', 'non-renewing'], true)) {
                    $duplicate = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            throw new SubscriptionException(
                "Could not check for an existing subscription to plan $request->plan, which ".
                "payments.subscriptions.prevent_duplicates requires: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($duplicate) {
            throw new SubscriptionException(
                "Customer already has an active subscription to plan $request->plan. ".
                'Please cancel the existing subscription before creating a new one.'
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
