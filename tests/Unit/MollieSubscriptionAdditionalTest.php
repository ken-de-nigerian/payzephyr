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
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeMollieSubscriptionDriver2(array $responses): MollieDriver
{
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

// ---------------------------------------------------------------------
// updatePlan
// ---------------------------------------------------------------------

test('mollie updatePlan re-encodes with the given overrides', function () {
    $driver = makeMollieSubscriptionDriver2([]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR'));
    $updated = $driver->updatePlan($plan->planCode, [
        'name' => 'Elite Plan',
        'amount' => 25.00,
        'interval' => 'annually',
        'currency' => 'USD',
    ]);

    expect($updated->name)->toBe('Elite Plan')
        ->and($updated->amount)->toBe(25.0)
        ->and($updated->interval)->toBe('annually')
        ->and($updated->currency)->toBe('USD')
        ->and($updated->planCode)->not->toBe($plan->planCode);
});

test('mollie updatePlan falls back to the existing values when no overrides are given', function () {
    $driver = makeMollieSubscriptionDriver2([]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR'));
    $updated = $driver->updatePlan($plan->planCode, []);

    expect($updated->name)->toBe('Pro Plan')
        ->and($updated->amount)->toBe(10.0)
        ->and($updated->interval)->toBe('monthly')
        ->and($updated->currency)->toBe('EUR');
});

// ---------------------------------------------------------------------
// createSubscription - error branch, interval mapping
// ---------------------------------------------------------------------

test('mollie createSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode(['_embedded' => ['customers' => []]])),
        new Response(200, [], json_encode(['id' => 'cst_1', 'email' => 'test@example.com'])),
        new Response(500, [], json_encode(['status' => 500, 'title' => 'Server Error'])),
    ]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Pro Plan', 10, 'monthly', 'EUR'));
    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: $plan->planCode, authorization: 'mdt_1');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Failed to create subscription');

test('mollie createSubscription maps daily, weekly and annually intervals when creating', function (string $interval) {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode(['_embedded' => ['customers' => []]])),
        new Response(200, [], json_encode(['id' => 'cst_1', 'email' => 'test@example.com'])),
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Plan',
            'mandateId' => 'mdt_1',
        ])),
    ]);

    $plan = $driver->createPlan(new SubscriptionPlanDTO('Plan', 10, $interval, 'EUR'));
    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: $plan->planCode, authorization: 'mdt_1');

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('cst_1:sub_1');
})->with(['daily', 'weekly', 'annually']);

// ---------------------------------------------------------------------
// fetchSubscription
// ---------------------------------------------------------------------

test('mollie fetchSubscription decodes the composite code and maps the response', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Pro Plan',
        ])),
    ]);

    $result = $driver->fetchSubscription('cst_1:sub_1');

    expect($result->subscriptionCode)->toBe('cst_1:sub_1')
        ->and($result->status)->toBe('active');
});

test('mollie fetchSubscription rejects a malformed subscription code', function () {
    $driver = makeMollieSubscriptionDriver2([]);

    $driver->fetchSubscription('sub_1');
})->throws(SubscriptionException::class, 'Invalid Mollie subscription code');

test('mollie fetchSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 500, 'title' => 'Server Error'])),
    ]);

    $driver->fetchSubscription('cst_1:sub_1');
})->throws(SubscriptionException::class, 'Failed to fetch subscription');

test('mollie fetchSubscription falls back to monthly when the interval string does not match the expected pattern', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => 'not-a-recognized-interval',
            'description' => 'Pro Plan',
        ])),
    ]);

    $result = $driver->fetchSubscription('cst_1:sub_1');
    $decodedPlan = $driver->fetchPlan($result->plan);

    expect($decodedPlan->interval)->toBe('monthly');
});

test('mollie fetchSubscription maps completed, pending and suspended statuses', function (string $status, string $expected) {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'sub_1',
            'status' => $status,
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Pro Plan',
        ])),
    ]);

    $result = $driver->fetchSubscription('cst_1:sub_1');

    expect($result->status)->toBe($expected);
})->with([
    ['completed', 'completed'],
    ['pending', 'attention'],
    ['suspended', 'attention'],
]);

// ---------------------------------------------------------------------
// cancelSubscription - error branch
// ---------------------------------------------------------------------

test('mollie cancelSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 500, 'title' => 'Server Error'])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('cst_1:sub_1'));
})->throws(SubscriptionException::class, 'Failed to cancel subscription');

// ---------------------------------------------------------------------
// listSubscriptions
// ---------------------------------------------------------------------

test('mollie listSubscriptions logs a warning when page greater than one is requested', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode([
            '_embedded' => ['customers' => [['id' => 'cst_1', 'email' => 'test@example.com']]],
        ])),
        new Response(200, [], json_encode(['_embedded' => ['subscriptions' => []]])),
    ]);

    $result = $driver->listSubscriptions(perPage: 10, page: 2, customer: 'test@example.com');

    expect($result['data'])->toBe([]);
});

test('mollie listSubscriptions returns an empty list when the customer is not found', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(200, [], json_encode(['_embedded' => ['customers' => []]])),
    ]);

    $result = $driver->listSubscriptions(customer: 'missing@example.com');

    expect($result)->toBe(['data' => [], 'has_more' => false]);
});

test('mollie listSubscriptions throws SubscriptionException on a network/API error', function () {
    $driver = makeMollieSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 500, 'title' => 'Server Error'])),
    ]);

    $driver->listSubscriptions(customer: 'test@example.com');
})->throws(SubscriptionException::class, 'Failed to list subscriptions');
