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
 * Trait providing PayPal subscription functionality.
 *
 * Plans map to Catalog Product + Billing Plan pairs. Cancel/enable map to
 * PayPal's reversible suspend/activate pair (not the permanent /cancel
 * endpoint, reachable via $action->option('permanent', true) instead).
 * Subscriptions require a callbackUrl for the approval redirect.
 * listSubscriptions() throws, since PayPal's REST API has no such endpoint.
 */
trait PayPalSubscriptionMethods
{
    use LogsSubscriptionTransactions;

    /**
     * @throws PlanException
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            $product = $this->parseResponse($this->makeRequest('POST', '/v1/catalogs/products', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => array_filter([
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'type' => 'SERVICE',
                    'category' => 'SOFTWARE',
                ], fn ($value) => $value !== null),
            ]));

            $data = $this->parseResponse($this->makeRequest('POST', '/v1/billing/plans', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => [
                    'product_id' => $product['id'],
                    'name' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'billing_cycles' => [[
                        'frequency' => $this->mapIntervalToPayPalFrequency($plan->interval),
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value' => number_format($plan->amount, 2, '.', ''),
                                'currency_code' => $plan->currency,
                            ],
                        ],
                    ]],
                    'payment_preferences' => [
                        'auto_bill_outstanding' => true,
                        'payment_failure_threshold' => 3,
                    ],
                ],
            ]));

            $this->log('info', 'Subscription plan created', [
                'plan_code' => $data['id'],
                'name' => $plan->name,
            ]);

            return $this->mapPayPalPlanToResponse($data);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create plan', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to create plan: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a subscription plan.
     *
     * Only `description` (via PATCH) and `amount`/`currency` (via PayPal's
     * dedicated update-pricing-schemes endpoint - fixed-price plans can't
     * change price via the generic PATCH) are supported. Other fields are
     * ignored rather than guessed at, since PayPal's documented set of
     * PATCH-able plan fields is narrow.
     *
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public function updatePlan(string $planCode, array $updates): PlanResponseDTO
    {
        try {
            if (isset($updates['description'])) {
                $this->makeRequest('PATCH', "/v1/billing/plans/$planCode", [
                    'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                    'json' => [[
                        'op' => 'replace',
                        'path' => '/description',
                        'value' => $updates['description'],
                    ]],
                ]);
            }

            if (isset($updates['amount'])) {
                $currency = $updates['currency'] ?? $this->fetchPlan($planCode)->currency;

                $this->makeRequest('POST', "/v1/billing/plans/$planCode/update-pricing-schemes", [
                    'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                    'json' => [
                        'pricing_schemes' => [[
                            'billing_cycle_sequence' => 1,
                            'pricing_scheme' => [
                                'fixed_price' => [
                                    'value' => number_format((float) $updates['amount'], 2, '.', ''),
                                    'currency_code' => $currency,
                                ],
                            ],
                        ]],
                    ],
                ]);
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
            $data = $this->parseResponse($this->makeRequest('GET', "/v1/billing/plans/$planCode", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]));

            return $this->mapPayPalPlanToResponse($data);
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
            $data = $this->parseResponse($this->makeRequest('GET', '/v1/billing/plans', [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'query' => [
                    'page_size' => $perPage ?? 50,
                    'page' => $page ?? 1,
                    'total_required' => 'true',
                ],
            ]));

            return [
                'data' => array_map(fn ($item) => $this->mapPayPalPlanToResponse($item), $data['plans'] ?? []),
                'total_items' => $data['total_items'] ?? null,
            ];
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list plans', ['error' => $e->getMessage()]);
            throw new PlanException('Failed to list plans: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a subscription.
     *
     * Requires $request->callbackUrl - PayPal subscriptions need a customer
     * approval redirect (return_url/cancel_url). The created subscription
     * starts in APPROVAL_PENDING, not active - the approval link is
     * returned in $response->metadata['approval_url']; the customer must
     * complete it before billing starts.
     *
     * @throws SubscriptionException
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            if (! $request->callbackUrl) {
                throw new SubscriptionException(
                    'PayPal requires a callback URL for the customer approval redirect. '.
                    'Use ->callbackUrl() in your subscription chain to set it.'
                );
            }

            $returnUrl = $this->appendQueryParam($request->callbackUrl, 'status', 'success');
            $cancelUrl = $this->appendQueryParam($request->callbackUrl, 'status', 'cancelled');

            $payload = array_filter([
                'plan_id' => $request->plan,
                'quantity' => $request->quantity ? (string) $request->quantity : null,
                'subscriber' => ['email_address' => $request->customer],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'SUBSCRIBE_NOW',
                ],
                'custom_id' => $request->metadata['reference'] ?? null,
            ], fn ($value) => $value !== null);

            $headers = ['Authorization' => 'Bearer '.$this->getAccessToken()];
            if ($request->idempotencyKey) {
                $headers['PayPal-Request-Id'] = $request->idempotencyKey;
            }

            $data = $this->parseResponse($this->makeRequest('POST', '/v1/billing/subscriptions', [
                'headers' => $headers,
                'json' => $payload,
            ]));

            $this->log('info', 'Subscription created', [
                'subscription_code' => $data['id'],
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            $approvalUrl = null;
            foreach ($data['links'] ?? [] as $link) {
                if (($link['rel'] ?? null) === 'approve') {
                    $approvalUrl = $link['href'] ?? null;
                    break;
                }
            }

            $response = $this->mapPayPalSubscriptionToResponse($data, $request->customer, $approvalUrl);
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
            $data = $this->parseResponse($this->makeRequest('GET', "/v1/billing/subscriptions/$subscriptionCode", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]));

            return $this->mapPayPalSubscriptionToResponse($data);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch subscription', [
                'subscription_code' => $subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to fetch subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancel (suspend) a subscription.
     *
     * Maps to PayPal's /suspend endpoint by default - reversible, matching
     * Paystack's disable/enable semantics. Pass
     * $action->option('permanent', true) to instead call PayPal's
     * irreversible /cancel endpoint. $action->option('reason', ...)
     * overrides the default reason text sent to PayPal.
     *
     * @throws SubscriptionException
     */
    public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $permanent = (bool) $action->option('permanent', false);
            $reason = (string) $action->option('reason', 'Cancelled by merchant');
            $endpoint = $permanent ? 'cancel' : 'suspend';

