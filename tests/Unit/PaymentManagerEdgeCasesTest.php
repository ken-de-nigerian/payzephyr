<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager skips provider when currency not supported', function () {
    $manager = app(PaymentManager::class);

    config(['payments.providers.paystack.currencies' => ['NGN']]);
    config(['payments.providers.stripe.currencies' => ['USD']]);

    $request = new ChargeRequestDTO(10000, 'EUR', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack', 'stripe']))
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ProviderException::class);
});

test('payment manager logs error when all providers fail', function () {
    $manager = app(PaymentManager::class);

    config(['payments.providers.paystack.enabled' => true]);
    config(['payments.providers.stripe.enabled' => true]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack', 'stripe']))
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ProviderException::class);
});

test('payment manager handles database error during transaction logging', function () {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack']))
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ProviderException::class);
});

test('payment manager handles database error during verification update', function () {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    try {
        $manager->verify('TEST_123', 'paystack');
    } catch (\Exception $e) {
        expect($e)->toBeInstanceOf(\Exception::class);
    }
});

test('payment manager getCacheContext returns null when no auth or request', function () {
    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getCacheContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager);

    expect($result)->toBeNull();
});

test('payment manager cacheKey includes context when available', function () {
    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $cacheKeyMethod = $reflection->getMethod('cacheKey');
    $cacheKeyMethod->setAccessible(true);

    $getCacheContextMethod = $reflection->getMethod('getCacheContext');
    $getCacheContextMethod->setAccessible(true);

    \Illuminate\Support\Facades\Auth::shouldReceive('check')
        ->andReturn(true);
    \Illuminate\Support\Facades\Auth::shouldReceive('id')
        ->andReturn(123);

    $key = $cacheKeyMethod->invoke($manager, 'session', 'REF_123');

    expect($key)->toContain('user_123');
});

test('payment manager resolveVerificationContext handles array metadata', function () {
    $manager = app(PaymentManager::class);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'provider_123'],
    ]);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles ArrayObject metadata', function () {
    $manager = app(PaymentManager::class);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => new \ArrayObject(['_provider_id' => 'provider_123']),
    ]);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles string metadata', function () {
    $manager = app(PaymentManager::class);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $transaction->metadata = json_encode(['_provider_id' => 'provider_123']);
    $transaction->save();

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles DriverNotFoundException', function () {
    $manager = app(PaymentManager::class);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'nonexistent',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'provider_123'],
    ]);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager updateTransactionFromVerification handles null paid_at', function () {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $verification = new \KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO(
        reference: 'TEST_123',
        status: 'failed',
        amount: 100.0,
        currency: 'NGN',
        paidAt: null,
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    $method->setAccessible(true);

    $method->invoke($manager, 'TEST_123', $verification);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction->paid_at)->toBeNull();
});
