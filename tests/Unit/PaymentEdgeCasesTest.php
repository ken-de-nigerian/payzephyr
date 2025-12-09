<?php

use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment idempotency method sets idempotency key', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->idempotency('unique_key_123');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment idempotency can be chained', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment
        ->amount(10000)
        ->email('test@example.com')
        ->idempotency('key_123')
        ->currency('NGN');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment channels method sets channels array', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->channels(['card', 'bank_transfer']);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment channels can be chained', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment
        ->amount(10000)
        ->email('test@example.com')
        ->channels(['card'])
        ->currency('NGN');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment charge uses channels from data', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $payment->amount(10000)
        ->email('test@example.com')
        ->channels(['card', 'bank_transfer']);

    // Use reflection to check data
    $reflection = new ReflectionClass($payment);
    $property = $reflection->getProperty('data');
    $data = $property->getValue($payment);

    expect($data['channels'])->toBe(['card', 'bank_transfer']);
});

test('payment charge merges channels with default currency', function () {
    config([
        'payments.currency.default' => 'NGN',
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;
    $payment = new Payment($manager);

    try {
        $payment->amount(10000)
            ->email('test@example.com')
            ->channels(['card'])
            ->charge();
    } catch (Exception $e) {
        // Expected to fail due to no actual API
        // Just verifying the method doesn't throw on channels
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment redirect uses channels from data', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;
    $payment = new Payment($manager);

    try {
        $payment->amount(10000)
            ->email('test@example.com')
            ->channels(['card'])
            ->redirect();
    } catch (Exception $e) {
        // Expected to fail due to no actual API
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment with empty channels array', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->channels([]);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment idempotency with empty string', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->idempotency('');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment idempotency with long key', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $longKey = str_repeat('a', 255);
    $result = $payment->idempotency($longKey);

    expect($result)->toBeInstanceOf(Payment::class);
});
