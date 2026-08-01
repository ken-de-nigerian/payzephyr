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
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makeSquareSubscriptionDriver(array $responses): SquareDriver
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

test('square createPlan batch-upserts a subscription plan and variation', function () {
    $driver = makeSquareSubscriptionDriver([
        new Response(200, [], json_encode([
            'objects' => [
                [
                    'type' => 'SUBSCRIPTION_PLAN',
                    'id' => 'PLAN123',
                    'subscription_plan_data' => ['name' => 'Pro Plan'],
                ],
                [
                    'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                    'id' => 'VAR123',
                    'subscription_plan_variation_data' => [
                        'name' => 'Pro Plan',
                        'phases' => [[
                            'cadence' => 'MONTHLY',
                            'recurring_price_money' => ['amount' => 5000, 'currency' => 'USD'],
                            'ordinal' => 0,
                        ]],
                        'subscription_plan_id' => 'PLAN123',
                    ],
                ],
            ],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 50, 'monthly', 'USD');
    $result = $driver->createPlan($plan);

    expect($result->planCode)->toBe('VAR123')
        ->and($result->name)->toBe('Pro Plan')
        ->and($result->amount)->toBe(50.0)
        ->and($result->interval)->toBe('monthly')
        ->and($result->currency)->toBe('USD');
});

test('square createSubscription requires an authorization (card on file)', function () {
    $driver = makeSquareSubscriptionDriver([]);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'VAR123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Square requires an existing card-on-file ID');

test('square createSubscription finds or creates a customer then creates the subscription', function () {
    $driver = makeSquareSubscriptionDriver([
        new Response(200, [], json_encode(['customers' => []])),
        new Response(200, [], json_encode([
            'customer' => ['id' => 'CUST1', 'email_address' => 'test@example.com'],
        ])),
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'ACTIVE',
                'plan_variation_id' => 'VAR123',
                'customer_id' => 'CUST1',
                'price_override_money' => ['amount' => 5000, 'currency' => 'USD'],
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'VAR123',
        authorization: 'ccof:card123'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('SUB1')
        ->and($result->status)->toBe('active')
        ->and($result->customer)->toBe('test@example.com')
        ->and($result->amount)->toBe(50.0);
});

test('square cancelSubscription pauses and maps to non-renewing', function () {
    $driver = makeSquareSubscriptionDriver([
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'PAUSED',
                'plan_variation_id' => 'VAR123',
                'customer_id' => 'CUST1',
            ],
        ])),
        new Response(200, [], json_encode([
            'customer' => ['id' => 'CUST1', 'email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('SUB1'));

    expect($result->status)->toBe('non-renewing')
        ->and($result->canBeResumed())->toBeTrue();
});

test('square enableSubscription resumes a paused subscription', function () {
    $driver = makeSquareSubscriptionDriver([
        new Response(200, [], json_encode([
            'subscription' => [
                'id' => 'SUB1',
                'status' => 'ACTIVE',
                'plan_variation_id' => 'VAR123',
                'customer_id' => 'CUST1',
            ],
        ])),
        new Response(200, [], json_encode([
            'customer' => ['id' => 'CUST1', 'email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->enableSubscription(new SubscriptionActionDTO('SUB1'));

    expect($result->status)->toBe('active')
        ->and($result->isActive())->toBeTrue();
});

test('square listSubscriptions filters by customer via search', function () {
    $driver = makeSquareSubscriptionDriver([
        new Response(200, [], json_encode([
            'customers' => [['id' => 'CUST1', 'email_address' => 'test@example.com']],
        ])),
        new Response(200, [], json_encode([
            'subscriptions' => [
                [
                    'id' => 'SUB1',
                    'status' => 'ACTIVE',
                    'plan_variation_id' => 'VAR123',
                    'customer_id' => 'CUST1',
                ],
            ],
        ])),
        new Response(200, [], json_encode([
            'customer' => ['id' => 'CUST1', 'email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->listSubscriptions(customer: 'test@example.com');

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->subscriptionCode)->toBe('SUB1');
});
