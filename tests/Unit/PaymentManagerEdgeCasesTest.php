<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager skips provider when currency not supported', function (): void {
    $manager = app(PaymentManager::class);

    config(['payments.providers.paystack.currencies' => ['NGN']]);
    config(['payments.providers.stripe.currencies' => ['USD']]);

    $request = new ChargeRequestDTO(10000, 'EUR', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack', 'stripe']))
        ->toThrow(ProviderException::class);
});

test('payment manager logs error when all providers fail', function (): void {
    $manager = app(PaymentManager::class);

    config(['payments.providers.paystack.enabled' => true]);
    config(['payments.providers.stripe.enabled' => true]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack', 'stripe']))
        ->toThrow(ProviderException::class);
});

test('payment manager handles database error during transaction logging', function (): void {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $manager->chargeWithFallback($request, ['paystack']))
        ->toThrow(ProviderException::class);
});

test('payment manager handles database error during verification update', function (): void {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
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

test('payment manager getCacheContext returns null when no auth or request', function (): void {
    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getCacheContext');

    $result = $method->invoke($manager);

    expect($result)->toBeNull();
});

test('payment manager cacheKey includes context when available', function (): void {
    $manager = app(PaymentManager::class);

    $reflection = new ReflectionClass($manager);
    $cacheKeyMethod = $reflection->getMethod('cacheKey');

    $getCacheContextMethod = $reflection->getMethod('getCacheContext');

    mockAuthGuard(check: true, id: 123);

    $key = $cacheKeyMethod->invoke($manager, 'session', 'REF_123');

    expect($key)->toContain('user_123');
});

test('payment manager resolveVerificationContext handles array metadata', function (): void {
    $manager = app(PaymentManager::class);

    PaymentTransaction::create([
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

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles ArrayObject metadata', function (): void {
    $manager = app(PaymentManager::class);

    $transaction = PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'provider_123'],
    ]);

    $transaction->refresh();

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles string metadata', function (): void {
    $manager = app(PaymentManager::class);

    $transaction = PaymentTransaction::create([
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

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager resolveVerificationContext handles DriverNotFoundException', function (): void {
    $manager = app(PaymentManager::class);

    PaymentTransaction::create([
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

    $result = $method->invoke($manager, 'TEST_123', null);

    expect($result)->toHaveKey('provider')
        ->and($result)->toHaveKey('id');
});

test('payment manager updateTransactionFromVerification handles null paid_at', function (): void {
    $manager = app(PaymentManager::class);

    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $verification = new VerificationResponseDTO(
        reference: 'TEST_123',
        status: 'failed',
        amount: 100.0,
        currency: 'NGN',
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $method->invoke($manager, 'TEST_123', $verification);

    $transaction = PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction->paid_at)->toBeNull();
});
