<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Tests\Fixtures\CustomTestDriver;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unified Refund Abstraction Verification Test
 *
 * Verifies that refund operations work uniformly across providers,
 * mirroring UnifiedSubscriptionAbstractionTest's approach.
 */
#[Group('integration')]
#[Group('abstraction')]
#[Group('refunds')]
class UnifiedRefundAbstractionTest extends TestCase
{
    /**
     * @return array<array<string>>
     */
    public static function refundProviders(): array
    {
        return [
            ['paystack'],
            // Every other provider (Stripe, PayPal, Flutterwave, Square,
            // Mollie, Monnify, OPay) also implements SupportsRefundsInterface
            // - see their dedicated *RefundTest.php files - not added here
            // for the same reason UnifiedSubscriptionAbstractionTest only
            // lists Paystack: every mocked response in this file is shaped
            // for Paystack's API.
        ];
    }

    #[DataProvider('refundProviders')]
    public function test_refund_creation_works_identically(string $provider): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => ['id' => 555, 'status' => 'pending', 'amount' => 500000, 'currency' => 'NGN'],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        $refund = Payment::refund('txn_ref_123')
            ->with($provider)
            ->refund();

        $this->assertInstanceOf(RefundResponseDTO::class, $refund);
        $this->assertNotEmpty($refund->refundReference);
        $this->assertEquals('txn_ref_123', $refund->transactionReference);
        $this->assertEquals($provider, $refund->provider);
        $this->assertIsFloat($refund->amount);
    }

    #[DataProvider('refundProviders')]
    public function test_refund_retrieval_works_identically(string $provider): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'id' => 555,
                    'status' => 'processed',
                    'amount' => 500000,
                    'currency' => 'NGN',
                    'transaction' => ['reference' => 'txn_ref_123'],
                ],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        $refund = Payment::refund()
            ->with($provider)
            ->fetch('555');

        $this->assertInstanceOf(RefundResponseDTO::class, $refund);
        $this->assertEquals('555', $refund->refundReference);
        $this->assertEquals($provider, $refund->provider);
    }

    #[DataProvider('refundProviders')]
    public function test_refund_response_dtos_have_consistent_structure(string $provider): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => ['id' => 555, 'status' => 'pending', 'amount' => 500000, 'currency' => 'NGN'],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver($provider);
        $driver->setClient($client);

        $this->app->instance(PaymentManager::class, $manager);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        $refund = Payment::refund('txn_ref_123')->with($provider)->refund();

        $this->assertObjectHasProperty('refundReference', $refund);
        $this->assertObjectHasProperty('transactionReference', $refund);
        $this->assertObjectHasProperty('status', $refund);
        $this->assertObjectHasProperty('amount', $refund);
        $this->assertObjectHasProperty('currency', $refund);
        $this->assertObjectHasProperty('provider', $refund);

        $serialized = serialize($refund);
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(RefundResponseDTO::class, $unserialized);
    }

    public function test_a_provider_without_refund_support_fails_gracefully(): void
    {
        // Every real provider driver now implements SupportsRefundsInterface,
        // so a custom third-party driver (which doesn't) is what exercises
        // the graceful-failure path here, mirroring how
        // UnifiedSubscriptionAbstractionTest uses Monnify/OPay for the same
        // purpose against SupportsSubscriptionsInterface.
        $this->app->forgetInstance('payments.config');

        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
                'currencies' => ['NGN'],
            ],
        ]);

        app(\KenDeNigerian\PayZephyr\Services\DriverFactory::class)->register('custom_test', CustomTestDriver::class);

        $this->app->forgetInstance(PaymentManager::class);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('does not support refunds');

        Payment::refund('txn_ref_123')->with('custom_test')->refund();
    }
}
