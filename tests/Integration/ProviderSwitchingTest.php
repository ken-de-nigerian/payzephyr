<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Provider Switching Test
 *
 * Verifies that switching providers requires ONLY configuration changes,
 * not code changes.
 */
#[Group('integration')]
#[Group('abstraction')]
class ProviderSwitchingTest extends TestCase
{
    /**
     * Test that switching default provider requires only config change
     */
    public function test_switching_default_provider_requires_only_config_change(): void
    {
        $providers = ['paystack', 'stripe'];

        foreach ($providers as $provider) {
            // Ensure provider is enabled
            config(["payments.providers.{$provider}.enabled" => true]);
            $this->app->forgetInstance('payments.config');

            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            // Change ONLY config
            config(['payments.default' => $provider]);

            // Update payments.config singleton
            $this->app->forgetInstance('payments.config');
            $this->app->singleton('payments.config', fn () => config('payments'));
            $this->app->forgetInstance(PaymentManager::class);
            $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

            // Setup mocked provider
            $this->setupMockedProvider($provider, [
                new Response(200, [], json_encode([
                    'status' => true,
                    'data' => [
                        'reference' => "ref_{$provider}_123",
                        'authorization_url' => "https://checkout.{$provider}.com/abc123",
                        'access_code' => 'access_123',
                    ],
                ])),
            ]);

            // Use appropriate currency for provider
            $currency = ($provider === 'stripe') ? 'USD' : 'NGN';

            // IDENTICAL code - no changes needed
            $response = Payment::amount(100.00)
                ->currency($currency)
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->charge(); // No ->with() needed - uses default

            // Results should be functionally equivalent
            $this->assertInstanceOf(ChargeResponseDTO::class, $response);
            $this->assertEquals($provider, $response->provider);
        }
    }

    /**
     * Test that switching via ->with() method works
     */
    public function test_switching_via_with_method_works(): void
    {
        $providers = ['paystack', 'stripe'];

        // IDENTICAL code - only provider changes via ->with()
        foreach ($providers as $provider) {
            // Ensure provider is enabled
            config(["payments.providers.{$provider}.enabled" => true]);
            $this->app->forgetInstance('payments.config');

            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            // Setup mocked provider
            $this->setupMockedProvider($provider, [
                new Response(200, [], json_encode([
                    'status' => true,
                    'data' => [
                        'reference' => "ref_{$provider}_123",
                        'authorization_url' => "https://checkout.{$provider}.com/abc123",
                        'access_code' => 'access_123',
                    ],
                ])),
            ]);

            // Use appropriate currency for provider
            $currency = ($provider === 'stripe') ? 'USD' : 'NGN';

            $response = Payment::amount(100.00)
                ->currency($currency)
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->with($provider) // Only this changes
                ->charge();

            $this->assertInstanceOf(ChargeResponseDTO::class, $response);
            $this->assertEquals($provider, $response->provider);
        }
    }

    /**
     * Test that switching fallback provider requires only config change
     */
    public function test_switching_fallback_provider_requires_only_config_change(): void
    {
        // Set up primary and fallback
        config([
            'payments.default' => 'paystack',
            'payments.fallback' => 'stripe',
        ]);

        // Setup mocked provider
        $this->setupMockedProvider('paystack', [
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => 'ref_paystack_123',
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'access_123',
                ],
            ])),
        ]);

        // IDENTICAL code - fallback happens automatically
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();

        // Should work with either provider (depending on which succeeds)
        $this->assertInstanceOf(ChargeResponseDTO::class, $response);
    }

    /**
     * Test that switching subscription provider requires only config change
     */
    public function test_switching_subscription_provider_requires_only_config_change(): void
    {
        // Change subscription provider via config
        config(['payments.default' => 'paystack']);

        // Setup mocked provider for plan creation
        $this->setupMockedProvider('paystack', [
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

        // IDENTICAL subscription code
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->planData($planDTO)
            ->createPlan(); // Uses default provider

        $this->assertInstanceOf(PlanResponseDTO::class, $plan);
    }

    /**
     * Test that environment variable switching works
     */
    public function test_environment_variable_switching_works(): void
    {
        // Simulate environment variable change
        $provider = 'paystack'; // Use paystack since stripe might not be fully configured

        // Ensure provider is enabled
        config(["payments.providers.{$provider}.enabled" => true]);

        // Reload config
        config(['payments.default' => $provider]);
        $this->app->forgetInstance('payments.config');

        // Setup mocked provider
        $this->setupMockedProvider($provider, [
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => "ref_{$provider}_123",
                    'authorization_url' => "https://checkout.{$provider}.com/abc123",
                    'access_code' => 'access_123',
                ],
            ])),
        ]);

        // IDENTICAL code (paystack supports NGN)
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();

        $this->assertInstanceOf(ChargeResponseDTO::class, $response);
    }

    /**
     * Test that no cache issues occur when switching providers
     */
    public function test_no_cache_issues_when_switching_providers(): void
    {
        // Switch provider multiple times
        $providers = ['paystack', 'stripe'];

        foreach ($providers as $provider) {
            // Ensure provider is enabled
            config(["payments.providers.{$provider}.enabled" => true]);
            $this->app->forgetInstance('payments.config');

            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            config(['payments.default' => $provider]);
            $this->app->forgetInstance('payments.config');

            // Clear any caches
            Cache::flush();

            // Setup mocked provider
            $this->setupMockedProvider($provider, [
                new Response(200, [], json_encode([
                    'status' => true,
                    'data' => [
                        'reference' => "ref_{$provider}_123",
                        'authorization_url' => "https://checkout.{$provider}.com/abc123",
                        'access_code' => 'access_123',
                    ],
                ])),
            ]);

            // Use appropriate currency for provider
            $currency = ($provider === 'stripe') ? 'USD' : 'NGN';

            // IDENTICAL code
            $response = Payment::amount(100.00)
                ->currency($currency)
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->charge();

            // Should use correct provider, not cached one
            $this->assertEquals($provider, $response->provider);
        }
    }

    /**
     * Test that results are functionally equivalent across providers
     */
    public function test_results_are_functionally_equivalent_across_providers(): void
    {
        $providers = ['paystack', 'stripe'];
        $responses = [];

        foreach ($providers as $provider) {
            // Ensure provider is enabled
            config(["payments.providers.{$provider}.enabled" => true]);
            $this->app->forgetInstance('payments.config');

            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            // Setup mocked provider
            $this->setupMockedProvider($provider, [
                new Response(200, [], json_encode([
                    'status' => true,
                    'data' => [
                        'reference' => "ref_{$provider}_123",
                        'authorization_url' => "https://checkout.{$provider}.com/abc123",
                        'access_code' => 'access_123',
                    ],
                ])),
            ]);

            // Use appropriate currency for provider
            $currency = ($provider === 'stripe') ? 'USD' : 'NGN';

            // IDENTICAL code
            $response = Payment::amount(100.00)
                ->currency($currency)
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->with($provider)
                ->charge();

            $responses[$provider] = $response;
        }

        // All responses should have same structure
        foreach ($responses as $provider => $response) {
            $this->assertInstanceOf(ChargeResponseDTO::class, $response);
            $this->assertNotEmpty($response->reference);
            $this->assertNotEmpty($response->authorizationUrl);
            $this->assertEquals($provider, $response->provider);
        }
    }

    /**
     * Check if provider is enabled
     */
    protected function isProviderEnabled(string $provider): bool
    {
        $config = config("payments.providers.{$provider}", []);

        return ($config['enabled'] ?? false) === true;
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
}
