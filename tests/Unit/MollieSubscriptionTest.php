<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeMollieSubscriptionDriver(array $responses): MollieDriver
{
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('mollie createPlan encodes the plan client-side without an API call', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR');
    $result = $driver->createPlan($plan);

    expect($result->name)->toBe('Pro Plan')
        ->and($result->amount)->toBe(10.0)
        ->and($result->interval)->toBe('monthly')
        ->and($result->currency)->toBe('EUR')
        ->and($result->planCode)->not->toBeEmpty();

    $fetched = $driver->fetchPlan($result->planCode);
    expect($fetched->name)->toBe('Pro Plan')
        ->and($fetched->amount)->toBe(10.0);
});

test('mollie fetchPlan rejects an invalid plan code', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $driver->fetchPlan('not-a-valid-plan-code');
})->throws(PlanException::class, 'Invalid Mollie plan code');

test('mollie listPlans throws because there is no server-side plan storage', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $driver->listPlans();
})->throws(PlanException::class, 'Mollie has no server-side plan storage');

test('mollie createSubscription requires an authorization (mandate id)', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR'));
    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: $plan->planCode);

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Mollie requires an existing mandate ID');

test('mollie createSubscription finds or creates a customer then creates the subscription', function () {
    $driver = makeMollieSubscriptionDriver([
        new Response(200, [], json_encode(['_embedded' => ['customers' => []]])),
        new Response(200, [], json_encode(['id' => 'cst_1', 'email' => 'test@example.com'])),
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Pro Plan',
            'mandateId' => 'mdt_1',
        ])),
    ]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR'));
    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: $plan->planCode,
        authorization: 'mdt_1'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('cst_1:sub_1')
        ->and($result->status)->toBe('active')
        ->and($result->amount)->toBe(10.0);
});

test('mollie cancelSubscription decodes the composite subscription code and calls DELETE', function () {
    $driver = makeMollieSubscriptionDriver([
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => 'canceled',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Pro Plan',
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('cst_1:sub_1'));

    expect($result->status)->toBe('cancelled')
        ->and($result->isCancelled())->toBeTrue();
});

test('mollie cancelSubscription rejects a malformed subscription code', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $driver->cancelSubscription(new SubscriptionActionDTO('sub_1'));
})->throws(SubscriptionException::class, 'Invalid Mollie subscription code');

test('mollie enableSubscription always throws - Mollie has no resume', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $driver->enableSubscription(new SubscriptionActionDTO('cst_1:sub_1'));
})->throws(SubscriptionException::class, 'cannot be re-enabled on Mollie');

test('mollie listSubscriptions requires a customer', function () {
    $driver = makeMollieSubscriptionDriver([]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'listed per-customer');

test('mollie listSubscriptions returns subscriptions for a found customer', function () {
    $driver = makeMollieSubscriptionDriver([
        new Response(200, [], json_encode([
            '_embedded' => ['customers' => [['id' => 'cst_1', 'email' => 'test@example.com']]],
        ])),
        new Response(200, [], json_encode([
            '_embedded' => ['subscriptions' => [
                [
                    'id' => 'sub_1',
                    'status' => 'active',
                    'amount' => ['currency' => 'EUR', 'value' => '10.00'],
                    'interval' => '1 month',
                    'description' => 'Pro Plan',
                ],
            ]],
        ])),
    ]);

    $result = $driver->listSubscriptions(customer: 'test@example.com');

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->subscriptionCode)->toBe('cst_1:sub_1');
});
