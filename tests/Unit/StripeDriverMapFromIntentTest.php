<?php

use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

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
        'metadata' => ['reference' => 'ref_123'],
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->currency)->toBe('USD')
        ->and($result->paidAt)->not->toBeNull();
});

test('stripe driver mapFromPaymentIntent handles requires_payment_method status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'requires_payment_method',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'metadata' => ['reference' => 'ref_123'],
        'payment_method_types' => ['card'],
        'receipt_email' => null,
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull();
});

test('stripe driver mapFromPaymentIntent handles canceled status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'canceled',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'metadata' => ['reference' => 'ref_123'],
        'payment_method_types' => ['card'],
        'receipt_email' => null,
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->status)->toBe('failed')
        ->and($result->paidAt)->toBeNull();
});

test('stripe driver mapFromPaymentIntent handles default status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);
    
    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'unknown_status',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'metadata' => ['reference' => 'ref_123'],
        'payment_method_types' => ['card'],
        'receipt_email' => null,
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    // Unknown status normalizes to lowercase
    expect($result->status)->toBe('unknown_status')
        ->and($result->paidAt)->toBeNull();
});

test('stripe driver mapFromPaymentIntent extracts customer email', function () {
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
        'metadata' => ['reference' => 'ref_123'],
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
    ];
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, $intent);
    
    expect($result->customer)->toHaveKey('email')
        ->and($result->customer['email'])->toBe('test@example.com')
        ->and($result->channel)->toBe('card');
});
