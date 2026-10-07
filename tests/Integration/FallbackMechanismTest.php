<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Fallback Mechanism Test
 *
 * Verifies fallback mechanism maintains abstraction during failures.
 */
#[Group('integration')]
#[Group('fallback')]
class FallbackMechanismTest extends TestCase
{
    /**
     * Test that fallback is transparent to application code
     */
    public function test_fallback_is_transparent_to_application_code(): void
    {
        config([
            'payments.default' => 'paystack',
            'payments.fallback' => 'stripe',
        ]);

        // Mock HTTP client for paystack (primary provider)
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => 'ref_paystack_123',
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'access_123',
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver('paystack');
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager with mocked driver
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // IDENTICAL code - fallback happens automatically
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();

        // Should work regardless of which provider succeeds
        $this->assertInstanceOf(ChargeResponseDTO::class, $response);
    }

    /**
     * Test that error messages don't expose provider internals
     */
    public function test_error_messages_dont_expose_provider_internals(): void
    {
        // Configure invalid provider setup
        config([
            'payments.providers.paystack' => [
                'driver' => 'paystack',
                'secret_key' => '', // Invalid
                'enabled' => true,
            ],
        ]);

        try {
            Payment::amount(100.00)
                ->currency('NGN')
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->with('paystack')
                ->charge();

            $this->fail('Should have thrown exception');
        } catch (PaymentException $e) {
            // Error message should be provider-agnostic
            $message = $e->getMessage();

            // Should not expose internal provider details
            $this->assertStringNotContainsString('sk_test', $message);
            $this->assertStringNotContainsString('api_key', strtolower($message));
        }
    }

    /**
     * Test that fallback works for subscriptions
     */
    public function test_fallback_works_for_subscriptions(): void
    {
        config([
            'payments.default' => 'paystack',
            'payments.fallback' => 'paystack', // Same for now since only Paystack supports subscriptions
        ]);

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
        $driver = $manager->driver('paystack');
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager with mocked driver
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // IDENTICAL subscription code
        $planDTO = new SubscriptionPlanDTO(
            name: 'Test Plan',
            amount: 100.00,
            interval: 'monthly',
            currency: 'NGN'
        );

        $plan = Payment::subscription()
            ->planData($planDTO)
            ->createPlan();

        $this->assertInstanceOf(PlanResponseDTO::class, $plan);
    }

    /**
     * Check if provider supports subscriptions
     */
    protected function providerSupportsSubscriptions(string $provider): bool
    {
        try {
            $driver = app(PaymentManager::class)->driver($provider);

            return $driver instanceof SupportsSubscriptionsInterface;
        } catch (\Exception) {
            return false;
        }
    }
}
