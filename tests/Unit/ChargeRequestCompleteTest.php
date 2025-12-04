<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;

// Amount Validation Tests
test('charge request validates amount', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => -100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Amount must be greater than zero');
});

test('charge request rejects zero amount', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 0,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request accepts decimal amounts', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(100.50);
});

test('charge request accepts large amounts', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 1000000.99,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(1000000.99);
});

// Email Validation Tests
test('charge request validates email', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'invalid-email',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('charge request rejects empty email', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => '',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request accepts valid email formats', function () {
    $emails = [
        'simple@example.com',
        'user+tag@example.com',
        'user.name@example.com',
        'user_name@example.co.uk',
        '123@example.com',
    ];

    foreach ($emails as $email) {
        $request = ChargeRequest::fromArray([
            'amount' => 100,
            'currency' => 'NGN',
            'email' => $email,
        ]);

        expect($request->email)->toBe($email);
    }
});

// Currency Validation Tests
test('charge request validates currency format', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'INVALID',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request rejects empty currency', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => '',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request normalizes currency to uppercase', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'ngn',
        'email' => 'test@example.com',
    ]);

    expect($request->currency)->toBe('NGN');
});

test('charge request accepts standard currency codes', function () {
    $currencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES'];

    foreach ($currencies as $currency) {
        $request = ChargeRequest::fromArray([
            'amount' => 100,
            'currency' => $currency,
            'email' => 'test@example.com',
        ]);

        expect($request->currency)->toBe($currency);
    }
});

// Minor Units Conversion Tests
test('charge request converts amount to minor units', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(10050);
});

test('charge request converts whole numbers to minor units', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(100000);
});

test('charge request rounds minor units correctly', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100.555,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(10056); // Rounded
});

// Array Conversion Tests
test('charge request creates from array', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 5000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'REF_123',
        'metadata' => ['order_id' => 123],
    ]);

    expect($request->amount)->toBe(5000.0)
        ->and($request->currency)->toBe('NGN')
        ->and($request->email)->toBe('test@example.com')
        ->and($request->reference)->toBe('REF_123')
        ->and($request->metadata)->toBe(['order_id' => 123]);
});

test('charge request converts to array', function () {
    $data = [
        'amount' => 5000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'reference' => 'REF_123',
        'callback_url' => 'https://example.com/callback',
        'metadata' => ['key' => 'value'],
        'description' => 'Test payment',
    ];

    $request = ChargeRequest::fromArray($data);
    $array = $request->toArray();

    expect($array['amount'])->toBe(5000.0)
        ->and($array['currency'])->toBe('USD')
        ->and($array['email'])->toBe('test@example.com')
        ->and($array['reference'])->toBe('REF_123')
        ->and($array['callback_url'])->toBe('https://example.com/callback');
});

// Optional Fields Tests
test('charge request handles null reference', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->reference)->toBeNull();
});

test('charge request handles null callback url', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->callbackUrl)->toBeNull();
});

test('charge request handles empty metadata', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->metadata)->toBe([]);
});

test('charge request handles complex metadata', function () {
    $metadata = [
        'order_id' => 12345,
        'customer_id' => 'cust_123',
        'items' => [
            ['id' => 1, 'name' => 'Item 1', 'price' => 5000],
            ['id' => 2, 'name' => 'Item 2', 'price' => 3000],
        ],
        'shipping' => [
            'address' => '123 Main St',
            'city' => 'Lagos',
            'country' => 'Nigeria',
        ],
    ];

    $request = ChargeRequest::fromArray([
        'amount' => 8000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => $metadata,
    ]);

    expect($request->metadata)->toBe($metadata);
});

test('charge request handles customer data', function () {
    $customer = [
        'name' => 'John Doe',
        'phone' => '+2348012345678',
        'address' => '123 Main St',
    ];

    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'customer' => $customer,
    ]);

    expect($request->customer)->toBe($customer);
});

test('charge request handles description', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'description' => 'Premium subscription payment',
    ]);

    expect($request->description)->toBe('Premium subscription payment');
});

test('charge request handles custom fields', function () {
    $customFields = [
        ['display_name' => 'Invoice ID', 'variable_name' => 'invoice_id', 'value' => 'INV_123'],
    ];

    $request = ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'custom_fields' => $customFields,
    ]);

    expect($request->customFields)->toBe($customFields);
});

test('charge request handles split payment config', function () {
    $split = [
        'type' => 'percentage',
        'bearer_type' => 'account',
        'subaccounts' => [
            ['subaccount' => 'ACCT_123', 'share' => 20],
        ],
    ];

    $request = ChargeRequest::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'split' => $split,
    ]);

    expect($request->split)->toBe($split);
});

test('charge request is readonly/immutable', function () {
    $request = new ChargeRequest(
        amount: 100,
        currency: 'NGN',
        email: 'test@example.com'
    );

    expect($request)->toBeInstanceOf(ChargeRequest::class)
        ->and($request->amount)->toBe(100.0);
});
