<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\SubscriptionQuery;

interface CombinedSubscriptionDriverForGaps extends DriverInterface, SupportsSubscriptionsInterface {}

function makeGapsQueryManager(string $provider, DriverInterface $driver): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$provider => $driver]);

    return $manager;
}

test('first() converts a raw provider array (Paystack-shaped) into a SubscriptionResponseDTO', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_1', 'status' => 'active', 'customer' => 'a@b.com', 'plan' => 'PLN_1', 'amount' => 100000],
            ],
        ])),
    ]))]));

    $query = new SubscriptionQuery(makeGapsQueryManager('paystack', $driver));
    $first = $query->from('paystack')->first();

    expect($first)->toBeInstanceOf(SubscriptionResponseDTO::class)
        ->and($first->subscriptionCode)->toBe('SUB_1')
        ->and($first->plan)->toBe('PLN_1');
});

test('createdAfter filters out array-shaped subscriptions created before the cutoff', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_OLD', 'status' => 'active', 'plan' => ['plan_code' => 'PLN_1'], 'created_at' => '2020-01-01T00:00:00.000Z'],
                ['subscription_code' => 'SUB_NEW', 'status' => 'active', 'plan' => ['plan_code' => 'PLN_1'], 'created_at' => '2025-01-01T00:00:00.000Z'],
            ],
        ])),
    ]))]));

    $query = new SubscriptionQuery(makeGapsQueryManager('paystack', $driver));
    $result = $query->from('paystack')->createdAfter('2024-01-01')->get();

    expect($result)->toHaveCount(1)
        ->and($result[0]['subscription_code'])->toBe('SUB_NEW');
});

test('createdBefore filters out array-shaped subscriptions created after the cutoff', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_OLD', 'status' => 'active', 'plan' => ['plan_code' => 'PLN_1'], 'created_at' => '2020-01-01T00:00:00.000Z'],
                ['subscription_code' => 'SUB_NEW', 'status' => 'active', 'plan' => ['plan_code' => 'PLN_1'], 'created_at' => '2025-01-01T00:00:00.000Z'],
            ],
        ])),
    ]))]));

    $query = new SubscriptionQuery(makeGapsQueryManager('paystack', $driver));
    $result = $query->from('paystack')->createdBefore('2024-01-01')->get();

    expect($result)->toHaveCount(1)
        ->and($result[0]['subscription_code'])->toBe('SUB_OLD');
});

test('getProviderName falls back to the manager default driver when from() is never called', function () {
    $driver = Mockery::mock(CombinedSubscriptionDriverForGaps::class);
    $driver->shouldReceive('listSubscriptions')->andReturn([]);

    config(['payments.default' => 'stripe']);
    app()->forgetInstance('payments.config');

    $manager = makeGapsQueryManager('stripe', $driver);
    $query = new SubscriptionQuery($manager);

    $result = $query->get();

    expect($result)->toBe([]);
});
