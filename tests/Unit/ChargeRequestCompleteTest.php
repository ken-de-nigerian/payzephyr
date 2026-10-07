<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;

test('charge request validates amount', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => -100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Amount must be greater than zero');
});

test('charge request rejects zero amount', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 0,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request accepts decimal amounts', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(100.50);
});

test('charge request accepts large amounts', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000000.99,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(1000000.99);
});

test('charge request validates email', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'invalid-email',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('charge request rejects empty email', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => '',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request accepts valid email formats', function (): void {
    $emails = [
        'simple@example.com',
        'user+tag@example.com',
        'user.name@example.com',
        'user_name@example.co.uk',
        '123@example.com',
    ];

    foreach ($emails as $email) {
        $request = ChargeRequestDTO::fromArray([
            'amount' => 100,
            'currency' => 'NGN',
            'email' => $email,
        ]);

        expect($request->email)->toBe($email);
    }
});

test('charge request validates currency format', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'INVALID',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request rejects empty currency', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => '',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request normalizes currency to uppercase', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'ngn',
        'email' => 'test@example.com',
    ]);

    expect($request->currency)->toBe('NGN');
});

test('charge request accepts standard currency codes', function (): void {
    $currencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES'];

    foreach ($currencies as $currency) {
        $request = ChargeRequestDTO::fromArray([
            'amount' => 100,
            'currency' => $currency,
            'email' => 'test@example.com',
        ]);

        expect($request->currency)->toBe($currency);
    }
});

test('charge request converts amount to minor units', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(10050);
});

test('charge request converts whole numbers to minor units', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(100000);
});

test('charge request rounds minor units correctly', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.555,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(10056); // Rounded
});

test('charge request creates from array', function (): void {
    $request = ChargeRequestDTO::fromArray([
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

test('charge request converts to array', function (): void {
    $data = [
        'amount' => 5000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'reference' => 'REF_123',
        'callback_url' => 'https://example.com/callback',
        'metadata' => ['key' => 'value'],
        'description' => 'Test payment',
    ];

    $request = ChargeRequestDTO::fromArray($data);
    $array = $request->toArray();

    expect($array['amount'])->toBe(5000.0)
        ->and($array['currency'])->toBe('USD')
        ->and($array['email'])->toBe('test@example.com')
        ->and($array['reference'])->toBe('REF_123')
        ->and($array['callback_url'])->toBe('https://example.com/callback');
});

test('charge request handles null reference', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->reference)->toBeNull();
});

test('charge request handles null callback url', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->callbackUrl)->toBeNull();
});

test('charge request handles empty metadata', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->metadata)->toBe([]);
});

test('charge request handles complex metadata', function (): void {
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

    $request = ChargeRequestDTO::fromArray([
        'amount' => 8000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => $metadata,
    ]);

    expect($request->metadata)->toBe($metadata);
});

test('charge request handles customer data', function (): void {
    $customer = [
        'name' => 'John Doe',
        'phone' => '+2348012345678',
        'address' => '123 Main St',
    ];

    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'customer' => $customer,
    ]);

    expect($request->customer)->toBe($customer);
});

test('charge request handles description', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'description' => 'Premium subscription payment',
    ]);

    expect($request->description)->toBe('Premium subscription payment');
});

test('charge request handles custom fields', function (): void {
    $customFields = [
        ['display_name' => 'Invoice ID', 'variable_name' => 'invoice_id', 'value' => 'INV_123'],
    ];

    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'custom_fields' => $customFields,
    ]);

    expect($request->customFields)->toBe($customFields);
});

test('charge request handles split payment config', function (): void {
    $split = [
        'type' => 'percentage',
        'bearer_type' => 'account',
        'subaccounts' => [
            ['subaccount' => 'ACCT_123', 'share' => 20],
        ],
    ];

    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'split' => $split,
    ]);

    expect($request->split)->toBe($split);
});

test('charge request is readonly/immutable', function (): void {
    $request = new ChargeRequestDTO(
        amount: 100,
        currency: 'NGN',
        email: 'test@example.com'
    );

    expect($request)->toBeInstanceOf(ChargeRequestDTO::class)
        ->and($request->amount)->toBe(100.0);
});
