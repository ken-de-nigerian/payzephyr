<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;

/*
 * A PayPal plan with a trial lists the trial cycle first. The driver read the
 * first cycle as the plan's price and interval, and repriced sequence 1 -
 * the trial - when asked to change the price.
 */

function payPalRecordingDriver(array $responses, array &$history): PayPalDriver
{
    $stack = HandlerStack::create(new MockHandler(array_merge([
        new Response(200, [], json_encode(['access_token' => 'A21_test_token', 'token_type' => 'Bearer', 'expires_in' => 32400])),
    ], $responses)));
    $stack->push(Middleware::history($history));

    $driver = new PayPalDriver(['client_id' => 'id', 'client_secret' => 'secret', 'mode' => 'sandbox', 'currencies' => ['USD']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function payPalTrialPlan(string $regularPrice): Response
{
    return new Response(200, [], json_encode([
        'id' => 'P-trial',
        'name' => 'Pro',
        'billing_cycles' => [
            [
                'tenure_type' => 'TRIAL', 'sequence' => 1, 'total_cycles' => 1,
                'frequency' => ['interval_unit' => 'WEEK', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '0', 'currency_code' => 'USD']],
            ],
            [
                'tenure_type' => 'REGULAR', 'sequence' => 2, 'total_cycles' => 0,
                'frequency' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => $regularPrice, 'currency_code' => 'USD']],
            ],
        ],
    ]));
}

test('a plan with a trial reports its regular price and interval', function () {
    $history = [];
    $plan = payPalRecordingDriver([payPalTrialPlan('120.00')], $history)->fetchPlan('P-trial');

    expect($plan->amount)->toBe(120.0)
        ->and($plan->interval)->toBe('annually')
        ->and($plan->currency)->toBe('USD');
});

test('repricing a plan with a trial reprices its regular cycle', function () {
    $history = [];
    $driver = payPalRecordingDriver([payPalTrialPlan('120.00'), new Response(204), payPalTrialPlan('150.00')], $history);

    $plan = $driver->updatePlan('P-trial', ['amount' => 150]);

    $sent = json_decode((string) $history[2]['request']->getBody(), true);

    expect($sent['pricing_schemes'][0]['billing_cycle_sequence'])->toBe(2)
        ->and($sent['pricing_schemes'][0]['pricing_scheme']['fixed_price'])->toBe(['value' => '150.00', 'currency_code' => 'USD'])
        ->and($plan->amount)->toBe(150.0);
});

test('a cancel with permanent set to the string false suspends rather than cancels for good', function (mixed $permanent, string $endpoint) {
    $history = [];
    $driver = payPalRecordingDriver([
        new Response(204),
        new Response(200, [], json_encode(['id' => 'I-XYZ', 'status' => 'SUSPENDED', 'plan_id' => 'P-1'])),
    ], $history);

    $driver->cancelSubscription(new SubscriptionActionDTO('I-XYZ', ['permanent' => $permanent, 'reason' => 404]));

    expect((string) $history[1]['request']->getUri())->toEndWith("/v1/billing/subscriptions/I-XYZ/$endpoint")
        ->and(json_decode((string) $history[1]['request']->getBody(), true))->toBe(['reason' => '404']);
})->with([
    ['false', 'suspend'],
    ['0', 'suspend'],
    [true, 'cancel'],
    ['yes', 'cancel'],
]);
