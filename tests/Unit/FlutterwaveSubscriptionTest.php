<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeFlutterwaveSubscriptionDriver(array $responses): FlutterwaveDriver
{
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('flutterwave createPlan creates a payment plan', function () {
    $driver = makeFlutterwaveSubscriptionDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'message' => 'Payment plan created',
            'data' => [
                'id' => 3807,
                'name' => 'Pro Plan',
                'amount' => 5000,
                'interval' => 'monthly',
                'duration' => 0,
                'status' => 'active',
                'currency' => 'NGN',
                'plan_token' => 'rpp_test',
            ],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 5000, 'monthly', 'NGN');
    $result = $driver->createPlan($plan);

    expect($result->planCode)->toBe('3807')
        ->and($result->name)->toBe('Pro Plan')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->interval)->toBe('monthly');
});

test('flutterwave createSubscription requires an authorization (card token)', function () {
    $driver = makeFlutterwaveSubscriptionDriver([]);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: '3807');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Flutterwave requires an existing card token');

test('flutterwave createSubscription charges the token then locates the resulting subscription', function () {
    $driver = makeFlutterwaveSubscriptionDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 'FLW_TXN_1', 'status' => 'successful'],
        ])),
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                [
                    'id' => 9911,
                    'amount' => 5000,
                    'customer' => ['email' => 'test@example.com', 'currency' => 'NGN'],
                    'plan' => 3807,
                    'status' => 'active',
                    'created_at' => '2026-01-01T00:00:00.000Z',
                ],
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: '3807',
        authorization: 'flw_token_abc'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('9911')
        ->and($result->status)->toBe('active')
        ->and($result->plan)->toBe('3807');
});

test('flutterwave cancelSubscription calls the cancel endpoint then re-fetches', function () {
    $driver = makeFlutterwaveSubscriptionDriver([
        new Response(200, [], json_encode(['status' => 'success', 'data' => []])),
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'id' => 9911,
                'amount' => 5000,
                'customer' => ['email' => 'test@example.com', 'currency' => 'NGN'],
                'plan' => 3807,
                'status' => 'cancelled',
            ],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('9911'));

    expect($result->status)->toBe('cancelled')
        ->and($result->isCancelled())->toBeTrue();
});

test('flutterwave enableSubscription calls the activate endpoint then re-fetches', function () {
    $driver = makeFlutterwaveSubscriptionDriver([
        new Response(200, [], json_encode(['status' => 'success', 'data' => []])),
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'id' => 9911,
                'amount' => 5000,
                'customer' => ['email' => 'test@example.com', 'currency' => 'NGN'],
                'plan' => 3807,
                'status' => 'active',
            ],
        ])),
    ]);

    $result = $driver->enableSubscription(new SubscriptionActionDTO('9911'));

    expect($result->status)->toBe('active')
        ->and($result->isActive())->toBeTrue();
});

test('flutterwave listSubscriptions filters by customer email client-side', function () {
    $driver = makeFlutterwaveSubscriptionDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                ['id' => 1, 'amount' => 5000, 'customer' => ['email' => 'a@example.com'], 'plan' => 3807, 'status' => 'active'],
                ['id' => 2, 'amount' => 5000, 'customer' => ['email' => 'b@example.com'], 'plan' => 3807, 'status' => 'active'],
            ],
            'meta' => ['page_info' => ['total' => 2]],
        ])),
    ]);

    $result = $driver->listSubscriptions(customer: 'b@example.com');

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->subscriptionCode)->toBe('2');
});
