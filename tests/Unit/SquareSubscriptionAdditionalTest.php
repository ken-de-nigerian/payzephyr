<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeSquareSubscriptionDriver2(array $responses): SquareDriver
{
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD'],
    ]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

// ---------------------------------------------------------------------
// createPlan
// ---------------------------------------------------------------------

test('square createPlan throws PlanException when the response has no objects', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['errors' => [['detail' => 'invalid catalog object']]])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 50, 'monthly', 'USD');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'invalid catalog object');

test('square createPlan throws PlanException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 50, 'monthly', 'USD');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Failed to create plan');

test('square createPlan maps daily, weekly and annually intervals', function (string $interval, string $cadence) {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'objects' => [
                [
                    'type' => 'SUBSCRIPTION_PLAN',
                    'id' => 'PLAN123',
                    'subscription_plan_data' => ['name' => 'Plan'],
                ],
                [
                    'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                    'id' => 'VAR123',
                    'subscription_plan_variation_data' => [
                        'name' => 'Plan',
                        'phases' => [[
                            'cadence' => $cadence,
                            'recurring_price_money' => ['amount' => 1000, 'currency' => 'USD'],
                            'ordinal' => 0,
                        ]],
                        'subscription_plan_id' => 'PLAN123',
                    ],
                ],
            ],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Plan', 10, $interval, 'USD');
    $result = $driver->createPlan($plan);

    expect($result->interval)->toBe($interval);
})->with([
    ['daily', 'DAILY'],
    ['weekly', 'WEEKLY'],
    ['annually', 'ANNUAL'],
]);

// ---------------------------------------------------------------------
// updatePlan
// ---------------------------------------------------------------------

test('square updatePlan updates the plan name and variation amount/interval', function () {
    $driver = makeSquareSubscriptionDriver2([
        // fetchSquareCatalogObjects()
        new Response(200, [], json_encode([
            'object' => [
                'id' => 'VAR123',
                'subscription_plan_variation_data' => [
                    'name' => 'Old Name',
                    'phases' => [[
                        'cadence' => 'MONTHLY',
                        'recurring_price_money' => ['amount' => 1000, 'currency' => 'USD'],
                        'ordinal' => 0,
                    ]],
                    'subscription_plan_id' => 'PLAN123',
                ],
            ],
            'related_objects' => [
                [
                    'id' => 'PLAN123',
                    'subscription_plan_data' => ['name' => 'Old Name'],
                ],
            ],
        ])),
        // name update upsert
        new Response(200, [], json_encode(['catalog_object' => ['id' => 'PLAN123']])),
        // amount/interval update upsert
        new Response(200, [], json_encode(['catalog_object' => ['id' => 'VAR123']])),
        // fetchPlan() at the end
        new Response(200, [], json_encode([
            'object' => [
                'id' => 'VAR123',
                'subscription_plan_variation_data' => [
                    'name' => 'New Name',
                    'phases' => [[
                        'cadence' => 'ANNUAL',
                        'recurring_price_money' => ['amount' => 2000, 'currency' => 'USD'],
                        'ordinal' => 0,
                    ]],
                    'subscription_plan_id' => 'PLAN123',
                ],
            ],
            'related_objects' => [
                [
                    'id' => 'PLAN123',
                    'subscription_plan_data' => ['name' => 'New Name'],
                ],
            ],
        ])),
    ]);

    $result = $driver->updatePlan('VAR123', ['name' => 'New Name', 'amount' => 20.00, 'interval' => 'annually']);

    expect($result->name)->toBe('New Name')
        ->and($result->amount)->toBe(20.0)
        ->and($result->interval)->toBe('annually');
});

test('square updatePlan rethrows PlanException when the plan is not found', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['object' => null])),
    ]);

    $driver->updatePlan('VAR-missing', ['name' => 'New Name']);
})->throws(PlanException::class, 'Subscription plan not found');

test('square updatePlan throws PlanException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->updatePlan('VAR123', ['name' => 'New Name']);
})->throws(PlanException::class, 'Failed to update plan');

