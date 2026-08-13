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
use Throwable;

/**
 * Trait providing Flutterwave subscription functionality.
 *
 * The single-subscription fetch/update endpoints and the tokenized-charge
 * subscribe flow were pattern-matched from Flutterwave's
 * independently-confirmed create-plan/cancel/activate/list endpoints, not
 * independently verified via live docs - verify against a sandbox before
 * production use.
 */
trait FlutterwaveSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * @throws PlanException
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            $response = $this->makeRequest('POST', 'payment-plans', [
                'json' => array_filter([
                    'amount' => $plan->amount,
                    'name' => $plan->name,
                    'interval' => $this->mapIntervalToFlutterwave($plan->interval),
                    'currency' => $plan->currency,
                    'duration' => $plan->invoiceLimit,
                ], fn ($value) => $value !== null),
            ]);

            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new PlanException($data['message'] ?? 'Failed to create subscription plan');
            }

            $this->log('info', 'Subscription plan created', [
                'plan_code' => $data['data']['id'] ?? null,
                'name' => $plan->name,
            ]);

            return $this->mapFlutterwavePlanToResponse($data['data']);
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create plan', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to create plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        try {
            $payload = array_filter([
                'name' => $updates['name'] ?? null,
                'status' => $updates['status'] ?? null,
            ], fn ($value) => $value !== null);

            if ($payload !== []) {
                $this->makeRequest('PUT', "payment-plans/$planCode", ['json' => $payload]);
            }

            $this->log('info', 'Subscription plan updated', ['plan_code' => $planCode]);

            return $this->fetchPlan($planCode);
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
            $response = $this->makeRequest('GET', "payment-plans/$planCode");
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new PlanException($data['message'] ?? 'Failed to fetch subscription plan');
            }

            return $this->mapFlutterwavePlanToResponse($data['data']);
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to get plan', ['plan_code' => $planCode, 'error' => $e->getMessage()]);
            throw new PlanException('Failed to get plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PlanException
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array
    {
        try {
            $response = $this->makeRequest('GET', 'payment-plans', [
                'query' => ['page' => $page ?? 1],
            ]);
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new PlanException($data['message'] ?? 'Failed to list subscription plans');
            }

            return [
                'data' => array_map(fn ($item) => $this->mapFlutterwavePlanToResponse($item), $data['data'] ?? []),
                'meta' => $data['meta'] ?? null,
            ];
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list plans', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to list plans: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a subscription.
     *
     * Flutterwave has no standalone "create subscription" call - a customer
     * is subscribed by including the plan in a tokenized (saved-card)
     * charge, so $request->authorization (a Flutterwave card token from a
     * prior charge) is required, matching the pattern already used for
     * Paystack/Stripe/Square. The subscription itself is a side effect of
     * that charge, not returned directly in its response, so this looks it
     * up by customer email immediately afterward.
     *
     * @throws SubscriptionException
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            if (! $request->authorization) {
                throw new SubscriptionException(
                    'Flutterwave requires an existing card token (via ->authorization()) to create a '.
                    'subscription. Charge the customer once and save the resulting token first.'
                );
            }

            $reference = $this->generateReference('FLW_SUB');

            $chargeResponse = $this->makeRequest('POST', "tokenized-charges/$request->authorization", [
                'json' => array_filter([
                    'currency' => $request->metadata['currency'] ?? null,
                    'email' => $request->customer,
                    'tx_ref' => $reference,
                    'payment_plan' => $request->plan,
                ], fn ($value) => $value !== null),
            ]);
            $this->parseResponse($chargeResponse);

            $subscription = $this->findFlutterwaveSubscription($request->customer, $request->plan);

            if (! $subscription) {
                throw new SubscriptionException(
                    'Subscription charge succeeded but the resulting subscription could not be located - '.
                    'it may still be processing. Check GET /subscriptions shortly, or handle the '.
                    'subscription.create webhook event instead of relying on the return value here.'
                );
            }

            $response = $this->mapFlutterwaveSubscriptionToResponse($subscription);
            $this->logSubscription($request, $response);

            return $response;
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
            $response = $this->makeRequest('GET', "subscriptions/$subscriptionCode");
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new SubscriptionException($data['message'] ?? 'Failed to fetch subscription');
            }

            return $this->mapFlutterwaveSubscriptionToResponse($data['data']);
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
     * @throws SubscriptionException
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $this->makeRequest('PUT', "subscriptions/$action->subscriptionCode/cancel");

            $this->log('info', 'Subscription cancelled', ['subscription_code' => $action->subscriptionCode]);

            $response = $this->fetchSubscription($action->subscriptionCode);
            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to cancel subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to cancel subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SubscriptionException
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $this->makeRequest('PUT', "subscriptions/$action->subscriptionCode/activate");

            $this->log('info', 'Subscription enabled', ['subscription_code' => $action->subscriptionCode]);

            $response = $this->fetchSubscription($action->subscriptionCode);
            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to enable subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to enable subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SubscriptionException
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array
    {
        try {
            $query = ['page' => $page ?? 1];

            $response = $this->makeRequest('GET', 'subscriptions', ['query' => $query]);
            $data = $this->parseResponse($response);

            if (($data['status'] ?? '') !== 'success') {
                throw new SubscriptionException($data['message'] ?? 'Failed to list subscriptions');
            }

            $items = $data['data'] ?? [];
            if ($customer) {
                $items = array_values(array_filter(
                    $items,
                    fn ($item) => ($item['customer']['email'] ?? null) === $customer
                ));
            }

            return [
                'data' => array_map(fn ($item) => $this->mapFlutterwaveSubscriptionToResponse($item), $items),
                'meta' => $data['meta'] ?? null,
            ];
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws ChargeException
     */
    private function findFlutterwaveSubscription(string $customerEmail, string $planCode): ?array
    {
        $response = $this->makeRequest('GET', 'subscriptions');
        $data = $this->parseResponse($response);

        foreach ($data['data'] ?? [] as $item) {
            $itemPlan = is_array($item['plan'] ?? null) ? ($item['plan']['id'] ?? null) : ($item['plan'] ?? null);
            if (
                ($item['customer']['email'] ?? null) === $customerEmail
                && (string) $itemPlan === $planCode
            ) {
                return $item;
            }
        }

        return null;
    }

    private function mapIntervalToFlutterwave(string $interval): string
    {
        return match ($interval) {
            'annually' => 'yearly',
            default => $interval,
        };
    }

    private function mapIntervalFromFlutterwave(string $interval): string
    {
        return match ($interval) {
            'yearly' => 'annually',
            'quarterly' => 'monthly', // no direct equivalent in SubscriptionPlanDTO's interval set
            default => $interval,
        };
    }

    /**
     * SubscriptionResponseDTO::status feeds Enums\SubscriptionStatus - see
     * the identical note in StripeSubscriptionMethods/PayPalSubscriptionMethods.
     * Flutterwave's own statuses ('active', 'canceled') already match the
     * enum's recognized vocabulary directly.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapFlutterwavePlanToResponse(array $data): PlanResponseDTO
    {
        return new PlanResponseDTO(
            planCode: (string) $data['id'],
            name: $data['name'] ?? '',
            amount: (float) ($data['amount'] ?? 0),
            interval: $this->mapIntervalFromFlutterwave($data['interval'] ?? 'monthly'),
            currency: $data['currency'] ?? 'NGN',
            metadata: array_filter(['duration' => $data['duration'] ?? null]),
            provider: $this->getName(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapFlutterwaveSubscriptionToResponse(array $data): SubscriptionResponseDTO
    {
        $plan = $data['plan'] ?? null;

        return new SubscriptionResponseDTO(
            subscriptionCode: (string) $data['id'],
            status: strtolower((string) ($data['status'] ?? 'active')),
            customer: $data['customer']['email'] ?? '',
            plan: is_array($plan) ? (string) ($plan['id'] ?? '') : (string) $plan,
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['customer']['currency'] ?? 'NGN',
            nextPaymentDate: null,
            metadata: [],
            provider: $this->getName(),
        );
    }
}
