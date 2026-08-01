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
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function makePayPalSubscriptionDriver(array $responses): PayPalDriver
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

test('paypal createPlan creates a catalog product then a billing plan', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(201, [], json_encode(['id' => 'PROD-123', 'name' => 'Pro Plan'])),
        new Response(201, [], json_encode([
            'id' => 'P-123',
            'name' => 'Pro Plan',
            'description' => 'Pro Plan',
            'product_id' => 'PROD-123',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '20.00', 'currency_code' => 'USD']],
            ]],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO('Pro Plan', 20.00, 'monthly', 'USD');
    $result = $driver->createPlan($plan);

    expect($result->planCode)->toBe('P-123')
        ->and($result->name)->toBe('Pro Plan')
        ->and($result->amount)->toBe(20.0)
        ->and($result->interval)->toBe('monthly')
        ->and($result->currency)->toBe('USD')
        ->and($result->metadata['product_id'])->toBe('PROD-123');
});

test('paypal fetchPlan retrieves and maps a plan', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(200, [], json_encode([
            'id' => 'P-abc',
            'name' => 'Annual Plan',
            'product_id' => 'PROD-456',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '100.00', 'currency_code' => 'EUR']],
            ]],
        ])),
    ]);

    $result = $driver->fetchPlan('P-abc');

    expect($result->planCode)->toBe('P-abc')
        ->and($result->interval)->toBe('annually')
        ->and($result->currency)->toBe('EUR')
        ->and($result->amount)->toBe(100.0);
});

test('paypal createSubscription requires a callback URL', function () {
    $driver = makePayPalSubscriptionDriver([]);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'P-123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'PayPal requires a callback URL');

test('paypal createSubscription succeeds and captures the approval url', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(201, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'APPROVAL_PENDING',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.paypal.com/v1/billing/subscriptions/I-XYZ'],
                ['rel' => 'approve', 'href' => 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=abc'],
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'P-123',
        callbackUrl: 'https://example.com/callback'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('I-XYZ')
        ->and($result->status)->toBe('attention')
        ->and($result->metadata['approval_url'])->toContain('ba_token=abc');
});

test('paypal cancelSubscription suspends by default (reversible)', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(204),
        new Response(200, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'SUSPENDED',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('I-XYZ'));

    expect($result->status)->toBe('non-renewing')
        ->and($result->canBeResumed())->toBeTrue();
});

test('paypal enableSubscription throws when the subscription is permanently cancelled', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(200, [], json_encode(['id' => 'I-XYZ', 'status' => 'CANCELLED'])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('I-XYZ'));
})->throws(SubscriptionException::class, 'cannot be reactivated');

test('paypal enableSubscription activates a suspended subscription', function () {
    $driver = makePayPalSubscriptionDriver([
        new Response(200, [], json_encode(['id' => 'I-XYZ', 'status' => 'SUSPENDED'])),
        new Response(204),
        new Response(200, [], json_encode([
            'id' => 'I-XYZ',
            'status' => 'ACTIVE',
            'plan_id' => 'P-123',
            'subscriber' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->enableSubscription(new SubscriptionActionDTO('I-XYZ'));

    expect($result->status)->toBe('active')
        ->and($result->isActive())->toBeTrue();
});

test('paypal listSubscriptions throws - no such endpoint exists', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'currencies' => ['USD'],
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'does not provide an API to list subscriptions');
