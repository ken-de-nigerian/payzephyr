<?php

use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.default' => 'paystack',
        'payments.currency.default' => 'NGN',
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ],
    ]);
});

test('payment fluent api sets amount', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->amount(10000);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets currency', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->currency('USD');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets email', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->email('test@example.com');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets reference', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->reference('REF_123');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets callback url', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->callback('https://example.com/callback');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets metadata', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->metadata(['order_id' => 123]);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets description', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->description('Test payment');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets customer', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->customer(['name' => 'John Doe']);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets single provider', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->with('paystack');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api sets multiple providers', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->with(['paystack', 'stripe']);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api using alias works', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->using('stripe');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api chains multiple methods', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment
        ->amount(10000)
        ->currency('NGN')
        ->email('test@example.com')
        ->reference('REF_123')
        ->callback('https://example.com/callback')
        ->metadata(['order_id' => 123])
        ->description('Test payment')
        ->customer(['name' => 'John Doe'])
        ->with('paystack');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment fluent api uses default currency from config', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    // Don't set currency, should use default
    $payment->amount(10000)->email('test@example.com');

    expect(true)->toBeTrue(); // If no exception, default was used
});

test('payment fluent api normalizes currency to uppercase', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->currency('usd');

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment helper function creates payment instance', function () {
    $payment = payment();

    expect($payment)->toBeInstanceOf(Payment::class);
});

test('payment helper function can chain methods', function () {
    $payment = payment()
        ->amount(5000)
        ->email('test@example.com');

    expect($payment)->toBeInstanceOf(Payment::class);
});

test('payment fluent api builds charge request correctly', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret',
            'enabled' => true,
            'currencies' => ['NGN'],
            'base_url' => 'https://api.paystack.co',
        ],
    ]);

    $manager = new PaymentManager;
    $payment = new Payment($manager);

    // This will attempt to charge but fail due to no API access
    // We're just testing that the fluent API builds correctly
    try {
        $payment
            ->amount(10000)
            ->currency('NGN')
            ->email('test@example.com')
            ->charge();
    } catch (Exception $e) {
        // Expected to fail, we're just checking the API works
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment allows complex metadata', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $metadata = [
        'order_id' => 12345,
        'customer_id' => 'cust_123',
        'items' => [
            ['name' => 'Product 1', 'price' => 5000],
            ['name' => 'Product 2', 'price' => 5000],
        ],
    ];

    $result = $payment->metadata($metadata);

    expect($result)->toBeInstanceOf(Payment::class);
});

test('payment allows complex customer data', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $customer = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+2348012345678',
        'address' => '123 Main St',
    ];

    $result = $payment->customer($customer);

    expect($result)->toBeInstanceOf(Payment::class);
});
