<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionLifecycleHooks;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Services\SubscriptionValidator;

final class Subscription
{
    protected PaymentManager $manager;

    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<int, string> */
    protected array $providers = [];

    protected ?string $subscriptionCode = null;

    protected ?string $planCode = null;

    /** @var array<string, mixed> Provider-specific options for cancel/enable actions. */
    protected array $actionOptions = [];

    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Set the provider(s) to use
     *
     * @param  string|array<int, string>  $providers
     */
    public function with(string|array $providers): self
    {
        $this->providers = is_array($providers) ? $providers : [$providers];

        return $this;
    }

    /**
     * Alias for with() method
     *
     * @param  string|array<int, string>  $providers
     */
    public function using(string|array $providers): self
    {
        return $this->with($providers);
    }

    /**
     * Set the subscription code (for operations on existing subscriptions)
     */
    public function code(string $subscriptionCode): self
    {
        $this->subscriptionCode = $subscriptionCode;

        return $this;
    }

    /**
     * Set customer email or code
     */
    public function customer(string $customer): self
    {
        $this->data['customer'] = $customer;

        return $this;
    }

    /**
     * Set the plan code (for subscriptions or plan operations)
     */
    public function plan(string $plan): self
    {
        $this->data['plan'] = $plan;
        $this->planCode = $plan;

        return $this;
    }

    /**
     * Set quantity
     */
    public function quantity(int $quantity): self
    {
        $this->data['quantity'] = $quantity;

        return $this;
    }

    /**
     * Set start date
     */
    public function startDate(string $startDate): self
    {
        $this->data['start_date'] = $startDate;

        return $this;
    }

    /**
     * Set trial period in days
     */
    public function trialDays(int $days): self
    {
        $this->data['trial_days'] = $days;

        return $this;
    }

    /**
     * Set authorization code (for card)
     */
    public function authorization(string $authorization): self
    {
        $this->data['authorization'] = $authorization;

        return $this;
    }

    /**
     * Set the callback/return URL for providers whose subscription creation
     * requires a customer approval redirect (e.g. PayPal). Ignored by
     * providers that don't need one.
     */
    public function callbackUrl(string $callbackUrl): self
    {
        $this->data['callback_url'] = $callbackUrl;

        return $this;
    }

    /**
     * Set metadata
     *
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->data['metadata'] = $metadata;

        return $this;
    }

    /**
     * Set idempotency key for subscription operations
     *
     * @param  string|null  $key  Idempotency key. If null, a UUID will be generated automatically.
     */
    public function idempotency(?string $key = null): self
    {
        $this->data['idempotency_key'] = $key ?? Str::uuid()->toString();

        return $this;
    }

