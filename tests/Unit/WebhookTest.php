<?php

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    app()->forgetInstance('payments.config');

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

test('webhook dispatches WebhookReceived through the real ProcessWebhook job', function () {
    // The package has no 'payments.webhook.{provider}' string event -
    // WebhookReceived (a real event class) is the only thing it actually
    // dispatches (see Jobs\ProcessWebhook::handle()). Driving this through
    // the real job - rather than manually firing an event() the test itself
    // invented - is what actually exercises package behavior.
    Event::fake([WebhookReceived::class]);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123', 'status' => 'success']];
    $job = new ProcessWebhook('paystack', $payload);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) {
        return $event->provider === 'paystack' && $event->reference === 'ref_123';
    });
});

test('webhook validation works with correct paystack signature', function () {
    $manager = new PaymentManager;
    $driver = $manager->driver('paystack');

    // Real Paystack payloads carry the timestamp under data.paid_at - see ADR-0001.
    $body = json_encode(['event' => 'charge.success', 'data' => ['paid_at' => now()->toIso8601String()]]);
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

    // Real Flutterwave payloads carry 'created_at' under 'data' - see ADR-0001.
    $body = json_encode(['event' => 'charge.completed', 'data' => ['created_at' => now()->toIso8601String()]]);
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

test('webhook dispatches WebhookReceived for every provider independently', function () {
    Event::fake([WebhookReceived::class]);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_paystack', 'status' => 'success'],
    ]);
    app()->call([$job, 'handle']);

    $job = new ProcessWebhook('flutterwave', [
        'event' => 'charge.completed',
        'data' => ['tx_ref' => 'ref_flutterwave', 'status' => 'successful'],
    ]);
    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->provider === 'paystack' && $e->reference === 'ref_paystack');
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->provider === 'flutterwave' && $e->reference === 'ref_flutterwave');
});

test('webhook payload survives complex nested json through to WebhookReceived', function () {
    Event::fake([WebhookReceived::class]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'status' => 'success',
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

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) use ($payload) {
        return $event->payload === $payload
            && $event->payload['data']['metadata']['items'][0]['name'] === 'Item 1';
    });
});

test('a real Laravel listener registered for WebhookReceived is actually invoked', function () {
    $received = null;

    Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$received) {
        $received = $event;
    });

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123', 'status' => 'success'],
    ]);
    app()->call([$job, 'handle']);

    expect($received)->toBeInstanceOf(WebhookReceived::class)
        ->and($received->provider)->toBe('paystack')
        ->and($received->reference)->toBe('ref_123');
});