            $this->makeRequest('POST', "/v1/billing/subscriptions/$action->subscriptionCode/$endpoint", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => ['reason' => $reason],
            ]);

            $this->log('info', 'Subscription cancelled', [
                'subscription_code' => $action->subscriptionCode,
                'permanent' => $permanent,
            ]);

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
     * Enable (activate) a suspended subscription.
     *
     * PayPal's /activate endpoint only works on a SUSPENDED subscription -
     * a permanently CANCELLED one (see cancelSubscription()'s 'permanent'
     * option) cannot be reactivated. Throws a clear exception rather than
     * silently failing or approximating incorrect behavior.
     *
     * @throws SubscriptionException
     */
    public function enableSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
    {
        try {
            $current = $this->parseResponse($this->makeRequest('GET', "/v1/billing/subscriptions/$action->subscriptionCode", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]));

            if (($current['status'] ?? null) === 'CANCELLED') {
                throw new SubscriptionException(
                    "Subscription $action->subscriptionCode is permanently cancelled on PayPal and cannot be ".
                    'reactivated - create a new subscription instead. Only a suspended subscription can be activated.'
                );
            }

            $this->makeRequest('POST', "/v1/billing/subscriptions/$action->subscriptionCode/activate", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => ['reason' => (string) $action->option('reason', 'Reactivated by merchant')],
            ]);

            $this->log('info', 'Subscription enabled', ['subscription_code' => $action->subscriptionCode]);

            $response = $this->fetchSubscription($action->subscriptionCode);
            $this->logSubscriptionFromResponse($response);

            return $response;
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to enable subscription', [
                'subscription_code' => $action->subscriptionCode,
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException('Failed to enable subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * PayPal's REST API has no endpoint to list subscriptions (only create,
     * fetch-by-id, and the state-transition endpoints exist) - throws
     * rather than returning an empty result that could be mistaken for "no
     * subscriptions exist".
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionException
     */
    public function listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array
    {
        throw new SubscriptionException(
            'PayPal does not provide an API to list subscriptions - only fetching a specific subscription '.
            'by ID is supported. Track subscription codes in your own application if you need to list them '.
            '(e.g. via the subscription_transactions table this package already persists to).'
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function mapIntervalToPayPalFrequency(string $interval): array
    {
        return match ($interval) {
            'daily' => ['interval_unit' => 'DAY', 'interval_count' => 1],
            'weekly' => ['interval_unit' => 'WEEK', 'interval_count' => 1],
            'annually' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
            default => ['interval_unit' => 'MONTH', 'interval_count' => 1],
        };
    }

    /**
     * @param  array<string, mixed>  $frequency
     */
    private function mapIntervalFromPayPal(array $frequency): string
    {
        return match ($frequency['interval_unit'] ?? 'MONTH') {
            'DAY' => 'daily',
            'WEEK' => 'weekly',
            'YEAR' => 'annually',
            default => 'monthly',
        };
    }

    /**
     * SubscriptionResponseDTO::status feeds Enums\SubscriptionStatus, which
     * has its own vocabulary - not PayPal's. SUSPENDED maps to
     * 'non-renewing' deliberately: it's PayPal's reversible-pause state,
     * the same shape as the enum's NON_RENEWING (canBeResumed() === true).
     */
    private function mapPayPalSubscriptionStatus(string $status): string
    {
        return match ($status) {
            'ACTIVE', 'APPROVED' => 'active',
            'APPROVAL_PENDING' => 'attention',
            'SUSPENDED' => 'non-renewing',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
            default => strtolower($status),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapPayPalPlanToResponse(array $data): PlanResponseDTO
    {
        $cycle = $data['billing_cycles'][0] ?? [];
        $price = $cycle['pricing_scheme']['fixed_price'] ?? [];

        return new PlanResponseDTO(
            planCode: $data['id'],
            name: $data['name'] ?? '',
            amount: (float) ($price['value'] ?? 0),
            interval: $this->mapIntervalFromPayPal($cycle['frequency'] ?? []),
            currency: $price['currency_code'] ?? 'USD',
            description: $data['description'] ?? null,
            metadata: array_filter(['product_id' => $data['product_id'] ?? null]),
            provider: $this->getName(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapPayPalSubscriptionToResponse(
        array $data,
        ?string $fallbackCustomerEmail = null,
        ?string $approvalUrl = null
    ): SubscriptionResponseDTO {
        $metadata = $approvalUrl ? ['approval_url' => $approvalUrl] : [];

        return new SubscriptionResponseDTO(
            subscriptionCode: $data['id'],
            status: $this->mapPayPalSubscriptionStatus($data['status'] ?? 'APPROVAL_PENDING'),
            customer: $data['subscriber']['email_address'] ?? $fallbackCustomerEmail ?? '',
            plan: $data['plan_id'] ?? '',
            amount: (float) ($data['billing_info']['last_payment']['amount']['value'] ?? 0),
            currency: $data['billing_info']['last_payment']['amount']['currency_code'] ?? 'USD',
            nextPaymentDate: $data['billing_info']['next_billing_time'] ?? null,
            metadata: $metadata,
            provider: $this->getName(),
        );
    }
}
