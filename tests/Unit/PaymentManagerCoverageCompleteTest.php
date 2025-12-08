<?php

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager caches session data', function () {
    Cache::flush();

    $manager = app(PaymentManager::class);

    // Use reflection to call a protected method
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('cacheSessionData');

    $method->invoke($manager, 'test_ref', 'paystack', 'provider_id_123');

    $cached = Cache::get('payzephyr_session_test_ref');

    expect($cached)->toBe([
        'provider' => 'paystack',
        'id' => 'provider_id_123',
    ]);
});

test('payment manager resolveVerificationContext uses cache first', function () {
    Cache::flush();
    Cache::put('payzephyr_session_test_ref', [
        'provider' => 'paystack',
        'id' => 'provider_id_123',
    ], now()->addHour());

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'test_ref', null);

    expect($result)->toBe([
        'provider' => 'paystack',
        'id' => 'provider_id_123',
    ]);
});

test('payment manager resolveVerificationContext uses database when cache miss', function () {
    Cache::flush();

    PaymentTransaction::create([
        'reference' => 'db_ref',
        'provider' => 'flutterwave',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'flw_id_123'],
    ]);

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'db_ref', null);

    expect($result)->toBe([
        'provider' => 'flutterwave',
        'id' => 'flw_id_123',
    ]);
});

test('payment manager resolveVerificationContext uses session_id from metadata', function () {
    Cache::flush();

    PaymentTransaction::create([
        'reference' => 'session_ref',
        'provider' => 'stripe',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'metadata' => ['session_id' => 'cs_test_123'],
    ]);

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'session_ref', null);

    expect($result)->toBe([
        'provider' => 'stripe',
        'id' => 'cs_test_123',
    ]);
});

test('payment manager resolveVerificationContext uses order_id from metadata', function () {
    Cache::flush();

    PaymentTransaction::create([
        'reference' => 'order_ref',
        'provider' => 'paypal',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'metadata' => ['order_id' => 'ORDER_123'],
    ]);

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'order_ref', null);

    expect($result)->toBe([
        'provider' => 'paypal',
        'id' => 'ORDER_123',
    ]);
});

test('payment manager resolveVerificationContext falls back to reference when no metadata', function () {
    Cache::flush();

    PaymentTransaction::create([
        'reference' => 'fallback_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => [],
    ]);

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'fallback_ref', null);

    expect($result)->toBe([
        'provider' => 'paystack',
        'id' => 'fallback_ref',
    ]);
});

test('payment manager resolveVerificationContext uses explicit provider when provided', function () {
    Cache::flush();

    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    

    $result = $method->invoke($manager, 'any_ref', 'stripe');

    expect($result)->toBe([
        'provider' => 'stripe',
        'id' => 'any_ref',
    ]);
});

test('payment manager detectProviderFromReference delegates to ProviderDetector', function () {
    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('detectProviderFromReference');
    

    expect($method->invoke($manager, 'PAYSTACK_ref_123'))->toBe('paystack')
        ->and($method->invoke($manager, 'FLW_ref_123'))->toBe('flutterwave')
        ->and($method->invoke($manager, 'unknown_ref'))->toBeNull();
});

test('payment manager updateTransactionFromVerification handles successful payment', function () {
    $transaction = PaymentTransaction::create([
        'reference' => 'verify_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = app(PaymentManager::class);

    $response = new VerificationResponseDTO(
        reference: 'verify_ref',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN',
        paidAt: '2024-01-01 12:00:00',
        channel: 'card',
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    

    $method->invoke($manager, 'verify_ref', $response);

    $transaction->refresh();

    expect($transaction->status)->toBe('success')
        ->and($transaction->paid_at)->not->toBeNull()
        ->and($transaction->channel)->toBe('card');
});

test('payment manager updateTransactionFromVerification handles failed payment', function () {
    $transaction = PaymentTransaction::create([
        'reference' => 'failed_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = app(PaymentManager::class);

    $response = new VerificationResponseDTO(
        reference: 'failed_ref',
        status: 'failed',
        amount: 1000.0,
        currency: 'NGN',
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    

    $method->invoke($manager, 'failed_ref', $response);

    $transaction->refresh();

    expect($transaction->status)->toBe('failed')
        ->and($transaction->paid_at)->toBeNull();
});
