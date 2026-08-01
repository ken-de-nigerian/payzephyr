<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;

/**
 * Interface for subscription functionality
 *
 * Not all drivers will support subscriptions - drivers can implement this
 * interface to indicate subscription support.
 */
interface SupportsSubscriptionsInterface
{
    /**
     * Create a subscription plan
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO;

    /**
     * Update a subscription plan
     *
     * @param  array<string, mixed>  $updates
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO;

    /**
     * Fetch a subscription plan
     */
    public function fetchPlan(string $planCode): PlanResponseDTO;

    /**
     * List all subscription plans
     *
     * @return array<string, mixed> Array with 'data' key containing array of PlanResponseDTO or arrays
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array;

    /**
     * Create a subscription
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO;

    /**
     * Fetch subscription details
     */
    public function fetchSubscription(string $subscriptionCode): SubscriptionResponseDTO;

    /**
     * Cancel a subscription.
     *
     * Provider-specific requirements (e.g. Paystack's email confirmation
     * token) are read from $action->option() by the implementing driver, not
     * fixed in this signature - see ADR-0006.
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO;

    /**
     * Enable a disabled subscription. See cancelSubscription() - same
     * provider-specific-options pattern.
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO;

    /**
     * List customer subscriptions
     *
     * @return array<string, mixed>
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array;
}
