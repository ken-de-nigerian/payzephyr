<?php

namespace Tests\Helpers;

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Subscription;
use ReflectionClass;

class SubscriptionTestHelper
{
    /**
     * Create a plan fetch mock response for validation
     */
    public static function planMock(string $planCode = 'PLN_123', array $overrides = []): Response
    {
        $data = array_merge([
            'plan_code' => $planCode,
            'name' => 'Test Plan',
            'amount' => 500000,
            'interval' => 'monthly',
            'currency' => 'NGN',
            'status' => 'active',
        ], $overrides);

        return new Response(200, [], json_encode([
            'status' => true,
            'data' => $data,
        ]));
    }

    /**
     * Create a subscription fetch mock response
     */
    public static function subscriptionMock(string $subscriptionCode = 'SUB_123', array $overrides = []): Response
    {
        $data = array_merge([
            'subscription_code' => $subscriptionCode,
            'status' => 'active',
            'customer' => ['email' => 'test@example.com'],
            'plan' => ['name' => 'Test Plan'],
            'amount' => 500000,
            'currency' => 'NGN',
        ], $overrides);

        return new Response(200, [], json_encode([
            'status' => true,
            'data' => $data,
        ]));
    }

    public static function createWithMock(array $responses): Subscription
    {
        $driver = PaystackDriverTestHelper::createWithMock($responses);

        // Create a real PaymentManager and inject the driver using reflection
        $manager = new PaymentManager;

        // Update manager config to ensure paystack is enabled
        $reflection = new ReflectionClass($manager);
        $configProperty = $reflection->getProperty('config');
        $config = $configProperty->getValue($manager);
        $config['providers']['paystack']['enabled'] = true;
        $config['default'] = 'paystack';
        $config['subscriptions']['validation']['enabled'] = config('payments.subscriptions.validation.enabled', true);
        $config['subscriptions']['prevent_duplicates'] = false;
        $configProperty->setValue($manager, $config);

        // CRITICAL: Inject the driver BEFORE any calls to driver() method
        // This ensures the manager uses our mocked driver, not creating a new one
        $driversProperty = $reflection->getProperty('drivers');
        $drivers = $driversProperty->getValue($manager);
        $drivers['paystack'] = $driver;
        $driversProperty->setValue($manager, $drivers);

        // CRITICAL: Verify the driver's HTTP client is set correctly
        // Use reflection to check the client property
        $driverReflection = new ReflectionClass($driver);
        $clientProperty = $driverReflection->getProperty('client');
        $client = $clientProperty->getValue($driver);

        // Ensure the client is using our MockHandler
        if (! $client) {
            throw new \RuntimeException('Driver HTTP client is not set');
        }

        // Set default driver in config
        config(['payments.default' => 'paystack']);
        config(['payments.providers.paystack.enabled' => true]);
        config(['payments.subscriptions.validation.enabled' => true]);
        config(['payments.subscriptions.prevent_duplicates' => false]);

        return new Subscription($manager);
    }
}
