<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance('payments.config');

    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');

    try {
        \Illuminate\Support\Facades\Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
    }

    \Illuminate\Support\Facades\Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference');
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 15, 2);
        $table->string('currency');
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
});

test('webhook controller determineStatus handles all provider status formats', function () {
    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $job = new ProcessWebhook('paystack', ['data' => ['status' => 'success']]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');

    $job = new ProcessWebhook('flutterwave', ['data' => ['status' => 'successful']]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('successful');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['flutterwave' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');

    $job = new ProcessWebhook('monnify', ['paymentStatus' => 'PAID']);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('PAID');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');

    $job = new ProcessWebhook('stripe', ['data' => ['object' => ['status' => 'succeeded']]]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('succeeded');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');

    $job = new ProcessWebhook('paypal', ['resource' => ['status' => 'COMPLETED']]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');

    $job = new ProcessWebhook('unknown', []);
    $manager = app(PaymentManager::class);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('unknown');
});

test('webhook controller updateTransactionFromWebhook updates with channel', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paystack', [
        'data' => ['status' => 'success', 'channel' => 'card'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'ref_123');

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('webhook controller updateTransactionFromWebhook handles database error gracefully', function () {
    config(['payments.logging.enabled' => true]);

    $job = new ProcessWebhook('paystack', [
        'data' => ['status' => 'success'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn(null);
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'nonexistent_ref');

    expect(true)->toBeTrue();
});

test('webhook controller updateTransactionFromWebhook handles different provider channels', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'flutterwave',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('flutterwave', [
        'data' => ['status' => 'successful', 'payment_type' => 'card'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('successful');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['flutterwave' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'ref_123');

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->channel)->toBe('card');
});

test('webhook controller updateTransactionFromWebhook handles monnify channel', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'monnify',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('monnify', [
        'paymentStatus' => 'PAID',
        'paymentMethod' => 'CARD',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('PAID');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('CARD');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'ref_123');

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->channel)->toBe('CARD');
});

test('webhook controller updateTransactionFromWebhook handles stripe channel', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'stripe',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('stripe', [
        'data' => [
            'object' => [
                'status' => 'succeeded',
                'payment_method' => 'card',
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('succeeded');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'ref_123');

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->channel)->toBe('card');
});

test('webhook controller updateTransactionFromWebhook handles paypal channel', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paypal',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paypal', [
        'resource' => ['status' => 'COMPLETED'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('paypal');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'ref_123');

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->channel)->toBe('paypal');
});
