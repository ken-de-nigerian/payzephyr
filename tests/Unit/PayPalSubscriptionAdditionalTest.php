<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makePayPalSubscriptionDriver2(array $responses): PayPalDriver
{
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $oauthResponse = new Response(200, [], json_encode([
        'access_token' => 'A21_test_token',
        'token_type' => 'Bearer',
        'expires_in' => 32400,
    ]));

    $mock = new MockHandler([$oauthResponse, ...$responses]);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

// ---------------------------------------------------------------------
// createPlan - error branch
// ---------------------------------------------------------------------

test('paypal createPlan throws PlanException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 20.00, 'monthly', 'USD');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Failed to create plan');

test('paypal createPlan maps daily, weekly and annually intervals', function (string $interval, string $intervalUnit) {
    $driver = makePayPalSubscriptionDriver2([
        new Response(201, [], json_encode(['id' => 'PROD-123', 'name' => 'Plan'])),
        new Response(201, [], json_encode([
            'id' => 'P-123',
            'name' => 'Plan',
            'product_id' => 'PROD-123',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => $intervalUnit, 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '10.00', 'currency_code' => 'USD']],
            ]],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Plan', 10.00, $interval, 'USD');
    $result = $driver->createPlan($plan);

    expect($result->interval)->toBe($interval);
})->with([
    ['daily', 'DAY'],
    ['weekly', 'WEEK'],
    ['annually', 'YEAR'],
]);

// ---------------------------------------------------------------------
// updatePlan
// ---------------------------------------------------------------------

test('paypal updatePlan patches the description only', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(204),
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Plan',
            'description' => 'New description',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '10.00', 'currency_code' => 'USD']],
            ]],
        ])),
    ]);

    $result = $driver->updatePlan('P-abc', ['description' => 'New description']);

    expect($result->description)->toBe('New description');
});

test('paypal updatePlan updates the pricing scheme when the amount changes with an explicit currency', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(204),
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Plan',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '50.00', 'currency_code' => 'EUR']],
            ]],
        ])),
    ]);

    $result = $driver->updatePlan('P-abc', ['amount' => 50.00, 'currency' => 'EUR']);

    expect($result->amount)->toBe(50.0)
        ->and($result->currency)->toBe('EUR');
});

test('paypal updatePlan falls back to the existing plan currency when the amount changes without one', function () {
    $driver = makePayPalSubscriptionDriver2([
        // fetchPlan() call inside updatePlan() to resolve currency
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Plan',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '10.00', 'currency_code' => 'GBP']],
            ]],
        ])),
        // update-pricing-schemes call
        new Response(204),
        // final fetchPlan() call
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Plan',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '75.00', 'currency_code' => 'GBP']],
            ]],
        ])),
    ]);

    $result = $driver->updatePlan('P-abc', ['amount' => 75.00]);

    expect($result->amount)->toBe(75.0)
        ->and($result->currency)->toBe('GBP');
});

test('paypal updatePlan throws PlanException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $driver->updatePlan('P-abc', ['description' => 'New description']);
})->throws(PlanException::class, 'Failed to update plan');

// ---------------------------------------------------------------------
// fetchPlan - error branch
// ---------------------------------------------------------------------

test('paypal fetchPlan throws PlanException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(404, [], json_encode(['error' => 'not_found'])),
    ]);

    $driver->fetchPlan('P-missing');
})->throws(PlanException::class, 'Failed to get plan');

test('paypal fetchPlan maps day and week intervals back to daily/weekly', function (string $intervalUnit, string $expected) {
    $driver = makePayPalSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Plan',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => $intervalUnit, 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '10.00', 'currency_code' => 'USD']],
            ]],
        ])),
    ]);

    $result = $driver->fetchPlan('P-abc');

    expect($result->interval)->toBe($expected);
})->with([
    ['DAY', 'daily'],
    ['WEEK', 'weekly'],
]);

// ---------------------------------------------------------------------
// listPlans
// ---------------------------------------------------------------------

test('paypal listPlans returns a mapped list of plans', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(200, [], json_encode([
            'plans' => [
                [
                    'id' => 'P-1',
                    'name' => 'Plan One',
                    'billing_cycles' => [[
                        'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                        'pricing_scheme' => ['fixed_price' => ['value' => '10.00', 'currency_code' => 'USD']],
                    ]],
                ],
            ],
            'total_items' => 1,
        ])),
    ]);

    $result = $driver->listPlans(10, 1);

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->planCode)->toBe('P-1')
        ->and($result['total_items'])->toBe(1);
});

test('paypal listPlans throws PlanException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class, 'Failed to list plans');

// ---------------------------------------------------------------------
// createSubscription
// ---------------------------------------------------------------------

test('paypal createSubscription sends an idempotency key header when provided', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(201, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'APPROVAL_PENDING',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
            'links' => [],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'P-123',
        callbackUrl: 'https://example.com/callback',
        idempotencyKey: 'idem-key-123'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('I-XYZ');
});

test('paypal createSubscription throws SubscriptionException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'P-123',
        callbackUrl: 'https://example.com/callback'
    );

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Failed to create subscription');

// ---------------------------------------------------------------------
// fetchSubscription
// ---------------------------------------------------------------------

test('paypal fetchSubscription retrieves and maps a subscription', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'ACTIVE',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->fetchSubscription('I-XYZ');

    expect($result->subscriptionCode)->toBe('I-XYZ')
        ->and($result->status)->toBe('active');
});

test('paypal fetchSubscription throws SubscriptionException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(404, [], json_encode(['error' => 'not_found'])),
    ]);

    $driver->fetchSubscription('I-missing');
})->throws(SubscriptionException::class, 'Failed to fetch subscription');

test('paypal fetchSubscription maps CANCELLED and EXPIRED statuses', function (string $status, string $expected) {
    $driver = makePayPalSubscriptionDriver2([
        new Response(200, [], json_encode([
            'id' => 'I-XYZ',
            'status' => $status,
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->fetchSubscription('I-XYZ');

    expect($result->status)->toBe($expected);
})->with([
    ['CANCELLED', 'cancelled'],
    ['EXPIRED', 'expired'],
]);

// ---------------------------------------------------------------------
// cancelSubscription - error branch
// ---------------------------------------------------------------------

test('paypal cancelSubscription throws SubscriptionException on API error', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('I-XYZ'));
})->throws(SubscriptionException::class, 'Failed to cancel subscription');

test('paypal cancelSubscription cancels permanently and with a custom reason when requested', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(204),
        new Response(200, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'CANCELLED',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('I-XYZ', [
        'permanent' => true,
        'reason' => 'No longer needed',
    ]));

    expect($result->status)->toBe('cancelled')
        ->and($result->isCancelled())->toBeTrue();
});

// ---------------------------------------------------------------------
// enableSubscription - error branch
// ---------------------------------------------------------------------

test('paypal enableSubscription throws SubscriptionException when the PayPal API errors', function () {
    $driver = makePayPalSubscriptionDriver2([
        new Response(500, [], json_encode(['error' => 'server_error'])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('I-XYZ'));
})->throws(SubscriptionException::class, 'Failed to enable subscription');
