<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Product;
use Stripe\StripeObject;

/**
 * Trait providing Stripe subscription functionality.
 *
 * Plans map to Prices. Immutable-price updates (amount/currency/interval)
 * create a new Price rather than mutating the existing one.
 * cancel_at_period_end distinguishes scheduled from immediate cancellation,
 * and a fully-canceled Stripe subscription cannot be re-enabled.
 */
trait StripeSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * @throws PlanException
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            $product = $this->stripe->products->create(array_filter([
                'name' => $plan->name,
                'description' => $plan->description,
            ], fn ($value) => $value !== null));

            $price = $this->stripe->prices->create([
                'unit_amount' => $plan->getAmountInMinorUnits(),
                'currency' => strtolower($plan->currency),
                'recurring' => ['interval' => $this->mapIntervalToStripe($plan->interval)],
                'product' => $product->id,
                'metadata' => $this->toStripeMetadata($plan->metadata),
            ]);

            $this->log('info', 'Subscription plan created', [
                'plan_code' => $price->id,
                'name' => $plan->name,
            ]);

            return $this->mapPriceToPlanResponse($price, $product);
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to create plan', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to create plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a subscription plan.
     *
     * Stripe Prices are immutable for amount/currency/interval - changing
     * either of those creates a new Price under the same Product instead of
     * mutating this one (Stripe's own recommended pattern; existing
     * subscriptions keep billing at their original price). Metadata,
     * nickname, and active-state changes update the existing Price in
     * place. Name/description changes update the underlying Product.
     *
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        try {
            $existingPrice = $this->stripe->prices->retrieve($planCode, ['expand' => ['product']]);
            $existingProduct = $existingPrice->product;
            $productId = $existingProduct instanceof Product ? $existingProduct->id : $existingProduct;

            if (isset($updates['name']) || isset($updates['description'])) {
                $this->stripe->products->update($productId, array_filter([
                    'name' => isset($updates['name']) ? (string) $updates['name'] : null,
                    'description' => isset($updates['description']) ? (string) $updates['description'] : null,
                ], fn ($value) => $value !== null));
            }

            if (isset($updates['amount']) || isset($updates['interval'])) {
                $price = $this->stripe->prices->create([
                    'unit_amount' => isset($updates['amount']) ? (int) round($updates['amount'] * 100) : (int) $existingPrice->unit_amount,
                    'currency' => $existingPrice->currency,
                    'recurring' => [
                        'interval' => isset($updates['interval'])
                            ? $this->mapIntervalToStripe((string) $updates['interval'])
                            : $existingPrice->recurring->interval,
                    ],
                    'product' => $productId,
                    'metadata' => isset($updates['metadata'])
                        ? $this->toStripeMetadata($updates['metadata'])
                        : $this->toStripeMetadata($existingPrice->metadata),
                ]);

                $this->log('info', 'Subscription plan updated with a new price (amount/interval changed)', [
                    'old_plan_code' => $planCode,
                    'new_plan_code' => $price->id,
                ]);
            } else {
                $mutable = array_filter([
                    'metadata' => isset($updates['metadata']) ? $this->toStripeMetadata($updates['metadata']) : null,
                    'active' => $updates['active'] ?? null,
                    'nickname' => isset($updates['nickname']) ? (string) $updates['nickname'] : null,
                ], fn ($value) => $value !== null);

                $price = $mutable !== [] ? $this->stripe->prices->update($planCode, $mutable) : $existingPrice;

                $this->log('info', 'Subscription plan updated', ['plan_code' => $planCode]);
            }

            $product = $this->stripe->products->retrieve($productId);

            return $this->mapPriceToPlanResponse($price, $product);
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to update plan', ['plan_code' => $planCode, 'error' => $e->getMessage()]);
            throw new PlanException('Failed to update plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws PlanException
     */
    public function fetchPlan(string $planCode): PlanResponseDTO
    {
        try {
            $price = $this->stripe->prices->retrieve($planCode, ['expand' => ['product']]);

            return $this->mapPriceToPlanResponse($price, is_object($price->product) ? $price->product : null);
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to get plan', ['plan_code' => $planCode, 'error' => $e->getMessage()]);
            throw new PlanException('Failed to get plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * List subscription plans.
     *
     * Stripe's list API is cursor-based, not page-numbered - $perPage is
     * honored, but only page 1 can be served faithfully. A $page > 1
     * request logs a warning and still returns page 1 rather than silently
     * returning wrong data.
     *
     * @return array<string, mixed>
     *
     * @throws PlanException
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array
    {
        try {
            if (($page ?? 1) > 1) {
                $this->log('warning', 'Stripe uses cursor-based pagination - only page 1 can be served', [
                    'requested_page' => $page,
                ]);
            }

            $prices = $this->stripe->prices->all([
                'limit' => $perPage ?? 50,
                'expand' => ['data.product'],
                'active' => true,
            ]);

            return [
                'data' => array_map(
                    fn ($price) => $this->mapPriceToPlanResponse($price, is_object($price->product) ? $price->product : null),
                    $prices->data
                ),
                'has_more' => $prices->has_more,
            ];
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to list plans', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to list plans: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a subscription.
     *
     * Requires $request->authorization (a Stripe PaymentMethod ID already
     * usable for the customer) - the same precondition Paystack's driver
     * already has (a prior authorization_code from a completed charge).
     *
     * @throws SubscriptionException
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            if (! $request->authorization) {
                throw new SubscriptionException(
                    'Stripe requires an existing PaymentMethod ID (via ->authorization()) to create a '.
                    'subscription. Charge the customer once with a saved payment method first, or use '.
                    'Stripe Checkout in subscription mode directly.'
                );
            }

            $customer = $this->findOrCreateStripeCustomer($request->customer);

            $params = array_filter([
                'customer' => $customer->id,
                'items' => [['price' => $request->plan, 'quantity' => $request->quantity ?? 1]],
                'trial_period_days' => $request->trialDays,
                'metadata' => $request->metadata,
                'default_payment_method' => $request->authorization,
            ], fn ($value) => $value !== null);

            $options = $request->idempotencyKey ? ['idempotency_key' => $request->idempotencyKey] : [];

            $subscription = $this->stripe->subscriptions->create($params, $options);

            $this->log('info', 'Subscription created', [
                'subscription_code' => $subscription->id,
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $response = $this->mapStripeSubscriptionToResponse($subscription, $customer);
            $this->logSubscription($request, $response);

            return $response;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to create subscription', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to create subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SubscriptionException
     */
    public function fetchSubscription(string $subscriptionCode): SubscriptionResponseDTO
    {
        try {
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionCode, [
                'expand' => ['customer', 'items.data.price'],
            ]);

            return $this->mapStripeSubscriptionToResponse(
                $subscription,
                is_object($subscription->customer) ? $subscription->customer : null
            );
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to fetch subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to fetch subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancel a subscription.
     *
     * $action->option('at_period_end', false) selects Stripe's two
     * cancellation modes: immediate (default, matches Paystack's
     * immediate-disable semantics) or scheduled for the end of the current
     * billing period.
     *
     * @throws SubscriptionException
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $atPeriodEnd = (bool) $action->option('at_period_end', false);

            $subscription = $atPeriodEnd
                ? $this->stripe->subscriptions->update($action->subscriptionCode, ['cancel_at_period_end' => true])
                : $this->stripe->subscriptions->cancel($action->subscriptionCode);

            $this->log('info', 'Subscription cancelled', [
                'subscription_code' => $action->subscriptionCode,
                'at_period_end' => $atPeriodEnd,
            ]);

            $response = $this->mapStripeSubscriptionToResponse($subscription);
            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to cancel subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to cancel subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Enable (resume) a subscription pending cancel_at_period_end.
     *
     * Stripe cannot reactivate a subscription already in its terminal
     * `canceled` status - only Paystack's model allows that. Throws a clear
     * exception rather than silently no-op'ing or approximating incorrect
     * behavior.
     *
     * @throws SubscriptionException
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $current = $this->stripe->subscriptions->retrieve($action->subscriptionCode);

            if ($current->status === 'canceled') {
                throw new SubscriptionException(
                    "Subscription $action->subscriptionCode is fully cancelled and cannot be reactivated on ".
                    'Stripe - create a new subscription instead. Only a subscription still pending '.
                    'cancel_at_period_end can be resumed.'
                );
            }

            $subscription = $this->stripe->subscriptions->update($action->subscriptionCode, [
                'cancel_at_period_end' => false,
            ]);

            $this->log('info', 'Subscription enabled', ['subscription_code' => $action->subscriptionCode]);

            $response = $this->mapStripeSubscriptionToResponse($subscription);
            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to enable subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to enable subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * List customer subscriptions.
     *
     * Same cursor-pagination caveat as listPlans().
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionException
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array
    {
        try {
            if (($page ?? 1) > 1) {
                $this->log('warning', 'Stripe uses cursor-based pagination - only page 1 can be served', [
                    'requested_page' => $page,
                ]);
            }

            $params = [
                'limit' => $perPage ?? 50,
                'expand' => ['data.customer', 'data.items.data.price'],
            ];

            if ($customer) {
                $customerObj = $this->findStripeCustomerByEmail($customer);
                if (! $customerObj) {
                    return ['data' => [], 'has_more' => false];
                }
                $params['customer'] = $customerObj->id;
            }

            $subscriptions = $this->stripe->subscriptions->all($params);

            return [
                'data' => array_map(
                    fn ($s) => $this->mapStripeSubscriptionToResponse($s, is_object($s->customer) ? $s->customer : null),
                    $subscriptions->data
                ),
                'has_more' => $subscriptions->has_more,
            ];
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    private function findStripeCustomerByEmail(string $email): ?object
    {
        $existing = $this->stripe->customers->all(['email' => $email, 'limit' => 1]);

        return $existing->data[0] ?? null;
    }

    private function findOrCreateStripeCustomer(string $email): object
    {
        return $this->findStripeCustomerByEmail($email) ?? $this->stripe->customers->create(['email' => $email]);
    }

    /**
     * Stripe's `metadata` request parameter is always array<string, string>.
     * Neither of this method's callers can prove that statically: a
     * StripeObject (Price::$metadata coming back from the API) stores its
     * values dynamically/untyped, and this package's own DTOs declare
     * metadata as array<string, mixed> since they're a general-purpose bag.
     * Stripe's metadata values are always strings in practice (that's the
     * API's own contract), so casting is safe rather than lossy.
     *
     * @param  array<string, mixed>|StripeObject  $metadata
     * @return array<string, string>
     */
    private function toStripeMetadata(array|StripeObject $metadata): array
    {
        $array = $metadata instanceof StripeObject ? $metadata->toArray() : $metadata;

        return array_map(strval(...), $array);
    }

    private function mapIntervalToStripe(string $interval): string
    {
        return match ($interval) {
            'daily' => 'day',
            'weekly' => 'week',
            'annually' => 'year',
            default => 'month',
        };
    }

    private function mapIntervalFromStripe(string $interval): string
    {
        return match ($interval) {
            'day' => 'daily',
            'week' => 'weekly',
            'year' => 'annually',
            default => 'monthly',
        };
    }

    private function mapPriceToPlanResponse(object $price, ?object $product = null): PlanResponseDTO
    {
        return new PlanResponseDTO(
            planCode: $price->id,
            name: $product->name ?? '',
            amount: ($price->unit_amount ?? 0) / 100,
            interval: $this->mapIntervalFromStripe($price->recurring->interval ?? 'month'),
            currency: strtoupper($price->currency),
            description: $product->description ?? null,
            metadata: (array) ($price->metadata ?? []),
            provider: $this->getName(),
        );
    }

    private function mapStripeSubscriptionToResponse(object $subscription, ?object $customer = null): SubscriptionResponseDTO
    {
        $item = $subscription->items->data[0] ?? null;
        $price = $item->price ?? null;

        return new SubscriptionResponseDTO(
            subscriptionCode: $subscription->id,
            status: $this->mapStripeSubscriptionStatus($subscription->status),
            customer: $customer->email
                ?? (is_object($subscription->customer ?? null) ? $subscription->customer->email : '')
                ?? '',
            plan: is_object($price) ? $price->id : $item->price ?? '',
            amount: is_object($price) ? ($price->unit_amount ?? 0) / 100 : 0,
            currency: is_object($price) ? strtoupper($price->currency) : 'USD',
            nextPaymentDate: isset($subscription->current_period_end)
                ? date('Y-m-d H:i:s', $subscription->current_period_end)
                : null,
            metadata: (array) ($subscription->metadata ?? []),
            provider: $this->getName(),
        );
    }

    /**
     * SubscriptionResponseDTO::status feeds Enums\SubscriptionStatus, which
     * has its own vocabulary (not the payment success/failed/pending one -
     * normalizeStatus() would be wrong here). Enums\SubscriptionStatus
     * already recognizes Stripe's native 'active', 'canceled', 'past_due',
     * and 'unpaid' as synonyms; only 'trialing' and the 'incomplete*'
     * statuses need translating to something it understands.
     */
    private function mapStripeSubscriptionStatus(string $status): string
    {
        return match ($status) {
            'trialing' => 'active',
            'incomplete' => 'attention',
            'incomplete_expired' => 'expired',
            default => $status,
        };
    }
}