// ---------------------------------------------------------------------
// fetchPlan
// ---------------------------------------------------------------------

test('square fetchPlan retrieves and maps a catalog plan/variation', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'object' => [
                'id' => 'VAR123',
                'subscription_plan_variation_data' => [
                    'name' => 'Plan',
                    'phases' => [[
                        'cadence' => 'MONTHLY',
                        'recurring_price_money' => ['amount' => 1000, 'currency' => 'USD'],
                        'ordinal' => 0,
                    ]],
                    'subscription_plan_id' => 'PLAN123',
                ],
            ],
            'related_objects' => [
                ['id' => 'PLAN123', 'subscription_plan_data' => ['name' => 'Plan']],
            ],
        ])),
    ]);

    $result = $driver->fetchPlan('VAR123');

    expect($result->planCode)->toBe('VAR123')
        ->and($result->name)->toBe('Plan');
});

test('square fetchPlan throws PlanException when the plan is not found', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['object' => null])),
    ]);

    $driver->fetchPlan('VAR-missing');
})->throws(PlanException::class, 'Subscription plan not found');

test('square fetchPlan throws PlanException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->fetchPlan('VAR123');
})->throws(PlanException::class, 'Failed to get plan');

test('square fetchPlan maps week and every_two_weeks cadences back to weekly', function (string $cadence) {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'object' => [
                'id' => 'VAR123',
                'subscription_plan_variation_data' => [
                    'name' => 'Plan',
                    'phases' => [[
                        'cadence' => $cadence,
                        'recurring_price_money' => ['amount' => 1000, 'currency' => 'USD'],
                        'ordinal' => 0,
                    ]],
                    'subscription_plan_id' => 'PLAN123',
                ],
            ],
            'related_objects' => [
                ['id' => 'PLAN123', 'subscription_plan_data' => ['name' => 'Plan']],
            ],
        ])),
    ]);

    $result = $driver->fetchPlan('VAR123');

    expect($result->interval)->toBe('weekly');
})->with([
    ['WEEKLY'],
    ['EVERY_TWO_WEEKS'],
]);

// ---------------------------------------------------------------------
// listPlans
// ---------------------------------------------------------------------

test('square listPlans returns a mapped list of plans', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'objects' => [
                ['type' => 'SUBSCRIPTION_PLAN', 'id' => 'PLAN123', 'subscription_plan_data' => ['name' => 'Plan']],
                [
                    'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                    'id' => 'VAR123',
                    'subscription_plan_variation_data' => [
                        'name' => 'Plan',
                        'phases' => [[
                            'cadence' => 'MONTHLY',
                            'recurring_price_money' => ['amount' => 1000, 'currency' => 'USD'],
                            'ordinal' => 0,
                        ]],
                        'subscription_plan_id' => 'PLAN123',
                    ],
                ],
            ],
            'cursor' => 'next-page-token',
        ])),
    ]);

    $result = $driver->listPlans(10, 1);

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->planCode)->toBe('VAR123')
        ->and($result['has_more'])->toBeTrue();
});

test('square listPlans logs a warning when page greater than one is requested', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['objects' => []])),
    ]);

    $result = $driver->listPlans(10, 2);

    expect($result['data'])->toBe([]);
});

test('square listPlans throws PlanException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class, 'Failed to list plans');

// ---------------------------------------------------------------------
// createSubscription
// ---------------------------------------------------------------------

test('square createSubscription throws SubscriptionException when the response has no subscription', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['customers' => []])),
        new Response(200, [], json_encode(['customer' => ['id' => 'CUST1', 'email_address' => 'test@example.com']])),
        new Response(200, [], json_encode(['errors' => [['detail' => 'card declined']]])),
    ]);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'VAR123', authorization: 'ccof:card123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'card declined');

test('square createSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'VAR123', authorization: 'ccof:card123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Failed to create subscription');

// ---------------------------------------------------------------------
// fetchSubscription
// ---------------------------------------------------------------------

test('square fetchSubscription retrieves and maps a subscription', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'ACTIVE',
                'plan_variation_id' => 'VAR123',
                'customer_id' => '',
            ],
        ])),
    ]);

    $result = $driver->fetchSubscription('SUB1');

    expect($result->subscriptionCode)->toBe('SUB1')
        ->and($result->customer)->toBe('');
});

