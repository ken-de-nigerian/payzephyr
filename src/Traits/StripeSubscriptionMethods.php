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
use KenDeNigerian\PayZephyr\Support\Payload;
use Stripe\Exception\ApiErrorException;

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
                'metadata' => $this->stripeMetadata($plan->metadata),
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
     * @param  array<array-key, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        SubscriptionPlanDTO::assertValidUpdates($updates);

        $changes = new Payload($updates);

        try {
            $existingPrice = $this->stripe->prices->retrieve($planCode, ['expand' => ['product']]);
            $existing = $this->stripePayload($existingPrice);

            // Expanded, the product is an object carrying its id; otherwise it
            // is the id.
            $productId = $existing->string('product', 'id') ?? $this->requireString($existing->all(), 'product', 'plan');

            $name = $changes->string('name');
            $description = $changes->string('description');

            if ($name !== null || $description !== null) {
                $this->stripe->products->update($productId, array_filter([
                    'name' => $name,
                    'description' => $description,
                ], fn ($value) => $value !== null));
            }

            $amount = $changes->float('amount');
            $interval = $changes->string('interval');
            $metadata = $changes->arrayOrNull('metadata');

            if ($amount !== null || $interval !== null) {
                // Stripe prices are immutable, so changing the amount or the
                // interval means cloning this price into a new one. Two kinds of
                // price cannot be cloned faithfully from the fields below, and
                // both used to be cloned anyway - silently:
                //
                // - A metered price. The clone carried no usage_type, so Stripe
                //   created a licensed price: customers moved onto it are billed
                //   a flat amount per interval instead of for what they used.
                // - A tiered price with no amount supplied. It has no unit_amount,
                //   which `(int)` turned into 0 - a free fixed-price plan.
                //
                // Neither is something PayZephyr can model, so both are refused
                // with an explanation rather than approximated.
                if ($existing->string('recurring', 'usage_type') === 'metered') {
                    throw new PlanException(
                        "Cannot change the amount or interval of metered plan [$planCode] through PayZephyr: cloning it would ".
                        'create a flat, licensed price and change how every subscriber on it is billed. Create the new metered price in Stripe directly.'
                    );
                }

                $unitAmount = $amount !== null ? (int) round($amount * 100) : $existing->int('unit_amount');

                if ($unitAmount === null) {
                    throw new PlanException(
                        "Cannot change the interval of plan [$planCode] without an amount: it has no fixed unit amount ".
                        '(a tiered price), and cloning it would create a free plan. Pass an amount, or create the new price in Stripe directly.'
                    );
                }

                $price = $this->stripe->prices->create([
                    'unit_amount' => $unitAmount,
                    'currency' => $this->requireString($existing->all(), 'currency', 'plan'),
                    'recurring' => [
                        'interval' => $interval !== null
                            ? $this->mapIntervalToStripe($interval)
                            : $this->requireString($existing->array('recurring'), 'interval', 'plan'),
                    ],
                    'product' => $productId,
                    'metadata' => $this->stripeMetadata($metadata ?? $existing->array('metadata')),
                ]);

                $this->log('info', 'Subscription plan updated with a new price (amount/interval changed)', [
                    'old_plan_code' => $planCode,
                    'new_plan_code' => $price->id,
                ]);
            } else {
                $mutable = array_filter([
                    'metadata' => $metadata === null ? null : $this->stripeMetadata($metadata),
                    'active' => $changes->onOff('active'),
                    'nickname' => $changes->string('nickname'),
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

            return $this->mapPriceToPlanResponse($price);
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
     * @return array<array-key, mixed>
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
                    fn (object $price) => $this->mapPriceToPlanResponse($price),
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
                'customer' => $this->requireString($customer->all(), 'id', 'customer'),
                'items' => [['price' => $request->plan, 'quantity' => $request->quantity ?? 1]],
                'trial_period_days' => $request->trialDays,
                'metadata' => $this->stripeMetadata($request->metadata),
                'default_payment_method' => $request->authorization,
            ], fn ($value) => $value !== null);

            $options = $request->idempotencyKey ? ['idempotency_key' => $request->idempotencyKey] : [];

            // Stripe goes through its SDK, not makeRequest(), so the send time
            // that orders subscription writes is recorded here.
            $this->markRequestSent();
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

            return $this->mapStripeSubscriptionToResponse($subscription);
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
            $atPeriodEnd = $action->flagOption('at_period_end', false);

            $this->markRequestSent();
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

            $this->markRequestSent();
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
     * @return array<array-key, mixed>
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
                if ($customerObj === null) {
                    return ['data' => [], 'has_more' => false];
                }
                $params['customer'] = $this->requireString($customerObj->all(), 'id', 'customer');
            }

            $subscriptions = $this->stripe->subscriptions->all($params);

            return [
                'data' => array_map(
                    fn (object $subscription) => $this->mapStripeSubscriptionToResponse($subscription),
                    $subscriptions->data
                ),
                'has_more' => $subscriptions->has_more,
            ];
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    private function findStripeCustomerByEmail(string $email): ?Payload
    {
        $existing = $this->stripe->customers->all(['email' => $email, 'limit' => 1]);
        $customer = $existing->data[0] ?? null;

        return $customer === null ? null : $this->stripePayload($customer);
    }

    private function findOrCreateStripeCustomer(string $email): Payload
    {
        return $this->findStripeCustomerByEmail($email)
            ?? $this->stripePayload($this->stripe->customers->create(['email' => $email]));
    }

    private function mapIntervalToStripe(string $interval): string
    {
        return match ($interval) {
            'daily' => 'day',
            'weekly' => 'week',
            'monthly' => 'month',
            'annually' => 'year',
            default => throw new PlanException("Unsupported billing interval [$interval]."),
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

    /**
     * @param  object  $price  A Stripe Price.
     * @param  object|null  $product  Its Product when fetched separately;
     *                                otherwise the one expanded on the price.
     */
    private function mapPriceToPlanResponse(object $price, ?object $product = null): PlanResponseDTO
    {
        $data = $this->stripePayload($price);
        $product = $product === null ? $data->at('product') : $this->stripePayload($product);
        $unitAmount = $data->float('unit_amount');

        return new PlanResponseDTO(
            planCode: $this->requireString($data->all(), 'id', 'plan'),
            name: $product->string('name') ?? '',
            amount: $unitAmount === null ? null : $unitAmount / 100,
            interval: $this->mapIntervalFromStripe($data->string('recurring', 'interval') ?? 'month'),
            currency: strtoupper($this->requireString($data->all(), 'currency', 'plan')),
            description: $product->string('description'),
            metadata: self::normalizeMetadata($data->get('metadata')),
            provider: $this->getName(),
        );
    }

    /**
     * @param  object  $subscription  A Stripe Subscription.
     * @param  Payload|null  $customer  Its Customer when already in hand;
     *                                  otherwise the one expanded on it.
     */
    private function mapStripeSubscriptionToResponse(object $subscription, ?Payload $customer = null): SubscriptionResponseDTO
    {
        $data = $this->stripePayload($subscription);
        $item = $data->at('items', 'data', 0);

        // Expanded, the price is an object; otherwise it is the price's id.
        $price = $item->at('price');
        $unitAmount = $price->float('unit_amount');
        $periodEnd = $data->int('current_period_end');

        return new SubscriptionResponseDTO(
            subscriptionCode: $this->requireString($data->all(), 'id', 'subscription'),
            status: $this->mapStripeSubscriptionStatus($this->requireString($data->all(), 'status', 'subscription')),
            customer: $customer?->string('email') ?? $data->string('customer', 'email') ?? '',
            plan: $price->string('id') ?? $item->string('price') ?? '',
            amount: $unitAmount === null ? null : $unitAmount / 100,
            currency: strtoupper($price->string('currency') ?? 'USD'),
            nextPaymentDate: $periodEnd === null ? null : date('Y-m-d H:i:s', $periodEnd),
            metadata: self::normalizeMetadata($data->get('metadata')),
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
