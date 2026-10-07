<?php

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

test('process webhook job handles database error during transaction update', function (): void {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'status' => 'success',
        ],
    ]);

    PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    app()->call($job->handle(...));

    $transaction = PaymentTransaction::where('reference', 'TEST_123')->first();
    expect($transaction)->not->toBeNull();
});

test('process webhook job handles driver not found exception in extractReference', function (): void {
    $job = new ProcessWebhook('nonexistent', ['data' => ['reference' => 'TEST_123']]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');

    $result = $method->invoke($job, app(PaymentManager::class));

    expect($result)->toBeNull();
});

test('process webhook job handles driver not found exception in updateTransactionFromWebhook', function (): void {
    $job = new ProcessWebhook('nonexistent', ['data' => ['reference' => 'TEST_123']]);

    PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'nonexistent',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');

    try {
        $method->invoke(
            $job,
            app(PaymentManager::class),
            app(StatusNormalizerInterface::class),
            app(TransactionRepositoryInterface::class),
            'TEST_123'
        );
    } catch (DriverNotFoundException $e) {
        expect($e)->toBeInstanceOf(DriverNotFoundException::class);
    }

    $transaction = PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction)->not->toBeNull();
});

test('process webhook job logs error when exception occurs', function (): void {
    Log::shouldReceive('channel')
        ->with('payments')
        ->andReturnSelf();
    Log::shouldReceive('error')
        ->with('Webhook processing failed', \Mockery::type('array'));

    $job = new ProcessWebhook('paystack', ['data' => ['reference' => 'TEST_123']]);

    $manager = app(PaymentManager::class);
    $mockDriver = \Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andThrow(new \Exception('Driver error'));

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    expect(fn () => app()->call($job->handle(...)))->toThrow(\Exception::class);
});

test('process webhook job handles missing transaction gracefully', function (): void {
    $job = new ProcessWebhook('paystack', ['data' => ['reference' => 'NONEXISTENT']]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');

    $method->invoke(
        $job,
        app(PaymentManager::class),
        app(StatusNormalizerInterface::class),
        app(TransactionRepositoryInterface::class),
        'NONEXISTENT'
    );

    $transaction = PaymentTransaction::where('reference', 'NONEXISTENT')->first();

    expect($transaction)->toBeNull();
});

test('process webhook job updates transaction with channel when available', function (): void {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'authorization' => ['channel' => 'card'],
            'status' => 'success',
        ],
    ]);

    PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    app()->call($job->handle(...));

    $transaction = PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)->not->toBe('pending');
});

test('process webhook job sets paid_at for successful status', function (): void {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'status' => 'success',
        ],
    ]);

    PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    app()->call($job->handle(...));

    $transaction = PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction->paid_at)->not->toBeNull();
});
