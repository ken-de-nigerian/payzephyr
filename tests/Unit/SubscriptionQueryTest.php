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
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\SubscriptionQuery;

interface CombinedSubscriptionDriver extends DriverInterface, SupportsSubscriptionsInterface {}

function makeQueryManager(string $provider, DriverInterface $driver): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$provider => $driver]);

    return $manager;
}

test('get() works against Paystack raw-array results with no filters', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_1', 'status' => 'active', 'customer' => ['email' => 'a@b.com'], 'plan' => ['plan_code' => 'PLN_1'], 'amount' => 100000],
            ],
        ])),
    ]))]));

    $query = new SubscriptionQuery(makeQueryManager('paystack', $driver));
    $result = $query->from('paystack')->get();

    expect($result)->toHaveCount(1);
});

test('regression: whereStatus/active/forPlan filters no longer crash on DTO-shaped results from Stripe/PayPal/Flutterwave/Square/Mollie', function () {
    $active = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'active',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );
    $cancelled = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_2',
        status: 'cancelled',
        customer: 'c@d.com',
        plan: 'PLN_2',
        amount: 20.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [$active, $cancelled], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));

    $activeOnly = $query->from('stripe')->active()->get();
    expect($activeOnly['data'])->toHaveCount(1)
        ->and($activeOnly['data'][0]->subscriptionCode)->toBe('SUB_1');

    $query2 = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    $cancelledOnly = $query2->from('stripe')->cancelled()->get();
    expect($cancelledOnly['data'])->toHaveCount(1)
        ->and($cancelledOnly['data'][0]->subscriptionCode)->toBe('SUB_2');

    $query3 = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    $byPlan = $query3->from('stripe')->forPlan('PLN_2')->get();
    expect($byPlan['data'])->toHaveCount(1)
        ->and($byPlan['data'][0]->subscriptionCode)->toBe('SUB_2');
});

test('createdAfter/createdBefore are a safe no-op against DTO-shaped results rather than crashing', function () {
    $dto = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'active',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [$dto], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    $result = $query->from('stripe')->createdAfter('2020-01-01')->createdBefore('2030-01-01')->get();

    expect($result['data'])->toHaveCount(1);
});

test('first() returns the first matching subscription as a DTO', function () {
    $dto = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'active',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [$dto], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    $first = $query->from('stripe')->first();

    expect($first)->toBeInstanceOf(SubscriptionResponseDTO::class)
        ->and($first->subscriptionCode)->toBe('SUB_1');
});

test('first() returns null when there are no results', function () {
    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));

    expect($query->from('stripe')->first())->toBeNull();
});

test('count() and exists() reflect the number of matching subscriptions', function () {
    $dto = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'active',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [$dto], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    expect($query->from('stripe')->count())->toBe(1);

    $query2 = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    expect($query2->from('stripe')->exists())->toBeTrue();
});

test('exists() is false when nothing matches', function () {
    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')->andReturn(['data' => [], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));

    expect($query->from('stripe')->exists())->toBeFalse();
});

test('get() throws when the target provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('getName')->andReturn('monnify');

    $query = new SubscriptionQuery(makeQueryManager('monnify', $driver));

    $query->from('monnify')->get();
})->throws(PaymentException::class, 'does not support subscriptions');

test('forCustomer, take, and page are forwarded to listSubscriptions', function () {
    $driver = Mockery::mock(CombinedSubscriptionDriver::class);
    $driver->shouldReceive('listSubscriptions')
        ->with(5, 2, 'customer@example.com')
        ->andReturn(['data' => [], 'has_more' => false]);

    $query = new SubscriptionQuery(makeQueryManager('stripe', $driver));
    $query->from('stripe')->forCustomer('customer@example.com')->take(5)->page(2)->get();
})->throwsNoExceptions();
