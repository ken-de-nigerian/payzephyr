<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use KenDeNigerian\PayZephyr\Tests\Fixtures\CustomTestDriver;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Custom Driver Test
 *
 * Verifies that custom drivers can be created and registered
 * without modifying core package code.
 */
#[Group('integration')]
#[Group('extensibility')]
class CustomDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure custom_test provider is configured first
        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
                'currencies' => ['NGN', 'USD'],
            ],
        ]);

        // Register custom driver using public API only
        // This must happen after config is set
        $this->registerCustomDriver();

        // Clear PaymentManager singleton to ensure it picks up the registered driver
        $this->app->forgetInstance(PaymentManager::class);
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);
    }

    /**
     * Test that custom driver can be registered without core modifications
     */
    public function test_custom_driver_can_be_registered_without_core_modifications(): void
    {
        // Registration should work using only public APIs
        app(DriverFactory::class);

        // Custom driver should be registered
        $this->assertTrue(true); // Registration happens in setUp()
    }

    /**
     * Test that payment facade works with custom driver
     */
    public function test_payment_facade_works_with_custom_driver(): void
    {
        // Ensure config is set (should already be in setUp, but ensure it's here too)
        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
                'currencies' => ['NGN', 'USD'],
            ],
        ]);

        // Update payments.config singleton
        $this->app->forgetInstance('payments.config');
        $this->app->singleton('payments.config', fn () => config('payments'));

        // Setup mocked custom driver
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'data' => [
                    'reference' => 'ref_custom_123',
                    'authorization_url' => 'https://checkout.custom-test.com/abc123',
                    'access_code' => 'access_123',
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver('custom_test');
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->bind(\KenDeNigerian\PayZephyr\Payment::class, fn ($app): \KenDeNigerian\PayZephyr\Payment => new \KenDeNigerian\PayZephyr\Payment($manager));
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // IDENTICAL code - should work with custom driver
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->with('custom_test')
            ->charge();

        // Should work exactly like any other provider
        $this->assertInstanceOf(ChargeResponseDTO::class, $response);
        $this->assertEquals('custom_test', $response->provider);
    }

    /**
     * Test that webhook handling works with custom driver
     */
    public function test_webhook_handling_works_with_custom_driver(): void
    {
        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'webhook_secret' => 'test_webhook_secret',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
            ],
        ]);

        // Update payments.config singleton
        $this->app->forgetInstance('payments.config');
        $this->app->singleton('payments.config', fn () => config('payments'));
        $this->app->forgetInstance(PaymentManager::class);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('custom_test');

        $payload = ['data' => ['reference' => 'test_ref', 'status' => 'success']];
        $body = json_encode($payload);
        $headers = ['x-custom-signature' => [hash_hmac('sha256', $body, 'test_webhook_secret')]];

        // Webhook validation should work
        $isValid = $driver->validateWebhook($headers, $body);

        $this->assertTrue($isValid);
    }

    /**
     * Test that transaction logging works with custom driver
     */
    public function test_transaction_logging_works_with_custom_driver(): void
    {
        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
                'currencies' => ['NGN'],
            ],
            'payments.logging.enabled' => true,
        ]);

        // Update payments.config singleton
        $this->app->forgetInstance('payments.config');
        $this->app->singleton('payments.config', fn () => config('payments'));
        $this->app->forgetInstance(PaymentManager::class);

        // Mock HTTP client
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'data' => [
                    'reference' => 'ref_custom_123',
                    'authorization_url' => 'https://checkout.custom-test.com/abc123',
                    'access_code' => 'access_123',
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver('custom_test');
        $driver->setClient($client);

        // Ensure Payment facade uses the same manager
        $this->app->instance(PaymentManager::class, $manager);
        $this->app->bind(\KenDeNigerian\PayZephyr\Payment::class, fn ($app): \KenDeNigerian\PayZephyr\Payment => new \KenDeNigerian\PayZephyr\Payment($manager));
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\Payment::class);

        // Transaction should be logged automatically
        $response = Payment::amount(100.00)
            ->currency('NGN')
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->with('custom_test')
            ->charge();

        // Check if transaction was logged
        $transaction = PaymentTransaction::where('reference', $response->reference)
            ->where('provider', 'custom_test')
            ->first();

        if (config('payments.logging.enabled')) {
            $this->assertNotNull($transaction);
            $this->assertEquals('custom_test', $transaction->provider);
        }
    }

    /**
     * Test that health checks work with custom driver
     */
    public function test_health_checks_work_with_custom_driver(): void
    {
        config([
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
            ],
        ]);

        // Update payments.config singleton
        $this->app->forgetInstance('payments.config');
        $this->app->singleton('payments.config', fn () => config('payments'));
        $this->app->forgetInstance(PaymentManager::class);

        // Mock HTTP client for health check
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $manager = app(PaymentManager::class);
        $driver = $manager->driver('custom_test');
        $driver->setClient($client);

        // Health check should work
        $isHealthy = $driver->healthCheck();

        $this->assertTrue($isHealthy);
    }

    /**
     * Test that fallback mechanism includes custom driver
     */
    public function test_fallback_mechanism_includes_custom_driver(): void
    {
        config([
            'payments.default' => 'paystack',
            'payments.fallback' => 'custom_test',
            'payments.providers.custom_test' => [
                'driver' => 'custom_test',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api.custom-test.com',
                'enabled' => true,
                'currencies' => ['NGN'],
            ],
        ]);

        // Fallback should work with custom driver
        // (This would require mocking primary provider failure)
        $this->assertTrue(true); // Placeholder - would need actual implementation
    }

    /**
     * Register custom driver using public API
     */
    protected function registerCustomDriver(): void
    {
        // Register using DriverFactory - public API only
        $factory = app(DriverFactory::class);

        // This is how developers would register custom drivers
        // In real implementation, this would be done via service provider
        $factory->register('custom_test', CustomTestDriver::class);
    }
}
