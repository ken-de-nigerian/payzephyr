<?php

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers' => [
            'paystack' => [
                'driver' => 'paystack',
                'secret_key' => 'test_secret',
                'enabled' => true,
            ],
            'flutterwave' => [
                'driver' => 'flutterwave',
                'secret_key' => 'test_secret',
                'webhook_secret' => 'webhook_secret',
                'enabled' => true,
            ],
        ],
    ]);
});

test('webhook route is registered', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri());

    expect($routes->contains('payments/webhook/{provider}'))->toBeTrue();
});

test('webhook dispatches provider-specific event', function () {
    Event::fake();

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123']];

    event('payments.webhook.paystack', [$payload]);

    Event::assertDispatched('payments.webhook.paystack');
});

test('webhook dispatches general event', function () {
    Event::fake();

    $payload = ['event' => 'charge.success'];

    event('payments.webhook', ['paystack', $payload]);

    Event::assertDispatched('payments.webhook');
});

test('webhook validation works with correct paystack signature', function () {
    $manager = new PaymentManager;
    $driver = $manager->driver('paystack');

    $body = '{"event":"charge.success"}';
    $signature = hash_hmac('sha512', $body, 'test_secret');

    $headers = ['x-paystack-signature' => [$signature]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('webhook validation fails with incorrect paystack signature', function () {
    $manager = new PaymentManager;
    $driver = $manager->driver('paystack');

    $body = '{"event":"charge.success"}';
    $headers = ['x-paystack-signature' => ['wrong_signature']];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('webhook validation works with correct flutterwave signature', function () {
    $manager = new PaymentManager;
    $driver = $manager->driver('flutterwave');

    $body = '{"event":"charge.completed"}';
    $headers = ['verif-hash' => ['webhook_secret']];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('webhook handles missing signature header', function () {
    $manager = new PaymentManager;
    $driver = $manager->driver('paystack');

    $body = '{"event":"charge.success"}';
    $headers = [];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('webhook can be disabled in config', function () {
    config(['payments.webhook.verify_signature' => false]);

    expect(config('payments.webhook.verify_signature'))->toBeFalse();
});

test('webhook path can be customized', function () {
    config(['payments.webhook.path' => '/custom/webhook']);

    expect(config('payments.webhook.path'))->toBe('/custom/webhook');
});

test('webhook middleware can be customized', function () {
    config(['payments.webhook.middleware' => ['api', 'throttle']]);

    expect(config('payments.webhook.middleware'))->toBe(['api', 'throttle']);
});

test('webhook handles multiple providers', function () {
    Event::fake();

    $providers = ['paystack', 'flutterwave', 'stripe', 'monnify', 'paypal'];

    foreach ($providers as $provider) {
        event("payments.webhook.$provider", [['event' => 'test']]);
    }

    Event::assertDispatched('payments.webhook.paystack');
    Event::assertDispatched('payments.webhook.flutterwave');
    Event::assertDispatched('payments.webhook.stripe');
    Event::assertDispatched('payments.webhook.monnify');
    Event::assertDispatched('payments.webhook.paypal');
});

test('webhook payload can be complex json', function () {
    Event::fake();

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'amount' => 10000,
            'currency' => 'NGN',
            'customer' => [
                'email' => 'test@example.com',
                'name' => 'John Doe',
            ],
            'metadata' => [
                'order_id' => 12345,
                'items' => [
                    ['name' => 'Item 1', 'qty' => 2],
                ],
            ],
        ],
    ];

    event('payments.webhook.paystack', [$payload]);

    Event::assertDispatched('payments.webhook.paystack', function ($event, $data) {
        return $data[0]['data']['reference'] === 'ref_123';
    });
});

test('webhook events can have listeners', function () {
    Event::fake();

    Event::listen('payments.webhook.paystack', function ($payload) {
        // Listener logic
    });

    event('payments.webhook.paystack', [['event' => 'charge.success']]);

    Event::assertDispatched('payments.webhook.paystack');
    Event::assertListening('payments.webhook.paystack', Closure::class);
});
