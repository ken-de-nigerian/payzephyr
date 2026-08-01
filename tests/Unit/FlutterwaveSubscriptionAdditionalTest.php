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
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeFlutterwaveSubscriptionDriver2(array $responses): FlutterwaveDriver
{
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

// ---------------------------------------------------------------------
// createPlan
// ---------------------------------------------------------------------

test('flutterwave createPlan throws PlanException when the API reports failure', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'Invalid amount'])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 5000, 'monthly', 'NGN');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Invalid amount');

test('flutterwave createPlan throws PlanException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 5000, 'monthly', 'NGN');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Failed to create plan');

test('flutterwave createPlan maps annually interval to yearly', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 3807, 'name' => 'Plan', 'amount' => 5000, 'interval' => 'yearly', 'currency' => 'NGN'],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Plan', 5000, 'annually', 'NGN');
    $result = $driver->createPlan($plan);

    expect($result->interval)->toBe('annually');
});

// ---------------------------------------------------------------------
// updatePlan
// ---------------------------------------------------------------------

test('flutterwave updatePlan sends name/status changes then re-fetches the plan', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'success', 'data' => []])),
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 3807, 'name' => 'New Name', 'amount' => 5000, 'interval' => 'monthly', 'currency' => 'NGN'],
        ])),
    ]);

    $result = $driver->updatePlan('3807', ['name' => 'New Name', 'status' => 'active']);

    expect($result->name)->toBe('New Name');
});

test('flutterwave updatePlan skips the PUT call when there is nothing to change', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 3807, 'name' => 'Plan', 'amount' => 5000, 'interval' => 'monthly', 'currency' => 'NGN'],
        ])),
    ]);

    $result = $driver->updatePlan('3807', []);

    expect($result->planCode)->toBe('3807');
});

test('flutterwave updatePlan throws PlanException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->updatePlan('3807', ['name' => 'New Name']);
})->throws(PlanException::class, 'Failed to update plan');

// ---------------------------------------------------------------------
// fetchPlan
// ---------------------------------------------------------------------

test('flutterwave fetchPlan retrieves and maps a plan', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 3807, 'name' => 'Plan', 'amount' => 5000, 'interval' => 'monthly', 'currency' => 'NGN'],
        ])),
    ]);

    $result = $driver->fetchPlan('3807');

    expect($result->planCode)->toBe('3807')
        ->and($result->name)->toBe('Plan');
});

test('flutterwave fetchPlan throws PlanException when the API reports failure', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'plan not found'])),
    ]);

    $driver->fetchPlan('3807-missing');
})->throws(PlanException::class, 'plan not found');

test('flutterwave fetchPlan throws PlanException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->fetchPlan('3807');
})->throws(PlanException::class, 'Failed to get plan');

test('flutterwave fetchPlan maps yearly and quarterly intervals', function (string $flwInterval, string $expected) {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => ['id' => 3807, 'name' => 'Plan', 'amount' => 5000, 'interval' => $flwInterval, 'currency' => 'NGN'],
        ])),
    ]);

    $result = $driver->fetchPlan('3807');

    expect($result->interval)->toBe($expected);
})->with([
    ['yearly', 'annually'],
    ['quarterly', 'monthly'],
]);

// ---------------------------------------------------------------------
// listPlans
// ---------------------------------------------------------------------

test('flutterwave listPlans returns a mapped list of plans', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                ['id' => 3807, 'name' => 'Plan', 'amount' => 5000, 'interval' => 'monthly', 'currency' => 'NGN'],
            ],
            'meta' => ['page_info' => ['total' => 1]],
        ])),
    ]);

    $result = $driver->listPlans(10, 1);

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->planCode)->toBe('3807');
});

test('flutterwave listPlans throws PlanException when the API reports failure', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'cannot list'])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class, 'cannot list');

test('flutterwave listPlans throws PlanException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class, 'Failed to list plans');

// ---------------------------------------------------------------------
// createSubscription
// ---------------------------------------------------------------------

test('flutterwave createSubscription throws SubscriptionException when the resulting subscription cannot be located', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'success', 'data' => ['id' => 'FLW_TXN_1']])),
        new Response(200, [], json_encode(['status' => 'success', 'data' => []])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: '3807',
        authorization: 'flw_token_abc'
    );

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'could not be located');

test('flutterwave createSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: '3807',
        authorization: 'flw_token_abc'
    );

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Failed to create subscription');

// ---------------------------------------------------------------------
// fetchSubscription
// ---------------------------------------------------------------------

test('flutterwave fetchSubscription retrieves and maps a subscription', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
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

    $result = $driver->fetchSubscription('9911');

    expect($result->subscriptionCode)->toBe('9911')
        ->and($result->status)->toBe('active');
});

test('flutterwave fetchSubscription throws SubscriptionException when the API reports failure', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'subscription not found'])),
    ]);

    $driver->fetchSubscription('9911-missing');
})->throws(SubscriptionException::class, 'subscription not found');

test('flutterwave fetchSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->fetchSubscription('9911');
})->throws(SubscriptionException::class, 'Failed to fetch subscription');

// ---------------------------------------------------------------------
// cancelSubscription / enableSubscription - error branches
// ---------------------------------------------------------------------

test('flutterwave cancelSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('9911'));
})->throws(SubscriptionException::class, 'Failed to cancel subscription');

test('flutterwave enableSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('9911'));
})->throws(SubscriptionException::class, 'Failed to enable subscription');

// ---------------------------------------------------------------------
// listSubscriptions
// ---------------------------------------------------------------------

test('flutterwave listSubscriptions throws SubscriptionException when the API reports failure', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'cannot list'])),
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'cannot list');

test('flutterwave listSubscriptions throws SubscriptionException on a network/API error', function () {
    $driver = makeFlutterwaveSubscriptionDriver2([
        new Response(500, [], json_encode(['status' => 'error', 'message' => 'server error'])),
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'Failed to list subscriptions');
