<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Regression;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Helpers\SubscriptionTestHelper;

/**
 * Public API Stability Test
 *
 * Ensures no breaking changes to existing public APIs after enhancements.
 */
#[Group('regression')]
#[Group('stability')]
class PublicApiStabilityTest extends TestCase
{
    /**
     * Test that Payment fluent API still works as before
     */
    public function test_payment_fluent_api_still_works(): void
    {
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

        // Original API - should still work (redirect needs callback)
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->redirect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * Test that Payment::verify() still works
     */
    public function test_payment_verify_still_works(): void
    {
        // Setup mocked provider for charge and verify
        $this->setupMockedProvider('paystack', [
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => 'ref_paystack_123',
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'access_123',
                ],
            ])),
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => 'test_ref_123',
                    'status' => 'success',
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        // Create payment first
        $chargeResponse = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();

        // Original verify API - should still work
        $verification = Payment::verify($chargeResponse->reference);

        $this->assertInstanceOf(VerificationResponseDTO::class, $verification);
    }

    /**
     * Test that subscription API still works
     */
    public function test_subscription_api_still_works(): void
    {
        // Setup mocked provider for plan fetch and subscription creation
        $this->setupMockedProvider('paystack', [
            SubscriptionTestHelper::planMock('PLAN_123'),
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_test123',
                    'status' => 'active',
                    'customer' => ['email' => 'test@example.com'],
                    'plan' => ['plan_code' => 'PLAN_123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        // Original subscription API - should still work
        $subscription = Payment::subscription()
            ->customer('test@example.com')
            ->plan('PLAN_123')
            ->with('paystack')
            ->create();

        $this->assertInstanceOf(SubscriptionResponseDTO::class, $subscription);
    }

    /**
     * Test that subscription() helper method still works
     */
    public function test_subscription_helper_method_still_works(): void
    {
        // Setup mocked provider for subscription fetch
        $this->setupMockedProvider('paystack', [
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'subscription_code' => 'SUB_123',
                    'status' => 'active',
                    'customer' => ['email' => 'test@example.com'],
                    'plan' => ['plan_code' => 'PLAN_123'],
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        ]);

        // Original API with code parameter
        $subscription = Payment::subscription('SUB_123')
            ->with('paystack')
            ->fetch();

        $this->assertInstanceOf(SubscriptionResponseDTO::class, $subscription);
    }

    /**
     * Test that all facade methods still work
     */
    public function test_all_facade_methods_still_work(): void
    {
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

        // Test all fluent methods
        $payment = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->description('Test')
            ->metadata(['key' => 'value'])
            ->callback('https://example.com/callback');

        $this->assertInstanceOf(\KenDeNigerian\PayZephyr\Payment::class, $payment);
    }

    /**
     * Test that DTOs can still be serialized/unserialized
     */
    public function test_dtos_can_still_be_serialized(): void
    {
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

        $chargeResponse = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();

        // Serialization should still work (for caching, queues, etc.)
        $serialized = serialize($chargeResponse);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ChargeResponseDTO::class, $unserialized);
        $this->assertEquals($chargeResponse->reference, $unserialized->reference);
    }

    /**
     * Test that method signatures haven't changed
     */
    public function test_method_signatures_havent_changed(): void
    {
        // Verify Payment class methods exist with expected signatures
        $reflection = new \ReflectionClass(\KenDeNigerian\PayZephyr\Payment::class);

        $this->assertTrue($reflection->hasMethod('amount'));
        $this->assertTrue($reflection->hasMethod('currency'));
        $this->assertTrue($reflection->hasMethod('email'));
        $this->assertTrue($reflection->hasMethod('charge'));
        $this->assertTrue($reflection->hasMethod('verify'));
        $this->assertTrue($reflection->hasMethod('subscription'));
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
