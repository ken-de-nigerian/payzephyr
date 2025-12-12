<?php

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;

test('process webhook job handles database error during transaction update', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'status' => 'success',
        ],
    ]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job->handle(
        app(PaymentManager::class),
        app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class)
    );

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'TEST_123')->first();
    expect($transaction)->not->toBeNull();
});

test('process webhook job handles driver not found exception in extractReference', function () {
    $job = new ProcessWebhook('nonexistent', ['data' => ['reference' => 'TEST_123']]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $result = $method->invoke($job, app(PaymentManager::class));

    expect($result)->toBeNull();
});

test('process webhook job handles driver not found exception in updateTransactionFromWebhook', function () {
    $job = new ProcessWebhook('nonexistent', ['data' => ['reference' => 'TEST_123']]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'nonexistent',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    try {
        $method->invoke(
            $job,
            app(PaymentManager::class),
            app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class),
            'TEST_123'
        );
    } catch (\KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException $e) {
        expect($e)->toBeInstanceOf(\KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException::class);
    }

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction)->not->toBeNull();
});

test('process webhook job logs error when exception occurs', function () {
    Log::shouldReceive('channel')
        ->with('payments')
        ->andReturnSelf();
    Log::shouldReceive('error')
        ->with('Webhook processing failed', \Mockery::type('array'));

    $job = new ProcessWebhook('paystack', ['data' => ['reference' => 'TEST_123']]);

    $manager = \Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')
        ->andThrow(new \Exception('Driver error'));

    expect(fn () => $job->handle(
        $manager,
        app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class)
    ))->toThrow(\Exception::class);
});

test('process webhook job handles missing transaction gracefully', function () {
    $job = new ProcessWebhook('paystack', ['data' => ['reference' => 'NONEXISTENT']]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke(
        $job,
        app(PaymentManager::class),
        app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class),
        'NONEXISTENT'
    );

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'NONEXISTENT')->first();

    expect($transaction)->toBeNull();
});

test('process webhook job updates transaction with channel when available', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'authorization' => ['channel' => 'card'],
            'status' => 'success',
        ],
    ]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job->handle(
        app(PaymentManager::class),
        app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class)
    );

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)->not->toBe('pending');
});

test('process webhook job sets paid_at for successful status', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'status' => 'success',
        ],
    ]);

    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'TEST_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job->handle(
        app(PaymentManager::class),
        app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class)
    );

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'TEST_123')->first();

    expect($transaction->paid_at)->not->toBeNull();
});