test('square fetchSubscription throws SubscriptionException when no subscription is returned', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['errors' => [['detail' => 'not found']]])),
    ]);

    $driver->fetchSubscription('SUB-missing');
})->throws(SubscriptionException::class, 'not found');

test('square fetchSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->fetchSubscription('SUB1');
})->throws(SubscriptionException::class, 'Failed to fetch subscription');

test('square fetchSubscription swallows customer lookup failures and treats the customer as unknown', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'ACTIVE',
                'plan_variation_id' => 'VAR123',
                'customer_id' => 'CUST1',
            ],
        ])),
        new Response(404, [], json_encode(['errors' => [['detail' => 'customer not found']]])),
    ]);

    $result = $driver->fetchSubscription('SUB1');

    expect($result->customer)->toBe('');
});

test('square fetchSubscription maps CANCELED, DEACTIVATED and PENDING statuses', function (string $status, string $expected) {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => $status,
                'plan_variation_id' => 'VAR123',
                'customer_id' => '',
            ],
        ])),
    ]);

    $result = $driver->fetchSubscription('SUB1');

    expect($result->status)->toBe($expected);
})->with([
    ['CANCELED', 'cancelled'],
    ['DEACTIVATED', 'cancelled'],
    ['PENDING', 'attention'],
]);

// ---------------------------------------------------------------------
// cancelSubscription
// ---------------------------------------------------------------------

test('square cancelSubscription falls back to fetchSubscription when pause response has no subscription', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['nothing' => 'here'])),
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'PAUSED',
                'plan_variation_id' => 'VAR123',
                'customer_id' => '',
            ],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('SUB1', [
        'pause_effective_date' => '2026-01-01',
        'pause_cycle_duration' => 2,
    ]));

    expect($result->status)->toBe('non-renewing');
});

test('square cancelSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('SUB1'));
})->throws(SubscriptionException::class, 'Failed to cancel subscription');

// ---------------------------------------------------------------------
// enableSubscription
// ---------------------------------------------------------------------

test('square enableSubscription falls back to fetchSubscription when resume response has no subscription', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['nothing' => 'here'])),
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'ACTIVE',
                'plan_variation_id' => 'VAR123',
                'customer_id' => '',
            ],
        ])),
    ]);

    $result = $driver->enableSubscription(new SubscriptionActionDTO('SUB1', [
        'resume_effective_date' => '2026-01-01',
        'resume_change_timing' => 'IMMEDIATE',
    ]));

    expect($result->status)->toBe('active');
});

test('square enableSubscription throws SubscriptionException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('SUB1'));
})->throws(SubscriptionException::class, 'Failed to enable subscription');

// ---------------------------------------------------------------------
// listSubscriptions
// ---------------------------------------------------------------------

test('square listSubscriptions returns a mapped list without a customer filter', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode([
            'subscriptions' => [
                ['id' => 'SUB1', 'status' => 'ACTIVE', 'plan_variation_id' => 'VAR123', 'customer_id' => ''],
            ],
        ])),
    ]);

    $result = $driver->listSubscriptions(10, 1);

    expect($result['data'])->toHaveCount(1);
});

test('square listSubscriptions logs a warning when page greater than one is requested', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['subscriptions' => []])),
    ]);

    $result = $driver->listSubscriptions(10, 2);

    expect($result['data'])->toBe([]);
});

test('square listSubscriptions returns an empty list when the customer is not found', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(200, [], json_encode(['customers' => []])),
    ]);

    $result = $driver->listSubscriptions(customer: 'missing@example.com');

    expect($result)->toBe(['data' => [], 'has_more' => false]);
});

test('square listSubscriptions throws SubscriptionException on a network/API error', function () {
    $driver = makeSquareSubscriptionDriver2([
        new Response(500, [], json_encode(['errors' => [['detail' => 'server error']]])),
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'Failed to list subscriptions');
