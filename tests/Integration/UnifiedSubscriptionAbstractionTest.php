<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Helpers\SubscriptionTestHelper;

/**
 * Unified Subscription Abstraction Verification Test
 *
 * Verifies that subscription operations work uniformly across providers
 * that support subscriptions.
 */
#[Group('integration')]
#[Group('abstraction')]
#[Group('subscriptions')]
class UnifiedSubscriptionAbstractionTest extends TestCase
{
    /**
     * Providers that support subscriptions
     *
     * @return array<string>
     */
    public static function subscriptionProviders(): array
    {
        return [
            ['paystack'],
            // Stripe and PayPal also implement SupportsSubscriptionsInterface as of
            // ADR-0009 (see tests/Unit/StripeSubscriptionTest.php and
            // tests/Unit/PayPalSubscriptionTest.php for their dedicated coverage) -
            // not added here because every mocked HTTP response in this file is
            // shaped for Paystack's API; adding them would need provider-specific
            // response fixtures throughout, not just this list.
        ];
    }

    /**
     * Test that plan creation works identically across subscription providers
     */
    #[DataProvider('subscriptionProviders')]
    public function test_plan_creation_works_identically(string $provider): void
    {
        // Mock HTTP client for plan creation
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Test Plan',
                    'amount' => 10000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // IDENTICAL code for all subscription providers
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN',
            description: 'Test subscription plan'
        );

        $plan = Payment::subscription()
            ->with($provider)
            ->planData($planDTO)
            ->createPlan();

