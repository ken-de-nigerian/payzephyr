<?php

use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;

test('stripe driver mapFromCheckoutSession handles paid status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'paid',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
        'payment_intent' => null,
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $session);
    
    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->paidAt)->not->toBeNull();
});

test('stripe driver mapFromCheckoutSession handles unpaid status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'unpaid',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $session);
    
    expect($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull();
});

test('stripe driver mapFromCheckoutSession handles failed status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'failed',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
        'payment_intent' => null,
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $session);
    
    expect($result->status)->toBe('failed');
});

test('stripe driver mapFromCheckoutSession uses payment intent amount when amount_total missing', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $pi = (object) ['amount' => 20000];
    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'paid',
        'amount_total' => null,
        'payment_intent' => $pi,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $session);
    
    expect($result->amount)->toBe(200.0);
});

test('stripe driver mapFromPaymentIntent handles succeeded status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
        'metadata' => ['reference' => 'ref_123'],
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->paidAt)->not->toBeNull();
});

test('stripe driver mapFromPaymentIntent uses id when metadata reference missing', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
        'metadata' => [],
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->reference)->toBe('pi_test_123');
});

test('stripe driver healthCheck returns true for authentication exception', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow(new AuthenticationException('Invalid API key'));
    
    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);
    
    expect($driver->healthCheck())->toBeTrue();
});

test('stripe driver healthCheck returns true for 4xx errors', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $exception = Mockery::mock(ApiErrorException::class);
    $exception->shouldReceive('getHttpStatus')->andReturn(404);
    
    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow($exception);
    
    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);
    
    expect($driver->healthCheck())->toBeTrue();
});

test('stripe driver healthCheck returns false for 5xx errors', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $exception = Mockery::mock(ApiErrorException::class);
    $exception->shouldReceive('getHttpStatus')->andReturn(500);
    $exception->shouldReceive('getMessage')->andReturn('Server error');
    
    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow($exception);
    
    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);
    
    expect($driver->healthCheck())->toBeFalse();
});
