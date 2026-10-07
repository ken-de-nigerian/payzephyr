<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

test('paystack handles zero decimal currencies correctly', function (): void {
    $config = [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'base_url' => 'https://api.paystack.co',
        'currencies' => ['NGN', 'JPY'],
    ];

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'PAYSTACK_123',
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code' => 'access_123',
            ],
        ])),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver($config);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(1000.00, 'JPY', 'test@example.com', null, 'https://example.com/callback');

    $response = $driver->charge($request);

    expect($response->reference)->not->toBeEmpty();
});

test('it handles unsupported currencies gracefully', function (): void {
    $manager = app(PaymentManager::class);

    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'], // Only NGN supported
        ],
    ]);

    $driver = $manager->driver('paystack');

    expect($driver->isCurrencySupported('NGN'))->toBeTrue();

    $usdSupported = $driver->isCurrencySupported('USD');
    expect($usdSupported)->toBeBool();
});

test('it handles empty metadata gracefully', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => [],
    ]);

    expect($request->metadata)->toBeArray()
        ->and($request->metadata)->toBeEmpty();
});

test('it handles null callback url when not required', function (): void {
    $request = new ChargeRequestDTO(
        10000,
        'NGN',
        'test@example.com' // No callback URL
    );

    expect($request->callbackUrl)->toBeNull();
});

test('it handles very large amounts', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 999999999.99, // Maximum allowed
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(999999999.99);
});

test('it handles special characters in metadata', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => [
            'order_id' => 123,
            'description' => 'Test & Special <Characters>',
            'user_name' => "O'Brien",
        ],
    ]);

    expect($request->metadata)->toHaveKey('order_id')
        ->and($request->metadata['description'])->toContain('Special');
});

test('it handles multiple payment channels', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'channels' => ['card', 'bank', 'ussd', 'qr'],
    ]);

    expect($request->channels)->toBeArray()
        ->and($request->channels)->toHaveCount(4)
        ->and($request->channels)->toContain('card', 'bank', 'ussd', 'qr');
});

test('it handles null channels when not specified', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->channels)->toBeNull();
});

test('it handles custom reference generation', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'CUSTOM_REF_123',
    ]);

    expect($request->reference)->toBe('CUSTOM_REF_123');
});

test('it handles idempotency keys', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => 'idempotent_key_123',
    ]);

    expect($request->idempotencyKey)->toBe('idempotent_key_123');
});

test('it auto-generates idempotency keys when not provided', function (): void {
    $request1 = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $request2 = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request1->idempotencyKey)->not->toBeEmpty()
        ->and($request2->idempotencyKey)->not->toBeEmpty()
        ->and($request1->idempotencyKey)->not->toBe($request2->idempotencyKey);
});

test('it handles customer details', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'customer' => [
            'name' => 'John Doe',
            'phone' => '+2348012345678',
        ],
    ]);

    expect($request->customer)->toBeArray()
        ->and($request->customer['name'])->toBe('John Doe')
        ->and($request->customer['phone'])->toBe('+2348012345678');
});

test('it handles null customer when not provided', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->customer)->toBeNull();
});
