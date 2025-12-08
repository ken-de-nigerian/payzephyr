<?php

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager cacheSessionData stores session data', function () {
    Cache::flush();

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('cacheSessionData');

    $method->invoke($manager, 'test_ref_123', 'paystack', 'provider_id_123');

    $cached = Cache::get('payzephyr_session_test_ref_123');

    expect($cached)->toBeArray()
        ->and($cached['provider'])->toBe('paystack')
        ->and($cached['id'])->toBe('provider_id_123');
});

test('payment manager resolveVerificationContext uses cache first', function () {
    Cache::flush();
    Cache::put('payzephyr_session_test_ref', ['provider' => 'paystack', 'id' => 'provider_id'], now()->addHour());

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'test_ref', null);

    expect($result)->toBeArray()
        ->and($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('provider_id');
});

test('payment manager resolveVerificationContext uses database second', function () {
    Cache::flush();

    PaymentTransaction::create([
        'reference' => 'db_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'provider_id_from_db'],
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'db_ref_123', null);

    expect($result)->toBeArray()
        ->and($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('provider_id_from_db');
});

test('payment manager resolveVerificationContext uses provider detector third', function () {
    Cache::flush();

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'PAYSTACK_ref_123', null);

    expect($result)->toBeArray()
        ->and($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('PAYSTACK_ref_123');
});

test('payment manager resolveVerificationContext uses explicit provider', function () {
    Cache::flush();

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'ref_123', 'paystack');

    expect($result)->toBeArray()
        ->and($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('ref_123');
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
        'metadata' => ['session_id' => 'stripe_session_123'],
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'session_ref', null);

    expect($result['id'])->toBe('stripe_session_123');
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
        'metadata' => ['order_id' => 'paypal_order_123'],
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'order_ref', null);

    expect($result['id'])->toBe('paypal_order_123');
});

test('payment manager updateTransactionFromVerification updates transaction', function () {
    PaymentTransaction::create([
        'reference' => 'verify_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $response = new VerificationResponseDTO(
        reference: 'verify_ref',
        status: 'success',
        amount: 10000,
        currency: 'NGN',
        paidAt: '2024-01-01 12:00:00',
        channel: 'card',
    );

    $method->invoke($manager, 'verify_ref', $response);

    $transaction = PaymentTransaction::where('reference', 'verify_ref')->first();

    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('payment manager updateTransactionFromVerification handles failed payment', function () {
    PaymentTransaction::create([
        'reference' => 'failed_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $response = new VerificationResponseDTO(
        reference: 'failed_ref',
        status: 'failed',
        amount: 10000,
        currency: 'NGN',
    );

    $method->invoke($manager, 'failed_ref', $response);

    $transaction = PaymentTransaction::where('reference', 'failed_ref')->first();

    expect($transaction->status)->toBe('failed')
        ->and($transaction->paid_at)->toBeNull();
});

test('payment manager updateTransactionFromVerification skips when logging disabled', function () {
    config(['payments.logging.enabled' => false]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $response = new VerificationResponseDTO(
        reference: 'skip_ref',
        status: 'success',
        amount: 10000,
        currency: 'NGN',
    );

    // Should not throw
    $method->invoke($manager, 'skip_ref', $response);

    expect(true)->toBeTrue(); // Verify it doesn't throw
});

test('payment manager updateTransactionFromVerification uses now when paidAt is null', function () {
    PaymentTransaction::create([
        'reference' => 'now_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $response = new VerificationResponseDTO(
        reference: 'now_ref',
        status: 'success',
        amount: 10000,
        currency: 'NGN',
        paidAt: null, // No paidAt provided
    );

    $method->invoke($manager, 'now_ref', $response);

    $transaction = PaymentTransaction::where('reference', 'now_ref')->first();

    expect($transaction->paid_at)->not->toBeNull();
});