        // Verify response structure is consistent
        $this->assertInstanceOf(PlanResponseDTO::class, $plan);
        $this->assertNotEmpty($plan->planCode);
        $this->assertEquals('Test Plan', $plan->name);
        $this->assertEquals(100.00, $plan->amount);
        $this->assertEquals($provider, $plan->provider);
    }

    /**
     * Test that subscription creation works identically across providers
     */
    #[DataProvider('subscriptionProviders')]
    public function test_subscription_creation_works_identically(string $provider): void
    {
        // Mock HTTP client for plan creation and subscription creation
        $mock = new MockHandler([
            // Plan creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Test Plan',
                    'amount' => 10000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
            ])),
            // Plan fetch for validation
            SubscriptionTestHelper::planMock('PLN_test123'),
            // Subscription creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'customer@example.com'],
                    'plan' => ['plan_code' => 'PLN_test123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // Create a plan first
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->with($provider)
            ->planData($planDTO)
            ->createPlan();

        // IDENTICAL subscription creation code
        $subscription = Payment::subscription()
            ->customer('customer@example.com')
            ->plan($plan->planCode)
            ->with($provider)
            ->create();

        // Verify response structure is consistent
        $this->assertInstanceOf(SubscriptionResponseDTO::class, $subscription);
        $this->assertNotEmpty($subscription->subscriptionCode);
        $this->assertIsString($subscription->status);
        $this->assertEquals($provider, $subscription->provider);
        $this->assertIsFloat($subscription->amount);
    }

    /**
     * Test that subscription retrieval works identically
     */
    #[DataProvider('subscriptionProviders')]
    public function test_subscription_retrieval_works_identically(string $provider): void
    {
        // Mock HTTP client for plan creation, subscription creation, and retrieval
        $mock = new MockHandler([
            // Plan creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Test Plan',
                    'amount' => 10000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
            ])),
            // Plan fetch for validation
            SubscriptionTestHelper::planMock('PLN_test123'),
            // Subscription creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'customer@example.com'],
                    'plan' => ['plan_code' => 'PLN_test123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
            // Subscription retrieval
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'customer@example.com'],
                    'plan' => ['plan_code' => 'PLN_test123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // Create subscription first
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->with($provider)
            ->planData($planDTO)
            ->createPlan();

        $subscription = Payment::subscription()
            ->customer('customer@example.com')
            ->plan($plan->planCode)
            ->with($provider)
            ->create();

        // IDENTICAL retrieval code
        $retrieved = Payment::subscription($subscription->subscriptionCode)
            ->with($provider)
            ->fetch();

        // Verify response structure is consistent
        $this->assertInstanceOf(SubscriptionResponseDTO::class, $retrieved);
        $this->assertEquals($subscription->subscriptionCode, $retrieved->subscriptionCode);
        $this->assertEquals($provider, $retrieved->provider);
    }

    /**
     * Test that providers without subscription support fail gracefully
     */
    public function test_providers_without_subscription_support_fail_gracefully(): void
    {
        // 'monnify' and 'opay' are the only two providers confirmed to lack a
        // provider-managed subscription API at all - see ADR-0010's research
        // findings. Every other provider now implements
        // SupportsSubscriptionsInterface (Paystack, Stripe, PayPal per
        // ADR-0009; Flutterwave, Square, Mollie per ADR-0010). If that
        // changes, point this at another provider that genuinely lacks
        // support rather than let providerSupportsSubscriptions() silently
        // skip every provider in this list and leave this test asserting
        // nothing.
        $nonSubscriptionProviders = ['monnify', 'opay'];

        foreach ($nonSubscriptionProviders as $provider) {
            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            if ($this->providerSupportsSubscriptions($provider)) {
                continue; // Skip if it actually supports subscriptions
            }

            $this->expectException(\KenDeNigerian\PayZephyr\Exceptions\PaymentException::class);
            $this->expectExceptionMessage('does not support subscriptions');

            Payment::subscription()
                ->customer('test@example.com')
                ->plan('PLAN_123')
                ->with($provider)
                ->create();
        }
    }

    /**
     * Test that fluent subscription API works identically
     */
    #[DataProvider('subscriptionProviders')]
    public function test_fluent_subscription_api_works_identically(string $provider): void
    {
        // Mock HTTP client for plan creation and subscription creation
        $mock = new MockHandler([
            // Plan creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Test Plan',
                    'amount' => 10000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
            ])),
            // Plan fetch for validation
            SubscriptionTestHelper::planMock('PLN_test123'),
            // Subscription creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'customer@example.com'],
                    'plan' => ['plan_code' => 'PLN_test123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // IDENTICAL fluent API usage
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->with($provider)
            ->planData($planDTO)
            ->createPlan();

        $subscription = Payment::subscription()
            ->customer('customer@example.com')
            ->plan($plan->planCode)
            ->metadata(['order_id' => '123'])
            ->quantity(1)
            ->with($provider)
            ->subscribe(); // Alias for create()

        $this->assertInstanceOf(SubscriptionResponseDTO::class, $subscription);
        $this->assertEquals($provider, $subscription->provider);
    }

    /**
     * Test that subscription response DTOs have consistent structure
     */
    #[DataProvider('subscriptionProviders')]
    public function test_subscription_response_dtos_have_consistent_structure(string $provider): void
    {
        // Mock HTTP client for plan creation and subscription creation
        $mock = new MockHandler([
            // Plan creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Test Plan',
                    'amount' => 10000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
            ])),
            // Plan fetch for validation
            SubscriptionTestHelper::planMock('PLN_test123'),
            // Subscription creation
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'customer@example.com'],
                    'plan' => ['plan_code' => 'PLN_test123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->with($provider)
            ->planData($planDTO)
            ->createPlan();

        $subscription = Payment::subscription()
            ->customer('customer@example.com')
            ->plan($plan->planCode)
            ->with($provider)
            ->create();

        // All providers must return same DTO structure
        $this->assertObjectHasProperty('subscriptionCode', $subscription);
        $this->assertObjectHasProperty('status', $subscription);
        $this->assertObjectHasProperty('customer', $subscription);
        $this->assertObjectHasProperty('plan', $subscription);
        $this->assertObjectHasProperty('amount', $subscription);
        $this->assertObjectHasProperty('currency', $subscription);
        $this->assertObjectHasProperty('provider', $subscription);

        // Verify DTO can be serialized
        $serialized = serialize($subscription);
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(SubscriptionResponseDTO::class, $unserialized);
    }

    /**
     * Check if provider supports subscriptions
     */
    protected function providerSupportsSubscriptions(string $provider): bool
    {
        try {
            $driver = app(\KenDeNigerian\PayZephyr\PaymentManager::class)->driver($provider);

            return $driver instanceof SupportsSubscriptionsInterface;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Check if provider is enabled in config
     */
    protected function isProviderEnabled(string $provider): bool
    {
        $config = config("payments.providers.{$provider}", []);

        return ($config['enabled'] ?? false) === true;
    }
}
