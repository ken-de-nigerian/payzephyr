<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

/**
 * Fluent query builder for advanced subscription filtering and retrieval.
 *
 * Provides a convenient way to filter, paginate, and retrieve subscriptions
 * with a fluent interface similar to Laravel's query builder.
 *
 * @example
 * Payment::subscriptions()
 *     ->forCustomer('user@example.com')
 *     ->active()
 *     ->forPlan('PLAN_123')
 *     ->take(10)
 *     ->get();
 */
final class SubscriptionQuery
{
    protected PaymentManager $manager;

    protected ?string $provider = null;

    protected ?string $customer = null;

    protected ?string $planCode = null;

    protected ?string $status = null;

    protected ?string $createdAfter = null;

    protected ?string $createdBefore = null;

    protected int $perPage = 50;

    protected int $page = 1;

    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Filter subscriptions by customer email.
     */
    public function forCustomer(string $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Filter subscriptions by plan code.
     */
    public function forPlan(string $planCode): self
    {
        $this->planCode = $planCode;

        return $this;
    }

    /**
     * Filter subscriptions by status.
     */
    public function whereStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Filter for active subscriptions (shorthand).
     */
    public function active(): self
    {
        return $this->whereStatus('active');
    }

    /**
     * Filter for canceled subscriptions (shorthand).
     */
    public function cancelled(): self
    {
        return $this->whereStatus('cancelled');
    }

    /**
     * Filter subscriptions created after the given date.
     *
     * @param  string  $date  Date string (Y-m-d or any format parseable by strtotime)
     */
    public function createdAfter(string $date): self
    {
        $this->createdAfter = $date;

        return $this;
    }

    /**
     * Filter subscriptions created before the given date.
     *
     * @param  string  $date  Date string (Y-m-d or any format parseable by strtotime)
     */
    public function createdBefore(string $date): self
    {
        $this->createdBefore = $date;

        return $this;
    }

    /**
     * Set the number of results per page.
     */
    public function take(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Set the page number.
     */
    public function page(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    /**
     * Use a specific provider for the query.
     */
    public function from(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Execute the query and return all matching subscriptions.
     *
     * @return array<string, mixed> Array of subscription data
     *
     * @throws PaymentException
     */
    public function get(): array
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        $results = $driver->listSubscriptions($this->perPage, $this->page, $this->customer);

        return $this->applyFilters($results);
    }

    /**
     * Get the first matching subscription.
     *
     *
     * @throws PaymentException
     */
    public function first(): ?SubscriptionResponseDTO
    {
        $results = $this->take(1)->get();
        $subscriptions = $results['data'] ?? $results;

        if (empty($subscriptions)) {
            return null;
        }

        $first = is_array($subscriptions) && isset($subscriptions[0]) ? $subscriptions[0] : $subscriptions;

        if ($first instanceof SubscriptionResponseDTO) {
            return $first;
        }

        return SubscriptionResponseDTO::fromArray($first);
    }

    /**
     * Count matching subscriptions.
     *
     *
     * @throws PaymentException
     */
    public function count(): int
    {
        $results = $this->get();
        $subscriptions = $results['data'] ?? $results;

        return is_countable($subscriptions) ? count($subscriptions) : 0;
    }

    /**
     * Check if any subscriptions match the query.
     *
     *
     * @throws PaymentException
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Apply filters that aren't supported by the provider API in memory.
     *
     * Returns the same shape it was given: a paginated ['data' => ..., 'meta'
     * => ...] envelope when $results had a 'data' key, otherwise the plain
     * filtered list.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>|list<mixed>
     */
    protected function applyFilters(array $results): array
    {
        $subscriptions = $results['data'] ?? $results;

        if (! is_array($subscriptions) || empty($subscriptions)) {
            return $results;
        }

        $filtered = [];

        foreach ($subscriptions as $subscription) {
            $normalized = $this->normalizeForFiltering($subscription);

            if ($this->planCode !== null && $normalized['plan_code'] !== $this->planCode) {
                continue;
            }

            if ($this->status !== null && strtolower($normalized['status']) !== strtolower($this->status)) {
                continue;
            }

            if ($this->createdAfter !== null && $normalized['created_at'] && strtotime($normalized['created_at']) < strtotime($this->createdAfter)) {
                continue;
            }

            if ($this->createdBefore !== null && $normalized['created_at'] && strtotime($normalized['created_at']) > strtotime($this->createdBefore)) {
                continue;
            }

            $filtered[] = $subscription;
        }

        if (isset($results['data'])) {
            $results['data'] = $filtered;
            $results['meta']['filtered_count'] = count($filtered);

            /** @var array<string, mixed> $results */
            return $results;
        }

        return $filtered;
    }

    /**
     * Normalize a single subscription entry - either a raw provider array
     * (Paystack) or a SubscriptionResponseDTO object (every other
     * subscription-capable driver) - into a common shape the filters above
     * can read uniformly.
     *
     * @param  SubscriptionResponseDTO|array<string, mixed>  $subscription
     * @return array{plan_code: ?string, status: string, created_at: ?string}
     */
    private function normalizeForFiltering(SubscriptionResponseDTO|array $subscription): array
    {
        if ($subscription instanceof SubscriptionResponseDTO) {
            return [
                'plan_code' => $subscription->plan,
                'status' => $subscription->status,
                'created_at' => null,
            ];
        }

        return [
            'plan_code' => $subscription['plan']['plan_code'] ?? $subscription['plan_code'] ?? null,
            'status' => (string) ($subscription['status'] ?? ''),
            'created_at' => $subscription['created_at'] ?? $subscription['createdAt'] ?? null,
        ];
    }

    protected function getDriver(): Contracts\DriverInterface
    {
        $providerName = $this->getProviderName();

        return $this->manager->driver($providerName);
    }

    protected function getProviderName(): string
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        return $this->manager->getDefaultDriver();
    }
}
