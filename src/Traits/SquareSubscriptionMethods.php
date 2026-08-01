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
use Throwable;

/**
 * Trait providing Square subscription functionality.
 *
 * See ADR-0010 for the API-mapping decisions this trait makes (Catalog
 * SUBSCRIPTION_PLAN + SUBSCRIPTION_PLAN_VARIATION pair, /pause and /resume
 * for cancel/enable, and the price_override_money simplification noted on
 * mapSquareSubscriptionToResponse()).
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
                    'idempotency_key' => uniqid('square_plan_', true),
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

            if (! isset($data['objects'])) {
                throw new PlanException($data['errors'][0]['detail'] ?? 'Failed to create subscription plan');
            }

            [$planObject, $variationObject] = $this->splitSquarePlanObjects($data['objects']);

            $this->log('info', 'Subscription plan created', [
                'plan_code' => $variationObject['id'] ?? null,
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
     * there are no new objects being created here). See ADR-0010.
     *
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        try {
            [$planObject, $variationObject] = $this->fetchSquareCatalogObjects($planCode);

            if (isset($updates['name']) && $planObject !== null) {
                $planObject['subscription_plan_data']['name'] = $updates['name'];
                $this->makeRequest('POST', '/v2/catalog/object', [
                    'json' => [
                        'idempotency_key' => uniqid('square_plan_upd_', true),
                        'object' => $planObject,
                    ],
                ]);
            }

            if (isset($updates['amount']) || isset($updates['interval'])) {
                if (isset($updates['amount'])) {
                    $variationObject['subscription_plan_variation_data']['phases'][0]['recurring_price_money']['amount']
                        = (int) round($updates['amount'] * 100);
                }
                if (isset($updates['interval'])) {
                    $variationObject['subscription_plan_variation_data']['phases'][0]['cadence']
                        = $this->mapIntervalToSquare($updates['interval']);
                }

                $this->makeRequest('POST', '/v2/catalog/object', [
                    'json' => [
                        'idempotency_key' => uniqid('square_plan_upd_', true),
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
     * caveat as Stripe's listPlans(). See ADR-0010.
     *
     * @return array<string, mixed>
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
            $data = $this->parseResponse($response);
            $objects = $data['objects'] ?? [];

            $plans = array_filter($objects, fn ($o) => ($o['type'] ?? null) === 'SUBSCRIPTION_PLAN');
            $variations = array_filter($objects, fn ($o) => ($o['type'] ?? null) === 'SUBSCRIPTION_PLAN_VARIATION');

            $result = [];
            foreach ($variations as $variation) {
                $planId = $variation['subscription_plan_variation_data']['subscription_plan_id'] ?? null;
                $plan = null;
                foreach ($plans as $candidate) {
                    if (($candidate['id'] ?? null) === $planId) {
                        $plan = $candidate;
                        break;
                    }
                }
                $result[] = $this->mapSquareCatalogToPlanResponse($plan, $variation);
            }

            return [
                'data' => array_slice($result, 0, $perPage ?? 50),
                'has_more' => isset($data['cursor']),
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
     * See ADR-0010.
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
                'idempotency_key' => $request->idempotencyKey ?? uniqid('square_sub_', true),
                'location_id' => $this->config['location_id'],
                'plan_variation_id' => $request->plan,
                'customer_id' => $customer['id'],
                'card_id' => $request->authorization,
                'start_date' => $request->startDate,
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/v2/subscriptions', ['json' => $payload]);
            $data = $this->parseResponse($response);

            if (! isset($data['subscription'])) {
                throw new SubscriptionException($data['errors'][0]['detail'] ?? 'Failed to create subscription');
            }

            $this->log('info', 'Subscription created', [
                'subscription_code' => $data['subscription']['id'],
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $result = $this->mapSquareSubscriptionToResponse($data['subscription'], $customer);
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
            $response = $this->makeRequest('GET', "/v2/subscriptions/$subscriptionCode");
            $data = $this->parseResponse($response);

            if (! isset($data['subscription'])) {
                throw new SubscriptionException($data['errors'][0]['detail'] ?? 'Failed to fetch subscription');
            }

            return $this->mapSquareSubscriptionToResponse($data['subscription']);
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
     * matching Paystack's disable/enable semantics. See ADR-0010.
     *
     * @throws SubscriptionException
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $payload = array_filter([
                'pause_effective_date' => $action->option('pause_effective_date'),
                'pause_cycle_duration' => $action->option('pause_cycle_duration'),
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', "/v2/subscriptions/{$action->subscriptionCode}/pause", [
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription paused', ['subscription_code' => $action->subscriptionCode]);

            $result = isset($data['subscription'])
                ? $this->mapSquareSubscriptionToResponse($data['subscription'])
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
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', "/v2/subscriptions/{$action->subscriptionCode}/resume", [
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription resumed', ['subscription_code' => $action->subscriptionCode]);

            $result = isset($data['subscription'])
                ? $this->mapSquareSubscriptionToResponse($data['subscription'])
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
     * Same cursor-pagination caveat as listPlans(). See ADR-0010.
     *
     * @return array<string, mixed>
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

            $filter = ['location_ids' => [$this->config['location_id']]];

            if ($customer) {
                $customerObject = $this->findSquareCustomerByEmail($customer);
                if (! $customerObject) {
                    return ['data' => [], 'has_more' => false];
                }
                $filter['customer_ids'] = [$customerObject['id']];
            }

            $response = $this->makeRequest('POST', '/v2/subscriptions/search', [
                'json' => [
                    'query' => ['filter' => $filter],
                    'limit' => $perPage ?? 50,
                ],
            ]);
            $data = $this->parseResponse($response);

            return [
                'data' => array_map(
                    fn ($s) => $this->mapSquareSubscriptionToResponse($s),
                    $data['subscriptions'] ?? []
                ),
                'has_more' => isset($data['cursor']),
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws PlanException
     */
    private function fetchSquareCatalogObjects(string $variationId): array
    {
        $response = $this->makeRequest('GET', "/v2/catalog/object/$variationId", [
            'query' => ['include_related_objects' => 'true'],
        ]);
        $data = $this->parseResponse($response);

        $variation = $data['object'] ?? null;
        if (! $variation) {
            throw new PlanException("Subscription plan not found: $variationId");
        }

        $planId = $variation['subscription_plan_variation_data']['subscription_plan_id'] ?? null;
        $plan = null;
        foreach ($data['related_objects'] ?? [] as $related) {
            if (($related['id'] ?? null) === $planId) {
                $plan = $related;
                break;
            }
        }

        return [$plan, $variation];
    }

    /**
     * @param  array<int, array<string, mixed>>  $objects
     * @return array{0: ?array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitSquarePlanObjects(array $objects): array
    {
        $plan = null;
        $variation = null;

        foreach ($objects as $object) {
            if (($object['type'] ?? null) === 'SUBSCRIPTION_PLAN') {
                $plan = $object;
            }
            if (($object['type'] ?? null) === 'SUBSCRIPTION_PLAN_VARIATION') {
                $variation = $object;
            }
        }

        return [$plan, $variation ?? []];
    }

    /**
     * @return array<string, mixed>|null
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

        return $data['customers'][0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateSquareCustomer(string $email): array
    {
        return $this->findSquareCustomerByEmail($email) ?? $this->createSquareCustomer($email);
    }

    /**
     * @return array<string, mixed>
     */
    private function createSquareCustomer(string $email): array
    {
        $response = $this->makeRequest('POST', '/v2/customers', [
            'json' => [
                'idempotency_key' => uniqid('square_cust_', true),
                'email_address' => $email,
            ],
        ]);
        $data = $this->parseResponse($response);

        return $data['customer'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSquareCustomerById(string $customerId): ?array
    {
        if ($customerId === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', "/v2/customers/$customerId");
            $data = $this->parseResponse($response);

            return $data['customer'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function mapIntervalToSquare(string $interval): string
    {
        return match ($interval) {
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'annually' => 'ANNUAL',
            default => 'MONTHLY',
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
     * closest match: reversible via enableSubscription(), same as our
     * pause/resume mapping decision in ADR-0010).
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
     * @param  array<string, mixed>|null  $plan
     * @param  array<string, mixed>  $variation
     */
    private function mapSquareCatalogToPlanResponse(?array $plan, array $variation): PlanResponseDTO
    {
        $phase = $variation['subscription_plan_variation_data']['phases'][0] ?? [];
        $price = $phase['recurring_price_money'] ?? [];

        return new PlanResponseDTO(
            planCode: $variation['id'] ?? '',
            name: $plan['subscription_plan_data']['name'] ?? $variation['subscription_plan_variation_data']['name'] ?? '',
            amount: ($price['amount'] ?? 0) / 100,
            interval: $this->mapIntervalFromSquare($phase['cadence'] ?? 'MONTHLY'),
            currency: strtoupper($price['currency'] ?? 'USD'),
            metadata: array_filter(['plan_id' => $plan['id'] ?? null]),
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
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>|null  $customer
     */
    private function mapSquareSubscriptionToResponse(array $subscription, ?array $customer = null): SubscriptionResponseDTO
    {
        $customer ??= $this->findSquareCustomerById($subscription['customer_id'] ?? '');
        $priceMoney = $subscription['price_override_money'] ?? null;

        return new SubscriptionResponseDTO(
            subscriptionCode: $subscription['id'] ?? '',
            status: $this->mapSquareSubscriptionStatus($subscription['status'] ?? 'ACTIVE'),
            customer: $customer['email_address'] ?? '',
            plan: $subscription['plan_variation_id'] ?? '',
            amount: ($priceMoney['amount'] ?? 0) / 100,
            currency: strtoupper($priceMoney['currency'] ?? 'USD'),
            nextPaymentDate: $subscription['charged_through_date'] ?? null,
            metadata: array_filter(['customer_id' => $subscription['customer_id'] ?? null]),
            provider: $this->getName(),
        );
    }
}
