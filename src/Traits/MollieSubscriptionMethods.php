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
 * Trait providing Mollie subscription functionality.
 *
 * See ADR-0010 for the API-mapping decisions this trait makes. Two
 * structural differences from every other subscription-capable driver:
 *
 * 1. Mollie has no server-side "plan" resource at all - a subscription
 *    carries its own amount/interval/description directly. createPlan()
 *    therefore encodes the plan into an opaque, self-describing planCode
 *    string client-side rather than persisting anything with Mollie;
 *    listPlans() throws, since there is nothing server-side to enumerate.
 * 2. Every subscription operation requires both a customerId and a
 *    subscriptionId, but the shared interface only carries one
 *    $subscriptionCode string - so subscriptionCode is encoded here as
 *    "{customerId}:{subscriptionId}".
 */
trait MollieSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * @throws PlanException
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        $planCode = $this->encodeMolliePlanData($plan->name, $plan->amount, $plan->interval, $plan->currency);

        $this->log('info', 'Subscription plan created (encoded client-side - Mollie has no plan resource)', [
            'plan_code' => $planCode,
            'name' => $plan->name,
        ]);

        return new PlanResponseDTO(
            planCode: $planCode,
            name: $plan->name,
            amount: $plan->amount,
            interval: $plan->interval,
            currency: $plan->currency,
            description: $plan->description,
            metadata: $plan->metadata,
            provider: $this->getName(),
        );
    }

    /**
     * Update a subscription plan.
     *
     * Since the "plan" only exists as an encoded string, updating produces
     * a new plan code rather than mutating anything server-side - existing
     * subscriptions created from the old plan code are unaffected, matching
     * Stripe's immutable-Price update behavior for the same underlying
     * reason (nothing to mutate in place).
     *
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        $existing = $this->decodeMolliePlanData($planCode);

        $name = $updates['name'] ?? $existing['name'];
        $amount = (float) ($updates['amount'] ?? $existing['amount']);
        $interval = $updates['interval'] ?? $existing['interval'];
        $currency = $updates['currency'] ?? $existing['currency'];

        $newPlanCode = $this->encodeMolliePlanData($name, $amount, $interval, $currency);

        $this->log('info', 'Subscription plan updated (re-encoded into a new plan code)', [
            'old_plan_code' => $planCode,
            'new_plan_code' => $newPlanCode,
        ]);

        return new PlanResponseDTO(
            planCode: $newPlanCode,
            name: $name,
            amount: $amount,
            interval: $interval,
            currency: $currency,
            provider: $this->getName(),
        );
    }

    /**
     * @throws PlanException
     */
    public function fetchPlan(string $planCode): PlanResponseDTO
    {
        $data = $this->decodeMolliePlanData($planCode);

        return new PlanResponseDTO(
            planCode: $planCode,
            name: $data['name'],
            amount: (float) $data['amount'],
            interval: $data['interval'],
            currency: $data['currency'],
            provider: $this->getName(),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PlanException
     */
    public function listPlans(?int $perPage = 50, ?int $page = 1): array
    {
        throw new PlanException(
            'Mollie has no server-side plan storage to list - plans are encoded client-side into the '.
            'plan code returned by createPlan()/fetchPlan()/updatePlan(). Track plan codes in your own '.
            'application if you need to enumerate them.'
        );
    }

    /**
     * Create a subscription.
     *
     * Requires $request->authorization as an existing Mollie mandate ID
     * (obtained from a prior recurring-eligible payment). See ADR-0010.
     *
     * @throws SubscriptionException
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            if (! $request->authorization) {
                throw new SubscriptionException(
                    'Mollie requires an existing mandate ID (via ->authorization()) to create a '.
                    'subscription. Charge the customer once with a recurring-eligible payment method '.
                    'first to obtain a mandate.'
                );
            }

            $planData = $this->decodeMolliePlanData($request->plan);
            $customer = $this->findOrCreateMollieCustomer($request->customer);
            $customerId = $customer['id'];

            $payload = array_filter([
                'amount' => [
                    'currency' => $planData['currency'],
                    'value' => $this->formatAmount((float) $planData['amount'], $planData['currency']),
                ],
                'interval' => $this->mapIntervalToMollie($planData['interval']),
                'description' => $planData['name'],
                'mandateId' => $request->authorization,
                'startDate' => $request->startDate,
                'times' => $request->metadata['times'] ?? null,
                'webhookUrl' => $request->callbackUrl,
                'metadata' => $request->metadata ?: null,
            ], fn ($value) => $value !== null);

            $response = $this->makeRequest('POST', "/v2/customers/$customerId/subscriptions", [
                'json' => $payload,
            ]);
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription created', [
                'subscription_code' => "$customerId:{$data['id']}",
                'customer' => $request->customer,
            ]);

            $result = $this->mapMollieSubscriptionToResponse($data, $customerId, $request->customer);
            $this->logSubscription($request, $result);

            return $result;
        } catch (SubscriptionException|PlanException $e) {
            throw $e instanceof SubscriptionException ? $e : new SubscriptionException($e->getMessage(), 0, $e);
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
            [$customerId, $subscriptionId] = $this->decodeMollieSubscriptionCode($subscriptionCode);

            $response = $this->makeRequest('GET', "/v2/customers/$customerId/subscriptions/$subscriptionId");
            $data = $this->parseResponse($response);

            return $this->mapMollieSubscriptionToResponse($data, $customerId);
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
            [$customerId, $subscriptionId] = $this->decodeMollieSubscriptionCode($action->subscriptionCode);

            $response = $this->makeRequest('DELETE', "/v2/customers/$customerId/subscriptions/$subscriptionId");
            $data = $this->parseResponse($response);

            $this->log('info', 'Subscription cancelled', ['subscription_code' => $action->subscriptionCode]);

            $result = $this->mapMollieSubscriptionToResponse($data, $customerId);
            $this->logSubscriptionFromResponse($result);

            return $result;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to cancel subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to cancel subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Mollie has no merchant-triggered resume - a cancelled subscription is
     * terminal, and `suspended` (an invalid-mandate state) is system-driven,
     * not something this action can clear. Throws rather than silently
     * no-op'ing. See ADR-0010.
     *
     * @throws SubscriptionException
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        throw new SubscriptionException(
            "Subscription {$action->subscriptionCode} cannot be re-enabled on Mollie - a cancelled ".
            'subscription is terminal and a suspended one requires the customer to provide a new valid '.
            'mandate. Create a new subscription instead.'
        );
    }

    /**
     * List customer subscriptions.
     *
     * Mollie's list endpoint is customer-scoped, not global - $customer is
     * required, unlike every other driver's optional filter. See ADR-0010.
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionException
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array
    {
        try {
            if (! $customer) {
                throw new SubscriptionException(
                    'Mollie subscriptions are listed per-customer, not globally - pass $customer (the '.
                    'customer\'s email address) to listSubscriptions().'
                );
            }

            if (($page ?? 1) > 1) {
                $this->log('warning', 'Mollie uses cursor-based pagination - only the first page can be served', [
                    'requested_page' => $page,
                ]);
            }

            $customerObject = $this->findMollieCustomerByEmail($customer);
            if (! $customerObject) {
                return ['data' => [], 'has_more' => false];
            }
            $customerId = $customerObject['id'];

            $response = $this->makeRequest('GET', "/v2/customers/$customerId/subscriptions", [
                'query' => array_filter(['limit' => $perPage ?? 50]),
            ]);
            $data = $this->parseResponse($response);

            $items = $data['_embedded']['subscriptions'] ?? [];

            return [
                'data' => array_map(
                    fn ($item) => $this->mapMollieSubscriptionToResponse($item, $customerId, $customer),
                    $items
                ),
                'has_more' => isset($data['_links']['next']),
            ];
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list subscriptions', ['error' => $e->getMessage()]);
            throw new SubscriptionException('Failed to list subscriptions: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws SubscriptionException
     */
    private function decodeMollieSubscriptionCode(string $subscriptionCode): array
    {
        $parts = explode(':', $subscriptionCode, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new SubscriptionException(
                "Invalid Mollie subscription code [$subscriptionCode] - expected the ".
                '"customerId:subscriptionId" format returned by createSubscription()/fetchSubscription().'
            );
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMollieCustomerByEmail(string $email): ?array
    {
        // Mollie's list-customers endpoint has no server-side email filter,
        // so this scans up to 250 most-recent customers client-side. A
        // known limitation for accounts with more customers than that -
        // acceptable here since the alternative (creating a duplicate
        // customer on every call) is worse.
        $response = $this->makeRequest('GET', '/v2/customers', ['query' => ['limit' => 250]]);
        $data = $this->parseResponse($response);

        foreach ($data['_embedded']['customers'] ?? [] as $customer) {
            if (($customer['email'] ?? null) === $email) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateMollieCustomer(string $email): array
    {
        return $this->findMollieCustomerByEmail($email) ?? $this->createMollieCustomer($email);
    }

    /**
     * @return array<string, mixed>
     */
    private function createMollieCustomer(string $email): array
    {
        $response = $this->makeRequest('POST', '/v2/customers', ['json' => ['email' => $email]]);

        return $this->parseResponse($response);
    }

    private function mapIntervalToMollie(string $interval): string
    {
        return match ($interval) {
            'daily' => '1 day',
            'weekly' => '1 week',
            'annually' => '12 months',
            default => '1 month',
        };
    }

    private function mapIntervalFromMollie(string $interval): string
    {
        if (preg_match('/^(\d+)\s+(day|week|month)s?$/i', trim($interval), $matches)) {
            $count = (int) $matches[1];
            $unit = strtolower($matches[2]);

            return match (true) {
                $unit === 'day' => 'daily',
                $unit === 'week' => 'weekly',
                $unit === 'month' && $count >= 12 => 'annually',
                default => 'monthly',
            };
        }

        return 'monthly';
    }

    /**
     * SubscriptionResponseDTO::status feeds Enums\SubscriptionStatus - see
     * the identical note in StripeSubscriptionMethods. Mollie's `suspended`
     * (system-driven, invalid mandate) maps to 'attention' rather than
     * 'non-renewing', since it isn't merchant-resumable - see
     * enableSubscription()'s unconditional throw.
     */
    private function mapMollieSubscriptionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'active',
            'canceled' => 'cancelled',
            'completed' => 'completed',
            'pending', 'suspended' => 'attention',
            default => 'attention',
        };
    }

    private function encodeMolliePlanData(string $name, float $amount, string $interval, string $currency): string
    {
        return base64_encode(json_encode([
            'name' => $name,
            'amount' => $amount,
            'interval' => $interval,
            'currency' => $currency,
        ]));
    }

    /**
     * @return array{name: string, amount: float, interval: string, currency: string}
     *
     * @throws PlanException
     */
    private function decodeMolliePlanData(string $planCode): array
    {
        $decoded = base64_decode($planCode, true);
        $data = $decoded !== false ? json_decode($decoded, true) : null;

        if (! is_array($data) || ! isset($data['name'], $data['amount'], $data['interval'], $data['currency'])) {
            throw new PlanException(
                "Invalid Mollie plan code [$planCode] - expected a value returned by ".
                'createPlan()/fetchPlan()/updatePlan().'
            );
        }

        return [
            'name' => (string) $data['name'],
            'amount' => (float) $data['amount'],
            'interval' => (string) $data['interval'],
            'currency' => (string) $data['currency'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function molliePlanCodeFromSubscriptionData(array $data): string
    {
        $amount = $data['amount'] ?? [];

        return $this->encodeMolliePlanData(
            $data['description'] ?? '',
            (float) ($amount['value'] ?? 0),
            $this->mapIntervalFromMollie($data['interval'] ?? '1 month'),
            $amount['currency'] ?? 'EUR'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapMollieSubscriptionToResponse(array $data, string $customerId, ?string $customerEmail = null): SubscriptionResponseDTO
    {
        $amount = $data['amount'] ?? [];

        return new SubscriptionResponseDTO(
            subscriptionCode: $customerId.':'.($data['id'] ?? ''),
            status: $this->mapMollieSubscriptionStatus($data['status'] ?? 'pending'),
            customer: $customerEmail ?? '',
            plan: $this->molliePlanCodeFromSubscriptionData($data),
            amount: (float) ($amount['value'] ?? 0),
            currency: $amount['currency'] ?? 'EUR',
            nextPaymentDate: $data['nextPaymentDate'] ?? null,
            metadata: array_filter(['mandate_id' => $data['mandateId'] ?? null]),
            provider: $this->getName(),
        );
    }
}
