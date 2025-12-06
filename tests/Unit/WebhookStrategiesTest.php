<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.logging.enabled' => false,
        'payments.webhook.verify_signature' => false, // Disable for these tests to focus on routing logic
    ]);
});

test('webhook controller routes monnify requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = ['event' => 'charge.success', 'transactionReference' => 'ref_monnify'];
    $request = Request::create('/payments/webhook/monnify', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'monnify');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.monnify');
});

test('webhook controller routes stripe requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    // Stripe structure usually wraps data in 'data.object'
    $payload = [
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => ['id' => 'pi_123'],
        ],
    ];
    $request = Request::create('/payments/webhook/stripe', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'stripe');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.stripe');
});

test('webhook controller routes paypal requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'pay_123']];
    $request = Request::create('/payments/webhook/paypal', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'paypal');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.paypal');
});
