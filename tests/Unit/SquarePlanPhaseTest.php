<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;

/*
 * A Square plan variation with an introductory phase lists it first, with a
 * `periods` count; the phase it bills on indefinitely has none. The driver
 * read phases[0] as the plan's price and cadence, and repriced phases[0].
 */

function squareRecordingDriver(array $responses, array &$history): SquareDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $driver = new SquareDriver(['access_token' => 'test_token', 'location_id' => 'L123', 'currencies' => ['USD']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function squareIntroPlan(int $regularAmount): Response
{
    return new Response(200, [], json_encode([
        'object' => [
            'type' => 'SUBSCRIPTION_PLAN_VARIATION',
            'id' => 'VAR_1',
            'version' => 3,
            'subscription_plan_variation_data' => [
                'name' => 'Pro',
                'subscription_plan_id' => 'PLAN_1',
                'phases' => [
                    ['ordinal' => 0, 'periods' => 1, 'cadence' => 'MONTHLY', 'recurring_price_money' => ['amount' => 100, 'currency' => 'USD']],
                    ['ordinal' => 1, 'cadence' => 'ANNUAL', 'recurring_price_money' => ['amount' => $regularAmount, 'currency' => 'USD']],
                ],
            ],
        ],
        'related_objects' => [
            ['type' => 'SUBSCRIPTION_PLAN', 'id' => 'PLAN_1', 'subscription_plan_data' => ['name' => 'Pro plan']],
        ],
    ]));
}

test('a plan with an introductory phase reports the phase it bills on indefinitely', function () {
    $history = [];
    $plan = squareRecordingDriver([squareIntroPlan(12000)], $history)->fetchPlan('VAR_1');

    expect($plan->amount)->toBe(120.0)
        ->and($plan->interval)->toBe('annually')
        ->and($plan->name)->toBe('Pro plan')
        ->and($plan->metadata)->toBe(['plan_id' => 'PLAN_1']);
});

test('repricing a plan with an introductory phase reprices the indefinite phase', function () {
    $history = [];
    $driver = squareRecordingDriver([squareIntroPlan(12000), new Response(200, [], '{}'), squareIntroPlan(15000)], $history);

    $plan = $driver->updatePlan('VAR_1', ['amount' => 150]);

    $phases = json_decode((string) $history[1]['request']->getBody(), true)['object']['subscription_plan_variation_data']['phases'];

    expect($phases[0]['recurring_price_money']['amount'])->toBe(100)
        ->and($phases[1]['recurring_price_money']['amount'])->toBe(15000)
        ->and($plan->amount)->toBe(150.0);
});

test('listing subscriptions looks each customer up once, not once per subscription', function () {
    $history = [];
    $driver = squareRecordingDriver([
        new Response(200, [], json_encode(['subscriptions' => [
            ['id' => 'SUB_1', 'status' => 'ACTIVE', 'customer_id' => 'CUST_1', 'plan_variation_id' => 'VAR_1'],
            ['id' => 'SUB_2', 'status' => 'PAUSED', 'customer_id' => 'CUST_1', 'plan_variation_id' => 'VAR_1'],
        ]])),
        new Response(200, [], json_encode(['customer' => ['id' => 'CUST_1', 'email_address' => 'a@b.com']])),
    ], $history);

    $listing = $driver->listSubscriptions();

    expect($history)->toHaveCount(2)
        ->and(array_map(fn ($s) => [$s->subscriptionCode, $s->customer, $s->status], $listing['data']))
        ->toBe([['SUB_1', 'a@b.com', 'active'], ['SUB_2', 'a@b.com', 'non-renewing']]);
});

test('listing one customer\'s subscriptions uses the customer it already found', function () {
    $history = [];
    $driver = squareRecordingDriver([
        new Response(200, [], json_encode(['customers' => [['id' => 'CUST_1', 'email_address' => 'a@b.com']]])),
        new Response(200, [], json_encode(['subscriptions' => [
            ['id' => 'SUB_1', 'status' => 'ACTIVE', 'customer_id' => 'CUST_1', 'plan_variation_id' => 'VAR_1'],
        ]])),
    ], $history);

    $listing = $driver->listSubscriptions(customer: 'a@b.com');

    expect($history)->toHaveCount(2)
        ->and($listing['data'][0]->customer)->toBe('a@b.com');
});

test('a plan whose every phase ends reports its last phase', function () {
    $history = [];
    $driver = squareRecordingDriver([new Response(200, [], json_encode(['object' => [
        'type' => 'SUBSCRIPTION_PLAN_VARIATION',
        'id' => 'VAR_2',
        'subscription_plan_variation_data' => ['name' => 'Twelve months', 'phases' => [
            ['ordinal' => 0, 'periods' => 1, 'cadence' => 'MONTHLY', 'recurring_price_money' => ['amount' => 100, 'currency' => 'USD']],
            ['ordinal' => 1, 'periods' => 11, 'cadence' => 'MONTHLY', 'recurring_price_money' => ['amount' => 900, 'currency' => 'USD']],
        ]],
    ]]))], $history);

    $plan = $driver->fetchPlan('VAR_2');

    expect($plan->amount)->toBe(9.0)
        ->and($plan->name)->toBe('Twelve months');
});
