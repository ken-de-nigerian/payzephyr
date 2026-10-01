<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

/**
 * Trait providing Paystack subscription functionality.
 */
trait PaystackSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * The subscription request currently being processed.
     * Used for idempotency key handling and request tracking.
     */
    protected ?SubscriptionRequestDTO $currentSubscriptionRequest = null;

    /**
     * Create a subscription plan
     *
     *
     * @throws PlanException If the plan creation fails
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            $payload = array_filter([
                'name' => $plan->name,
                'interval' => $plan->interval,
                'amount' => $plan->getAmountInMinorUnits(),
                'currency' => $plan->currency,
                'description' => $plan->description,
                'invoice_limit' => $plan->invoiceLimit,
                'send_invoices' => $plan->sendInvoices,
                'send_sms' => $plan->sendSms,
            ], fn ($value): bool => $value !== null);

            $response = $this->makeRequest('POST', '/plan', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new PlanException(
                    Payload::of($data)->string('message') ?? 'Failed to create subscription plan'
                );
            }

            $this->log('info', 'Subscription plan created', [
                'plan_code' => Payload::of($data)->string('data', 'plan_code'),
                'name' => $plan->name,
            ]);

            return PlanResponseDTO::fromArray(array_merge(Payload::of($data)->array('data'), ['provider' => $this->getName()]));
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create plan', [
                'error' => $e->getMessage(),
            ]);
            throw new PlanException(
                'Failed to create plan: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Update a subscription plan
     *
     * @param  array<array-key, mixed>  $updates
     *
     * @throws PlanException If the plan update fails
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        SubscriptionPlanDTO::assertValidUpdates($updates);

        try {
            $response = $this->makeRequest('PUT', '/plan/'.rawurlencode($planCode), [
                'json' => $updates,
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new PlanException(
                    Payload::of($data)->string('message') ?? 'Failed to update subscription plan'
                );
            }

            $this->log('info', 'Subscription plan updated', [
                'plan_code' => $planCode,
            ]);

            return PlanResponseDTO::fromArray(array_merge(Payload::of($data)->array('data'), ['provider' => $this->getName()]));
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to update plan', [
                'plan_code' => $planCode,
                'error' => $e->getMessage(),
            ]);
            throw new PlanException(
                'Failed to update plan: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Fetch a subscription plan
     *
     *
     * @throws PlanException If the plan retrieval fails
     */
    public function fetchPlan(string $planCode): PlanResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/plan/'.rawurlencode($planCode));

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new PlanException(
                    Payload::of($data)->string('message') ?? 'Failed to fetch subscription plan'
                );
            }

            return PlanResponseDTO::fromArray(array_merge(Payload::of($data)->array('data'), ['provider' => $this->getName()]));
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to get plan', [
                'plan_code' => $planCode,
                'error' => $e->getMessage(),
            ]);
            throw new PlanException(
                'Failed to get plan: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * List all subscription plans
     *
     * @return array<array-key, mixed>
     *
     * @throws PlanException If listing plans fails
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array
    {
        try {
            $response = $this->makeRequest('GET', '/plan', [
                'query' => [
                    'perPage' => $perPage,
                    'page' => $page,
                ],
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new PlanException(
                    Payload::of($data)->string('message') ?? 'Failed to list subscription plans'
                );
            }

            return [
                'data' => array_map(
                    fn ($plan): PlanResponseDTO => PlanResponseDTO::fromArray(array_merge(Payload::of($plan)->all(), ['provider' => $this->getName()])),
                    Payload::of($data)->array('data')
                ),
                'meta' => Payload::of($data)->arrayOrNull('meta'),
            ];
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list plans', [
                'error' => $e->getMessage(),
            ]);
            throw new PlanException(
                'Failed to list plans: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create a subscription
     *
     * @throws SubscriptionException If subscription creation fails
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        $this->currentSubscriptionRequest = $request;

        try {
            $requestOptions = [
                'json' => $request->toArray(),
            ];

            if ($request->idempotencyKey) {
                $requestOptions['headers'] = [
                    'Idempotency-Key' => $request->idempotencyKey,
                ];
            }

            $response = $this->makeRequest('POST', '/subscription', $requestOptions);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new SubscriptionException(
                    Payload::of($data)->string('message') ?? 'Failed to create subscription'
                );
            }

            $result = Payload::of($data)->arrayOrNull('data') ?? $data;
            $subscriptionCode = Payload::of($result)->string('subscription_code') ?? Payload::of($result)->string('code');
            if ($subscriptionCode === null) {
                throw new SubscriptionException('Subscription code not found in response. Response: '.json_encode($data));
            }

            $this->log('info', 'Subscription created', [
                'subscription_code' => $subscriptionCode,
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $response = $this->mapPaystackSubscriptionToResponse($result, $request);

            $this->logSubscription($request, $response);

            return $response;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create subscription', [
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to create subscription: '.$e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->currentSubscriptionRequest = null;
        }
    }

    /**
     * Fetch subscription details
     *
     * @throws SubscriptionException If subscription retrieval fails
     */
    public function fetchSubscription(string $subscriptionCode): SubscriptionResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/subscription/'.rawurlencode($subscriptionCode));

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new SubscriptionException(
                    Payload::of($data)->string('message') ?? 'Failed to fetch subscription'
                );
            }

            return $this->mapPaystackSubscriptionToResponse(Payload::of($data)->array('data'));
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to fetch subscription: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Cancel a subscription. Requires $action->option('token') - Paystack's
     * email confirmation token.
     *
     * @throws SubscriptionException If subscription cancellation fails, or
     *                               the required token is missing/invalid
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        $subscriptionCode = $action->subscriptionCode;
        $token = $this->requirePaystackToken($action);

        try {
            $response = $this->makeRequest('POST', '/subscription/disable', [
                'json' => [
                    'code' => $subscriptionCode,
                    'token' => $token,
                ],
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new SubscriptionException(
                    Payload::of($data)->string('message') ?? 'Failed to cancel subscription'
                );
            }

            $this->log('info', 'Subscription cancelled', [
                'subscription_code' => $subscriptionCode,
            ]);

            $response = $this->fetchSubscription($subscriptionCode);

            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to cancel subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to cancel subscription: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Enable a disabled subscription. Requires $action->option('token') -
     * Paystack's email confirmation token.
     *
     * @throws SubscriptionException If subscription enabling fails, or the
     *                               required token is missing/invalid
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        $subscriptionCode = $action->subscriptionCode;
        $token = $this->requirePaystackToken($action);

        try {
            $response = $this->makeRequest('POST', '/subscription/enable', [
                'json' => [
                    'code' => $subscriptionCode,
                    'token' => $token,
                ],
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new SubscriptionException(
                    Payload::of($data)->string('message') ?? 'Failed to enable subscription'
                );
            }

            $this->log('info', 'Subscription enabled', [
                'subscription_code' => $subscriptionCode,
            ]);

            $response = $this->fetchSubscription($subscriptionCode);

            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to enable subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to enable subscription: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Paystack's cancel/enable endpoints require the email confirmation
     * token sent to the customer - this validation used to live generically
     * in SubscriptionValidator, which wrongly asserted it for every
     * provider. It belongs here: only Paystack actually needs it.
     */
    private function requirePaystackToken(SubscriptionActionDTO $action): string
    {
        $token = $action->option('token');

        if (! is_string($token) || strlen($token) < 10) {
            throw new SubscriptionException(
                'Paystack requires a valid email confirmation token (at least 10 characters) '.
                "to cancel or enable a subscription. Pass it via ->option('token', \$token) ".
                'or the Subscription::token() fluent helper.'
            );
        }

        return $token;
    }

    /**
     * List customer subscriptions
     *
     * @throws SubscriptionException If listing subscriptions fails
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array
    {
        try {
            $query = [
                'perPage' => $perPage,
                'page' => $page,
            ];

            if ($customer) {
                $customerId = $this->paystackCustomerId($customer);

                if ($customerId === null) {
                    return ['data' => [], 'meta' => null];
                }

                $query['customer'] = $customerId;
            }

            $response = $this->makeRequest('GET', '/subscription', [
                'query' => $query,
            ]);

            $data = $this->parseResponse($response);

            if (! Payload::of($data)->flag(false, 'status')) {
                throw new SubscriptionException(
                    Payload::of($data)->string('message') ?? 'Failed to list subscriptions'
                );
            }

            return [
                'data' => array_map(
                    fn ($subscription): SubscriptionResponseDTO => $this->mapPaystackSubscriptionToResponse(Payload::of($subscription)->all()),
                    Payload::of($data)->array('data')
                ),
                'meta' => Payload::of($data)->arrayOrNull('meta'),
            ];
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list subscriptions', [
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to list subscriptions: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * A Paystack subscription as the shared DTO.
     *
     * `plan` is the plan's code, as it is for every provider; the name is
     * `planName`. Paystack embeds the plan as an object when it lists or
     * fetches a subscription, but answers a create with only the plan's
     * numeric id - not a code anything else accepts - so on a create the
     * request supplies the code, and the other gaps the response leaves.
     *
     * @param  array<array-key, mixed>  $result
     */
    private function mapPaystackSubscriptionToResponse(array $result, ?SubscriptionRequestDTO $request = null): SubscriptionResponseDTO
    {
        $subscription = new Payload($result);
        $amount = $subscription->float('amount');

        return new SubscriptionResponseDTO(
            subscriptionCode: $subscription->string('subscription_code') ?? $subscription->string('code') ?? '',
            status: $subscription->string('status') ?? 'unknown',
            customer: $subscription->string('customer', 'email') ?? $request->customer ?? '',
            plan: $subscription->string('plan', 'plan_code') ?? $request->plan ?? $subscription->string('plan') ?? '',
            amount: $amount === null ? null : $amount / 100,
            currency: $subscription->string('currency') ?? 'NGN',
            nextPaymentDate: $subscription->string('next_payment_date'),
            emailToken: $subscription->string('email_token'),
            metadata: self::normalizeMetadata($subscription->get('metadata')),
            provider: $this->getName(),
            planName: $subscription->string('plan', 'name'),
            createdAt: $subscription->string('createdAt') ?? $subscription->string('created_at'),
        );
    }

    /**
     * Paystack's id for a customer given by email or customer code, or null
     * when Paystack has no such customer.
     *
     * The subscription list filters by that id. It was sent the email (or
     * code) as given, which is not what the filter takes - so the duplicate
     * check could read every customer's subscriptions as this one's, and
     * refuse a new customer because someone else was on the plan.
     */
    private function paystackCustomerId(string $customer): ?string
    {
        if (ctype_digit($customer)) {
            return $customer;
        }

        try {
            $body = Payload::of($this->parseResponse($this->makeRequest('GET', '/customer/'.rawurlencode($customer))));
        } catch (Throwable $e) {
            for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
                if ($current instanceof ClientException && $current->getResponse()->getStatusCode() === HttpStatusCodes::NOT_FOUND) {
                    return null;
                }
            }

            throw $e;
        }

        return $body->flag(false, 'status') ? $body->string('data', 'id') : null;
    }
}
