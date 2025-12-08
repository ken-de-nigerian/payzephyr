<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Constants\PaymentStatus;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('webhook controller handles default status in determineStatus', function () {
    $manager = Mockery::mock(PaymentManager::class);
    $normalizer = new StatusNormalizer();
    $controller = new WebhookController($manager, $normalizer);
    
    $payload = ['unknown_field' => 'value'];
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($controller, 'unknown_provider', $payload);
    
    expect($status)->toBe('unknown');
});

test('webhook controller handles paypal status from event_type', function () {
    $manager = Mockery::mock(PaymentManager::class);
    $normalizer = new StatusNormalizer();
    $controller = new WebhookController($manager, $normalizer);
    
    $payload = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'];
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($controller, 'paypal', $payload);
    
    // event_type is returned as-is and normalized
    expect($status)->toBe('payment.capture.completed');
});

test('webhook controller handles webhook update with channel', function () {
    Event::fake();
    
    $transaction = PaymentTransaction::create([
        'reference' => 'test_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);
    
    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'test_ref',
            'status' => 'success',
            'channel' => 'card',
        ],
    ]);
    
    $request->headers->set('x-paystack-signature', 'valid');
    
    // Mock driver
    $driver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturn(true);
    $manager = Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    
    $controller = new WebhookController($manager);
    $response = $controller->handle($request, 'paystack');
    
    expect($response->getStatusCode())->toBe(200);
    
    $transaction->refresh();
    expect($transaction->channel)->toBe('card');
});

test('webhook controller handles database error in updateTransactionFromWebhook', function () {
    Event::fake();
    
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);
    
    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'nonexistent_ref',
            'status' => 'success',
        ],
    ]);
    
    $request->headers->set('x-paystack-signature', 'valid');
    
    // Mock driver
    $driver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturn(true);
    $manager = Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    
    $controller = new WebhookController($manager);
    $response = $controller->handle($request, 'paystack');
    
    // Should still return 200 even if transaction update fails
    expect($response->getStatusCode())->toBe(200);
});

test('webhook controller handles successful status with paid_at', function () {
    Event::fake();
    
    $transaction = PaymentTransaction::create([
        'reference' => 'test_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);
    
    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'test_ref',
            'status' => 'success',
        ],
    ]);
    
    $request->headers->set('x-paystack-signature', 'valid');
    
    // Mock driver
    $driver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturn(true);
    $manager = Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    
    $controller = new WebhookController($manager);
    $response = $controller->handle($request, 'paystack');
    
    expect($response->getStatusCode())->toBe(200);
    
    $transaction->refresh();
    expect($transaction->paid_at)->not->toBeNull();
});
