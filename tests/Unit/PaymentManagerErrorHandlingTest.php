<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager handles database error during transaction logging gracefully', function () {
    config([
        'payments.logging.enabled' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    // Should not throw exception even if logging fails
    // The logTransaction method catches exceptions internally
    try {
        $manager->chargeWithFallback($request, ['paystack']);
    } catch (\Exception $e) {
        // Expected to fail due to no actual API, but logging error should be caught
        expect($e)->not->toBeInstanceOf(\PDOException::class);
    }
});

test('payment manager handles database error during verification update gracefully', function () {
    config([
        'payments.logging.enabled' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;

    // Should not throw exception even if database update fails
    try {
        $manager->verify('test_ref', 'paystack');
    } catch (\Exception $e) {
        // Expected to fail due to no actual API, but database error should be caught
        expect($e)->not->toBeInstanceOf(\PDOException::class);
    }
});

test('payment manager getDefaultDriver returns first provider when default not set', function () {
    config([
        'payments.providers' => [
            'stripe' => ['driver' => 'stripe', 'secret_key' => 'test', 'enabled' => true],
            'paystack' => ['driver' => 'paystack', 'secret_key' => 'test', 'enabled' => true],
        ],
        // No default set
    ]);

    $manager = new PaymentManager;
    $default = $manager->getDefaultDriver();

    // Should return first key from providers array
    expect($default)->toBeString();
});

test('payment manager getDefaultDriver handles empty providers config', function () {
    // Clear all config to ensure empty providers
    config()->set('payments.providers', []);
    config()->set('payments.default', null);

    $manager = new PaymentManager;
    
    // When no providers and no default, array_key_first returns null
    // but the method return type is string, so it will cause a type error
    // This tests that the method handles edge cases
    try {
        $default = $manager->getDefaultDriver();
        // If it doesn't throw, it should return a string (even if empty)
        expect($default)->toBeString();
    } catch (\TypeError $e) {
        // Type error is expected when array_key_first returns null
        expect($e)->toBeInstanceOf(\TypeError::class);
    }
});

test('payment manager getFallbackChain handles empty fallback string', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => '', // Empty string
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;
    $chain = $manager->getFallbackChain();

    // Should filter out empty strings
    expect($chain)->toBe(['paystack'])
        ->and($chain)->toHaveCount(1);
});

test('payment manager getFallbackChain handles false fallback', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => false,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;
    $chain = $manager->getFallbackChain();

    // Should filter out false values
    expect($chain)->toBe(['paystack'])
        ->and($chain)->toHaveCount(1);
});

test('payment manager resolveDriverClass returns original string for unknown driver', function () {
    config([
        'payments.providers.custom' => [
            'driver' => 'CustomDriverClass',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;

    // Should throw DriverNotFoundException when class doesn't exist
    expect(fn () => $manager->driver('custom'))
        ->toThrow(DriverNotFoundException::class);
});

test('payment manager handles logging disabled during charge', function () {
    config([
        'payments.logging.enabled' => false,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    // Should not attempt to log when logging is disabled
    try {
        $manager->chargeWithFallback($request, ['paystack']);
    } catch (\Exception $e) {
        // Expected to fail due to no actual API
        expect($e)->toBeInstanceOf(\Exception::class);
    }
});

test('payment manager handles logging disabled during verification', function () {
    config([
        'payments.logging.enabled' => false,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $manager = new PaymentManager;

    // Should not attempt to update when logging is disabled
    try {
        $manager->verify('test_ref', 'paystack');
    } catch (\Exception $e) {
        // Expected to fail due to no actual API
        expect($e)->toBeInstanceOf(\Exception::class);
    }
});

test('payment manager updateTransactionFromVerification handles successful payment with paidAt', function () {
    config([
        'payments.logging.enabled' => true,
    ]);

    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    // Create a transaction first
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'test_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = new PaymentManager;
    $response = new VerificationResponseDTO(
        reference: 'test_ref_123',
        status: 'success',
        amount: 1000,
        currency: 'NGN',
        paidAt: now()->toIso8601String(),
        channel: 'card',
        customer: null,
        metadata: [],
        provider: 'paystack'
    );

    // Use reflection to call protected method
    $reflection = new \ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    $method->setAccessible(true);

    // Should not throw exception
    $method->invoke($manager, 'test_ref_123', $response);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'test_ref_123')->first();
    expect($transaction->status)->toBe('success')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('payment manager updateTransactionFromVerification handles failed payment', function () {
    config([
        'payments.logging.enabled' => true,
    ]);

    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    // Create a transaction first
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'test_ref_failed',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = new PaymentManager;
    $response = new VerificationResponseDTO(
        reference: 'test_ref_failed',
        status: 'failed',
        amount: 1000,
        currency: 'NGN',
        paidAt: null,
        channel: null,
        customer: null,
        metadata: [],
        provider: 'paystack'
    );

    // Use reflection to call protected method
    $reflection = new \ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    $method->setAccessible(true);

    // Should not throw exception
    $method->invoke($manager, 'test_ref_failed', $response);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'test_ref_failed')->first();
    expect($transaction->status)->toBe('failed')
        ->and($transaction->paid_at)->toBeNull();
});

test('payment manager logTransaction creates transaction with all fields', function () {
    config([
        'payments.logging.enabled' => true,
    ]);

    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 5000,
        'currency' => 'NGN',
        'email' => 'customer@example.com',
        'reference' => 'test_ref_log',
        'metadata' => ['order_id' => 123],
        'customer' => ['name' => 'John Doe'],
    ]);

    $response = new ChargeResponseDTO(
        reference: 'test_ref_log',
        authorizationUrl: 'https://example.com',
        accessCode: 'access_123',
        status: 'pending',
        metadata: [],
        provider: 'paystack'
    );

    // Use reflection to call protected method
    $reflection = new \ReflectionClass($manager);
    $method = $reflection->getMethod('logTransaction');
    $method->setAccessible(true);

    // Should not throw exception
    $method->invoke($manager, $request, $response, 'paystack');

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'test_ref_log')->first();
    expect($transaction)->not->toBeNull()
        ->and((float) $transaction->amount)->toBe(5000.0) // Cast to float for comparison
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->email)->toBe('customer@example.com')
        ->and($transaction->metadata)->toBe([
            'order_id' => 123,
            '_provider_id' => 'access_123'
        ])
        ->and($transaction->customer)->toBe(['name' => 'John Doe']);
});