    /**
     * Create a new subscription
     */
    public function create(): SubscriptionResponseDTO
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                'Provider does not support subscriptions'
            );
        }

        $request = SubscriptionRequestDTO::fromArray($this->data);

        if ($driver instanceof SubscriptionLifecycleHooks) {
            $request = $driver->beforeSubscriptionCreate($request);
        }

        $config = app('payments.config') ?? config('payments', []);
        if ($config['subscriptions']['validation']['enabled'] ?? true) {
            $validator = app(SubscriptionValidator::class);
            $validator->validateCreation($request, $driver);
        }

        $response = $driver->createSubscription($request);

        if ($driver instanceof SubscriptionLifecycleHooks) {
            $driver->afterSubscriptionCreate($response);
        }

        return $response;
    }

    /**
     * Alias for create()
     */
    public function subscribe(): SubscriptionResponseDTO
    {
        return $this->create();
    }

    /**
     * Fetch subscription details
     */
    public function fetch(): SubscriptionResponseDTO
    {
        if (! $this->subscriptionCode) {
            throw new PaymentException('Subscription code is required. Use ->code($code)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        return $driver->fetchSubscription($this->subscriptionCode);
    }

    /**
     * Set the email token for cancel/enable operations (Paystack-specific;
     * ignored by providers that don't require one). Shorthand for
     * ->option('token', $token).
     */
    public function token(string $token): self
    {
        return $this->option('token', $token);
    }

    /**
     * Set a provider-specific option for cancel/enable operations (see
     * DataObjects\SubscriptionActionDTO). Use this for anything a given
     * provider needs beyond the subscription code - e.g. Paystack's email
     * confirmation token.
     */
    public function option(string $key, mixed $value): self
    {
        $this->actionOptions[$key] = $value;

        return $this;
    }

    /**
     * Cancel the subscription.
     *
     * $token is a convenience shorthand for Paystack's required email
     * confirmation token; providers that don't need one simply ignore it.
     * Use ->option() for other providers' requirements.
     */
    public function cancel(?string $token = null): SubscriptionResponseDTO
    {
        if (! $this->subscriptionCode) {
            throw new PaymentException('Subscription code is required. Use ->code($code)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        if ($driver instanceof SubscriptionLifecycleHooks) {
            $driver->beforeSubscriptionCancel($this->subscriptionCode);
        }

        $config = app('payments.config') ?? config('payments', []);
        if ($config['subscriptions']['validation']['enabled'] ?? true) {
            $validator = app(SubscriptionValidator::class);
            $validator->validateCancellation($this->subscriptionCode, $driver);
        }

        $response = $driver->cancelSubscription($this->buildAction($this->subscriptionCode, $token));

        if ($driver instanceof SubscriptionLifecycleHooks) {
            $driver->afterSubscriptionCancel($response);
        }

        return $response;
    }

    /**
     * Enable a canceled subscription. See cancel() - same $token shorthand.
     */
    public function enable(?string $token = null): SubscriptionResponseDTO
    {
        if (! $this->subscriptionCode) {
            throw new PaymentException('Subscription code is required. Use ->code($code)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        return $driver->enableSubscription($this->buildAction($this->subscriptionCode, $token));
    }

    /**
     * Build the SubscriptionActionDTO for cancel()/enable(), merging any
     * options set via ->option()/->token() with an inline $token argument
     * (the inline argument wins, matching the pre-v2.0 calling convention).
     */
    private function buildAction(string $subscriptionCode, ?string $token): SubscriptionActionDTO
    {
        $options = $this->actionOptions;
        if ($token !== null) {
            $options['token'] = $token;
        }

        return new SubscriptionActionDTO($subscriptionCode, $options);
    }

    /**
     * Set pagination parameters
     */
    public function perPage(int $perPage): self
    {
        $this->data['per_page'] = $perPage;

        return $this;
    }

    /**
     * Set page number
     */
    public function page(int $page): self
    {
        $this->data['page'] = $page;

        return $this;
    }

    /**
     * List subscriptions
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function list(?string $customer = null): array
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        $perPage = $this->data['per_page'] ?? 50;
        $page = $this->data['page'] ?? 1;

        return $driver->listSubscriptions($perPage, $page, $customer);
    }

    /**
     * Set plan data for creation
     */
    public function planData(SubscriptionPlanDTO $plan): self
    {
        $this->data['plan_data'] = $plan;

        return $this;
    }

    /**
     * Set plan updates
     *
     * @param  array<string, mixed>  $updates
     */
    public function planUpdates(array $updates): self
    {
        $this->data['plan_updates'] = $updates;

        return $this;
    }

    /**
     * Create a subscription plan
     *
     *
     * @throws PaymentException
     */
    public function createPlan(): PlanResponseDTO
    {
        if (! isset($this->data['plan_data'])) {
            throw new PaymentException('Plan data is required. Use ->planData($planDTO)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        return $driver->createPlan($this->data['plan_data']);
    }

    /**
     * Update a subscription plan
     *
     *
     * @throws PaymentException
     */
    public function updatePlan(): PlanResponseDTO
    {
        if (! $this->planCode) {
            throw new PaymentException('Plan code is required. Use ->plan($planCode)');
        }

        if (! isset($this->data['plan_updates'])) {
            throw new PaymentException('Plan updates are required. Use ->planUpdates($updates)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        return $driver->updatePlan($this->planCode, $this->data['plan_updates']);
    }

    /**
     * Fetch a subscription plan
     *
     *
     * @throws PaymentException
     */
    public function fetchPlan(): PlanResponseDTO
    {
        if (! $this->planCode) {
            throw new PaymentException('Plan code is required. Use ->plan($planCode)');
        }

        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        return $driver->fetchPlan($this->planCode);
    }

    /**
     * List subscription plans
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function listPlans(): array
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsSubscriptionsInterface)) {
            throw new PaymentException(
                "Provider [{$this->getProviderName()}] does not support subscriptions"
            );
        }

        $perPage = $this->data['per_page'] ?? 50;
        $page = $this->data['page'] ?? 1;

        return $driver->listPlans($perPage, $page);
    }

    protected function getDriver(): Contracts\DriverInterface
    {
        $providerName = $this->getProviderName();

        return $this->manager->driver($providerName);
    }

    protected function getProviderName(): string
    {
        if (! empty($this->providers)) {
            return $this->providers[0];
        }

        return $this->manager->getDefaultDriver();
    }
}
