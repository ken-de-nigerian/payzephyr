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
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', '/plan', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new PlanException(
                    $data['message'] ?? 'Failed to create subscription plan'
                );
            }

            $this->log('info', 'Subscription plan created', [
                'plan_code' => $data['data']['plan_code'] ?? null,
                'name' => $plan->name,
            ]);

            $planData = $data['data'];
            $planData['provider'] = $this->getName();

            return PlanResponseDTO::fromArray($planData);
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
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException If the plan update fails
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        try {
            $response = $this->makeRequest('PUT', "/plan/$planCode", [
                'json' => $updates,
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new PlanException(
                    $data['message'] ?? 'Failed to update subscription plan'
                );
            }

            $this->log('info', 'Subscription plan updated', [
                'plan_code' => $planCode,
            ]);

            $planData = $data['data'];
            $planData['provider'] = $this->getName();

            return PlanResponseDTO::fromArray($planData);
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
            $response = $this->makeRequest('GET', "/plan/$planCode");

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new PlanException(
                    $data['message'] ?? 'Failed to fetch subscription plan'
                );
            }

            $planData = $data['data'];
            $planData['provider'] = $this->getName();

            return PlanResponseDTO::fromArray($planData);
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
     * @return array<string, mixed>
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

            if (! ($data['status'] ?? false)) {
                throw new PlanException(
                    $data['message'] ?? 'Failed to list subscription plans'
                );
            }

            return $data['data'];
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

            if (! ($data['status'] ?? false)) {
                throw new SubscriptionException(
                    $data['message'] ?? 'Failed to create subscription'
                );
            }

            $result = $data['data'] ?? $data;

            $subscriptionCode = $result['subscription_code'] ?? $result['code'] ?? null;
            if ($subscriptionCode === null) {
                throw new SubscriptionException('Subscription code not found in response. Response: '.json_encode($data));
            }

            $this->log('info', 'Subscription created', [
                'subscription_code' => $subscriptionCode,
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $metadata = $result['metadata'] ?? [];
            $planCode = $result['plan']['plan_code'] ?? $result['plan']['code'] ?? $request->plan;
            if (! isset($metadata['plan_code'])) {
                $metadata['plan_code'] = $planCode;
            }

            $response = new SubscriptionResponseDTO(
                subscriptionCode: $subscriptionCode,
                status: $result['status'],
                customer: $result['customer']['email'] ?? $request->customer,
                plan: $result['plan']['name'] ?? $request->plan,
                amount: ($result['amount'] ?? 0) / 100,
                currency: $result['currency'] ?? 'NGN',
                nextPaymentDate: $result['next_payment_date'] ?? null,
                emailToken: $result['email_token'] ?? null,
                metadata: $metadata,
                provider: $this->getName(),
            );

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
            $response = $this->makeRequest('GET', "/subscription/$subscriptionCode");

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new SubscriptionException(
                    $data['message'] ?? 'Failed to fetch subscription'
                );
            }

            $result = $data['data'];

            $metadata = $result['metadata'] ?? [];
            $planCode = $result['plan']['plan_code'] ?? $result['plan']['code'] ?? null;
            if ($planCode && ! isset($metadata['plan_code'])) {
                $metadata['plan_code'] = $planCode;
            }

            return new SubscriptionResponseDTO(
                subscriptionCode: $result['subscription_code'] ?? '',
                status: $result['status'] ?? 'unknown',
                customer: $result['customer']['email'] ?? '',
                plan: $result['plan']['name'] ?? '',
                amount: ($result['amount'] ?? 0) / 100,
                currency: $result['currency'] ?? 'NGN',
                nextPaymentDate: $result['next_payment_date'] ?? null,
                emailToken: $result['email_token'] ?? null,
                metadata: $metadata,
                provider: $this->getName(),
            );
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
     * email confirmation token; see ADR-0006.
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

            if (! ($data['status'] ?? false)) {
                throw new SubscriptionException(
                    $data['message'] ?? 'Failed to cancel subscription'
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
     * Paystack's email confirmation token; see ADR-0006.
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

            if (! ($data['status'] ?? false)) {
                throw new SubscriptionException(
                    $data['message'] ?? 'Failed to enable subscription'
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
                $query['customer'] = $customer;
            }

            $response = $this->makeRequest('GET', '/subscription', [
                'query' => $query,
            ]);

            $data = $this->parseResponse($response);

            if (! ($data['status'] ?? false)) {
                throw new SubscriptionException(
                    $data['message'] ?? 'Failed to list subscriptions'
                );
            }

            return $data['data'];
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
}
