<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use KenDeNigerian\PayZephyr\Services\ProviderDetector;

beforeEach(function () {
    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (Exception) {
        // Ignore if table doesn't exist
    }

    Schema::connection('testing')->create('payment_transactions', function ($table) {
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

    Cache::flush();
});

test('payment manager cacheSessionData stores session data', function () {
    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('cacheSessionData');

    $method->invoke($manager, 'ref_123', 'paystack', 'provider_id_123');

    $cached = Cache::get('payzephyr_session_ref_123');

    expect($cached)->toBeArray()
        ->and($cached['provider'])->toBe('paystack')
        ->and($cached['id'])->toBe('provider_id_123');
});

test('payment manager resolveVerificationContext uses cache first', function () {
    Cache::put('payzephyr_session_ref_123', [
        'provider' => 'paystack',
        'id' => 'provider_id_123',
    ], now()->addHour());

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'ref_123', null);

    expect($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('ref_123'); // Paystack uses reference, not cached id
});

test('payment manager resolveVerificationContext uses database when cache miss', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['_provider_id' => 'provider_id_123'],
    ]);

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'ref_123', null);

    expect($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('ref_123'); // Paystack uses reference, not database id
});

test('payment manager resolveVerificationContext uses provider detector when no cache or db', function () {
    config(['payments.logging.enabled' => false]);

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'PAYSTACK_ref_123', null);

    expect($result['provider'])->toBe('paystack')
        ->and($result['id'])->toBe('PAYSTACK_ref_123');
});

test('payment manager resolveVerificationContext uses explicit provider when provided', function () {
    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');

    $result = $method->invoke($manager, 'ref_123', 'stripe');

    expect($result['provider'])->toBe('stripe')
        ->and($result['id'])->toBe('ref_123');
});

test('payment manager updateTransactionFromVerification updates transaction successfully', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN',
        paidAt: '2024-01-01 12:00:00',
        channel: 'card'
    );

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $method->invoke($manager, 'ref_123', $response);

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('payment manager updateTransactionFromVerification handles failed payment', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'failed',
        amount: 1000.0,
        currency: 'NGN',
        channel: 'card'
    );

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $method->invoke($manager, 'ref_123', $response);

    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();

    expect($transaction->status)->toBe('failed')
        ->and($transaction->paid_at)->toBeNull();
});

test('payment manager updateTransactionFromVerification handles database error gracefully', function () {
    config(['payments.logging.enabled' => true]);

    $response = new VerificationResponseDTO(
        reference: 'nonexistent_ref',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN'
    );

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    // Should not throw exception
    $method->invoke($manager, 'nonexistent_ref', $response);

    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});

test('payment manager updateTransactionFromVerification skips when logging disabled', function () {
    config(['payments.logging.enabled' => false]);

    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN'
    );

    $manager = new PaymentManager(
        app(ProviderDetector::class),
        app(DriverFactory::class)
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    // Should not throw exception
    $method->invoke($manager, 'ref_123', $response);

    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});
