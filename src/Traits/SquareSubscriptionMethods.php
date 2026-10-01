<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

/**
 * Trait providing Square subscription functionality.
 *
 * Plans map to a Catalog SUBSCRIPTION_PLAN + SUBSCRIPTION_PLAN_VARIATION
 * pair. Cancel/enable map to /pause and /resume. See the
 * price_override_money simplification noted on
 * mapSquareSubscriptionToResponse().
 */
trait SquareSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * @throws PlanException
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            $response = $this->makeRequest('POST', '/v2/catalog/batch-upsert', [
                'json' => [
                    'idempotency_key' => $this->newIdempotencyKey(),
                    'batches' => [[
                        'objects' => [
                            [
                                'type' => 'SUBSCRIPTION_PLAN',
                                'id' => '#plan',
                                'subscription_plan_data' => ['name' => $plan->name],
                            ],
                            [
                                'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                                'id' => '#variation',
                                'subscription_plan_variation_data' => [
                                    'name' => $plan->name,
                                    'phases' => [[
                                        'cadence' => $this->mapIntervalToSquare($plan->interval),
                                        'recurring_price_money' => [
                                            'amount' => $plan->getAmountInMinorUnits(),
                                            'currency' => $plan->currency,
                                        ],
                                        'ordinal' => 0,
                                    ]],
                                    'subscription_plan_id' => '#plan',
                                ],
                            ],
                        ],
                    ]],
                ],
            ]);

            $data = $this->parseResponse($response);
            $objects = Payload::of($data)->arrayOrNull('objects');

            if ($objects === null) {
                throw new PlanException($this->errorDetail($data) ?? 'Failed to create subscription plan');
            }

            [$planObject, $variationObject] = $this->splitSquarePlanObjects($objects);

            $this->log('info', 'Subscription plan created', [
                'plan_code' => Payload::of($variationObject)->string('id'),
                'name' => $plan->name,
            ]);

            return $this->mapSquareCatalogToPlanResponse($planObject, $variationObject);
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create plan', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to create plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a subscription plan.
     *
     * Square catalog objects carry a version number for optimistic
     * concurrency - the existing plan/variation objects are fetched first
     * and re-upserted individually (rather than via batch-upsert, since
     * there are no new objects being created here).
     *
     * @param  array<array-key, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        SubscriptionPlanDTO::assertValidUpdates($updates);

        $changes = new Payload($updates);
        $name = $changes->string('name');
        $amount = $changes->float('amount');
        $interval = $changes->string('interval');

        try {
            [$planObject, $variationObject] = $this->fetchSquareCatalogObjects($planCode);

            if ($name !== null && $planObject !== null) {
                data_set($planObject, 'subscription_plan_data.name', $name);
                $this->makeRequest('POST', '/v2/catalog/object', [
                    'json' => [
                        'idempotency_key' => $this->newIdempotencyKey(),
                        'object' => $planObject,
                    ],
                ]);
            }

            if ($amount !== null || $interval !== null) {
                // The phase that bills indefinitely, not phases[0]: a plan
                // with an introductory phase lists that first.
                $phase = 'subscription_plan_variation_data.phases.'.$this->squareRegularPhaseIndex($variationObject);

                if ($amount !== null) {
                    data_set($variationObject, "$phase.recurring_price_money.amount", (int) round($amount * 100));
                }
                if ($interval !== null) {
                    data_set($variationObject, "$phase.cadence", $this->mapIntervalToSquare($interval));
                }

                $this->makeRequest('POST', '/v2/catalog/object', [
                    'json' => [
                        'idempotency_key' => $this->newIdempotencyKey(),
                        'object' => $variationObject,
                    ],
                ]);
            }

            $this->log('info', 'Subscription plan updated', ['plan_code' => $planCode]);

            return $this->fetchPlan($planCode);
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
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
            [$planObject, $variationObject] = $this->fetchSquareCatalogObjects($planCode);

            return $this->mapSquareCatalogToPlanResponse($planObject, $variationObject);
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to get plan', ['plan_code' => $planCode, 'error' => $e->getMessage()]);
            throw new PlanException('Failed to get plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * List subscription plans.
     *
     * Square's catalog list API is cursor-based, not page-numbered - same
     * caveat as Stripe's listPlans().
     *
     * @return array<array-key, mixed>
     *
     * @throws PlanException
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array
    {
        try {
            if (($page ?? 1) > 1) {
                $this->log('warning', 'Square catalog listing is cursor-based - only the first page can be served', [
                    'requested_page' => $page,
                ]);
            }

            $response = $this->makeRequest('GET', '/v2/catalog/list', [
                'query' => ['types' => 'SUBSCRIPTION_PLAN,SUBSCRIPTION_PLAN_VARIATION'],
            ]);
            $body = Payload::of($this->parseResponse($response));
            $objects = $body->array('objects');

            $plans = array_filter($objects, fn ($o): bool => Payload::of($o)->string('type') === 'SUBSCRIPTION_PLAN');
            $variations = array_filter($objects, fn ($o): bool => Payload::of($o)->string('type') === 'SUBSCRIPTION_PLAN_VARIATION');

            $result = [];
            foreach ($variations as $variation) {
                $variation = Payload::of($variation)->all();
                $planId = Payload::of($variation)->string('subscription_plan_variation_data', 'subscription_plan_id');
                $plan = null;
                foreach ($plans as $candidate) {
                    if ($planId !== null && Payload::of($candidate)->string('id') === $planId) {
                        $plan = Payload::of($candidate)->all();
                        break;
                    }
                }
                $result[] = $this->mapSquareCatalogToPlanResponse($plan, $variation);
            }

            return [
                'data' => array_slice($result, 0, $perPage ?? 50),
                'has_more' => $body->has('cursor'),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list plans', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to list plans: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a subscription.
     *
     * Requires $request->authorization as an existing Square card-on-file ID
     * for the customer - the same precondition Stripe's driver already has.
     *
     * @throws SubscriptionException
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            if (! $request->authorization) {
                throw new SubscriptionException(
                    'Square requires an existing card-on-file ID (via ->authorization()) to create a '.
                    'subscription. Save a card to the customer profile first.'
                );
            }

            $customer = $this->findOrCreateSquareCustomer($request->customer);

            $payload = array_filter([
                'idempotency_key' => $request->idempotencyKey ?? $this->newIdempotencyKey(),
                'location_id' => $this->settings()->string('location_id'),
                'plan_variation_id' => $request->plan,
                'customer_id' => $this->requireString($customer, 'id', 'customer'),
                'card_id' => $request->authorization,
                'start_date' => $request->startDate,
            ], fn ($value): bool => $value !== null);

            $response = $this->makeRequest('POST', '/v2/subscriptions', ['json' => $payload]);
            $data = $this->parseResponse($response);

            $subscription = Payload::of($data)->arrayOrNull('subscription');

            if ($subscription === null) {
                throw new SubscriptionException($this->errorDetail($data) ?? 'Failed to create subscription');
            }

            $this->log('info', 'Subscription created', [
                'subscription_code' => Payload::of($subscription)->string('id'),
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $result = $this->mapSquareSubscriptionToResponse($subscription, $customer);
            $this->logSubscription($request, $result);

            return $result;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
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
            $response = $this->makeRequest('GET', '/v2/subscriptions/'.rawurlencode($subscriptionCode));
            $data = $this->parseResponse($response);

            $subscription = Payload::of($data)->arrayOrNull('subscription');

            if ($subscription === null) {
                throw new SubscriptionException($this->errorDetail($data) ?? 'Failed to fetch subscription');
            }

            return $this->mapSquareSubscriptionToResponse($subscription);
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to fetch subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancel (pause) a subscription.
     *
     * Maps to Square's /pause endpoint rather than /cancel - reversible,
     * matching Paystack's disable/enable semantics.
     *
     * @throws SubscriptionException
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $payload = array_filter([
                'pause_effective_date' => $action->option('pause_effective_date'),
                'pause_cycle_duration' => $action->option('pause_cycle_duration'),
            ], fn ($value): bool => $value !== null);

            $response = $this->makeRequest('POST', '/v2/subscriptions/'.rawurlencode($action->subscriptionCode).'/pause', [
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription paused', ['subscription_code' => $action->subscriptionCode]);

            $subscription = Payload::of($data)->arrayOrNull('subscription');
            $result = $subscription !== null
                ? $this->mapSquareSubscriptionToResponse($subscription)
                : $this->fetchSubscription($action->subscriptionCode);

            $this->logSubscriptionFromResponse($result);

            return $result;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to cancel subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to cancel subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Enable (resume) a paused subscription.
     *
     * @throws SubscriptionException
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $payload = array_filter([
                'resume_effective_date' => $action->option('resume_effective_date'),
                'resume_change_timing' => $action->option('resume_change_timing'),
            ], fn ($value): bool => $value !== null);

            $response = $this->makeRequest('POST', '/v2/subscriptions/'.rawurlencode($action->subscriptionCode).'/resume', [
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription resumed', ['subscription_code' => $action->subscriptionCode]);

            $subscription = Payload::of($data)->arrayOrNull('subscription');
            $result = $subscription !== null
                ? $this->mapSquareSubscriptionToResponse($subscription)
                : $this->fetchSubscription($action->subscriptionCode);

            $this->logSubscriptionFromResponse($result);

            return $result;
        } catch (Throwable $e) {
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
                $this->log('warning', 'Square subscription search is cursor-based - only the first page can be served', [
                    'requested_page' => $page,
                ]);
            }

            $filter = ['location_ids' => [$this->settings()->string('location_id')]];

            // Customers already looked up, by id. Each subscription names
            // only its customer's id, and looking one up per subscription
            // made a listing of fifty one call plus fifty.
            $customers = [];

            if ($customer) {
                $customerObject = $this->findSquareCustomerByEmail($customer);
                if (! $customerObject) {
                    return ['data' => [], 'has_more' => false];
                }
                $customerId = $this->requireString($customerObject, 'id', 'customer');
                $filter['customer_ids'] = [$customerId];
                $customers[$customerId] = $customerObject;
            }

            $response = $this->makeRequest('POST', '/v2/subscriptions/search', [
                'json' => [
                    'query' => ['filter' => $filter],
                    'limit' => $perPage ?? 50,
                ],
            ]);
            $body = Payload::of($this->parseResponse($response));

            return [
                'data' => array_map(
                    function ($item) use (&$customers): SubscriptionResponseDTO {
                        $subscription = Payload::of($item)->all();
                        $customerId = Payload::of($subscription)->string('customer_id') ?? '';

                        if (! array_key_exists($customerId, $customers)) {
                            $customers[$customerId] = $this->findSquareCustomerById($customerId);
                        }

                        return $this->mapSquareSubscriptionToResponse($subscription, $customers[$customerId]);
                    },
                    $body->array('subscriptions')
                ),
                'has_more' => $body->has('cursor'),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{0: ?array<array-key, mixed>, 1: array<array-key, mixed>}
     *
     * @throws PlanException|ChargeException
     */
    private function fetchSquareCatalogObjects(string $variationId): array
    {
        $response = $this->makeRequest('GET', '/v2/catalog/object/'.rawurlencode($variationId), [
            'query' => ['include_related_objects' => 'true'],
        ]);
        $body = Payload::of($this->parseResponse($response));

        $variation = $body->arrayOrNull('object');
        if ($variation === null || $variation === []) {
            throw new PlanException("Subscription plan not found: $variationId");
        }

        $planId = Payload::of($variation)->string('subscription_plan_variation_data', 'subscription_plan_id');
        $plan = null;
        foreach ($body->array('related_objects') as $related) {
            if ($planId !== null && Payload::of($related)->string('id') === $planId) {
                $plan = Payload::of($related)->all();
                break;
            }
        }

        return [$plan, $variation];
    }

    /**
     * @param  array<array-key, mixed>  $objects
     * @return array{0: ?array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    private function splitSquarePlanObjects(array $objects): array
    {
        $plan = null;
        $variation = null;

        foreach ($objects as $object) {
            $type = Payload::of($object)->string('type');

            if ($type === 'SUBSCRIPTION_PLAN') {
                $plan = Payload::of($object)->all();
            }
            if ($type === 'SUBSCRIPTION_PLAN_VARIATION') {
                $variation = Payload::of($object)->all();
            }
        }

        return [$plan, $variation ?? []];
    }

    /**
     * @return array<array-key, mixed>|null
     *
     * @throws ChargeException
     */
    private function findSquareCustomerByEmail(string $email): ?array
    {
        $response = $this->makeRequest('POST', '/v2/customers/search', [
            'json' => [
                'query' => ['filter' => ['email_address' => ['exact' => $email]]],
                'limit' => 1,
            ],
        ]);
        $data = $this->parseResponse($response);

        return Payload::of($data)->arrayOrNull('customers', 0);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ChargeException
     */
    private function findOrCreateSquareCustomer(string $email): array
    {
        return $this->findSquareCustomerByEmail($email) ?? $this->createSquareCustomer($email);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ChargeException
     */
    private function createSquareCustomer(string $email): array
    {
        $response = $this->makeRequest('POST', '/v2/customers', [
            'json' => [
                'idempotency_key' => $this->newIdempotencyKey(),
                'email_address' => $email,
            ],
        ]);
        $data = $this->parseResponse($response);

        return $this->requireArray($data, 'customer', 'customer');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function findSquareCustomerById(string $customerId): ?array
    {
        if ($customerId === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/v2/customers/'.rawurlencode($customerId));
            $data = $this->parseResponse($response);

            return Payload::of($data)->arrayOrNull('customer');
        } catch (Throwable) {
            return null;
        }
    }

    private function mapIntervalToSquare(string $interval): string
    {
        return match ($interval) {
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'monthly' => 'MONTHLY',
            'annually' => 'ANNUAL',
            default => throw new PlanException("Unsupported billing interval [$interval]."),
        };
    }

    private function mapIntervalFromSquare(string $cadence): string
    {
        return match (strtoupper($cadence)) {
            'DAILY' => 'daily',
            'WEEKLY', 'EVERY_TWO_WEEKS' => 'weekly',
            'ANNUAL' => 'annually',
            default => 'monthly',
        };
    }

    /**
     * SubscriptionResponseDTO::status feeds Enums\SubscriptionStatus - see
     * the identical note in StripeSubscriptionMethods. Square's own
     * PAUSED status maps to 'non-renewing' (not a direct synonym, but the
     * closest match: reversible via enableSubscription(), same as
     * cancelSubscription()'s pause/resume mapping).
     */
    private function mapSquareSubscriptionStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'CANCELED', 'DEACTIVATED' => 'cancelled',
            'PAUSED' => 'non-renewing',
            'PENDING' => 'attention',
            default => 'active',
        };
    }

    /**
     * The index of the phase a plan variation bills on indefinitely: the
     * first with no `periods`, or the last. An introductory phase comes
     * first, so reading phases[0] took the introductory price as the plan's.
     *
     * @param  array<array-key, mixed>  $variation
     */
    private function squareRegularPhaseIndex(array $variation): int
    {
        $phases = array_values(Payload::of($variation)->array('subscription_plan_variation_data', 'phases'));

        foreach ($phases as $index => $phase) {
            if (! Payload::of($phase)->has('periods')) {
                return $index;
            }
        }

        return max(0, count($phases) - 1);
    }

    /**
     * @param  array<array-key, mixed>|null  $plan
     * @param  array<array-key, mixed>  $variation
     */
    private function mapSquareCatalogToPlanResponse(?array $plan, array $variation): PlanResponseDTO
    {
        $details = Payload::of($variation)->at('subscription_plan_variation_data');
        $phase = $details->at('phases', $this->squareRegularPhaseIndex($variation));
        $price = $phase->at('recurring_price_money');
        $amount = $price->float('amount');

        return new PlanResponseDTO(
            planCode: $this->requireString($variation, 'id', 'plan'),
            name: Payload::of($plan)->string('subscription_plan_data', 'name') ?? $details->string('name') ?? '',
            amount: $amount === null ? null : $amount / 100,
            interval: $this->mapIntervalFromSquare($phase->string('cadence') ?? 'MONTHLY'),
            currency: strtoupper($price->string('currency') ?? 'USD'),
            metadata: array_filter(['plan_id' => Payload::of($plan)->string('id')]),
            provider: $this->getName(),
        );
    }

    /**
     * Maps a Square Subscription resource to our shared DTO.
     *
     * Note: Square's Subscription object does not itself carry the billed
     * amount/currency (that lives on the plan variation) unless a
     * price_override_money was set on this specific subscription - so
     * amount/currency here reflect the override when present, and fall back
     * to 0/USD otherwise rather than making an extra catalog lookup per
     * subscription. Callers needing the authoritative price should read it
     * from fetchPlan($subscription->plan).
     *
     * @param  array<array-key, mixed>  $subscription
     * @param  array<array-key, mixed>|null  $customer
     */
    private function mapSquareSubscriptionToResponse(array $subscription, ?array $customer = null): SubscriptionResponseDTO
    {
        $data = new Payload($subscription);
        $customer ??= $this->findSquareCustomerById($data->string('customer_id') ?? '');
        $price = $data->at('price_override_money');
        $amount = $price->float('amount');

        return new SubscriptionResponseDTO(
            subscriptionCode: $this->requireString($subscription, 'id', 'subscription'),
            status: $this->mapSquareSubscriptionStatus($data->string('status') ?? 'ACTIVE'),
            customer: Payload::of($customer)->string('email_address') ?? '',
            plan: $data->string('plan_variation_id') ?? '',
            amount: $amount === null ? null : $amount / 100,
            currency: strtoupper($price->string('currency') ?? 'USD'),
            nextPaymentDate: $data->string('charged_through_date'),
            metadata: array_filter(['customer_id' => $data->string('customer_id')]),
            provider: $this->getName(),
        );
    }
}
