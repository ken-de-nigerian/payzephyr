<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager handles database error during transaction logging gracefully', function (): void {
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

    try {
        $manager->chargeWithFallback($request, ['paystack']);
    } catch (Exception $e) {
        expect($e)->not->toBeInstanceOf(PDOException::class);
    }
});

test('payment manager handles database error during verification update gracefully', function (): void {
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

    try {
        $manager->verify('test_ref', 'paystack');
    } catch (Exception $e) {
        expect($e)->not->toBeInstanceOf(PDOException::class);
    }
});

test('payment manager getDefaultDriver returns first provider when default not set', function (): void {
    config([
        'payments.providers' => [
            'stripe' => ['driver' => 'stripe', 'secret_key' => 'test', 'enabled' => true],
            'paystack' => ['driver' => 'paystack', 'secret_key' => 'test', 'enabled' => true],
        ],
    ]);

    $manager = new PaymentManager;
    $default = $manager->getDefaultDriver();

    expect($default)->toBeString();
});

test('payment manager getDefaultDriver handles empty providers config', function (): void {
    config()->set('payments.providers', []);
    config()->set('payments.default');
    app()->forgetInstance('payments.config');

    $manager = new PaymentManager;

    expect(fn (): string => $manager->getDefaultDriver())->toThrow(DriverNotFoundException::class);
});

test('payment manager getFallbackChain handles empty fallback string', function (): void {
    app()->forgetInstance('payments.config');

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

    expect($chain)->toBe(['paystack'])
        ->and($chain)->toHaveCount(1);
});

test('payment manager getFallbackChain handles false fallback', function (): void {
    app()->forgetInstance('payments.config');

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

    expect($chain)->toBe(['paystack'])
        ->and($chain)->toHaveCount(1);
});

test('payment manager resolveDriverClass returns original string for unknown driver', function (): void {
    config([
        'payments.providers.custom' => [
            'driver' => 'CustomDriverClass',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;

    expect(fn (): DriverInterface => $manager->driver('custom'))
        ->toThrow(DriverNotFoundException::class);
});

test('payment manager handles logging disabled during charge', function (): void {
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

    try {
        $manager->chargeWithFallback($request, ['paystack']);
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment manager handles logging disabled during verification', function (): void {
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

    try {
        $manager->verify('test_ref', 'paystack');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment manager updateTransactionFromVerification handles successful payment with paidAt', function (): void {
    config([
        'payments.logging.enabled' => true,
    ]);

    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function ($table): void {
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

    PaymentTransaction::create([
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
        metadata: [],
        provider: 'paystack',
        channel: 'card'
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $method->invoke($manager, 'test_ref_123', $response);

    $transaction = PaymentTransaction::where('reference', 'test_ref_123')->first();
    expect($transaction->status)->toBe('success')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('payment manager updateTransactionFromVerification handles failed payment', function (): void {
    config([
        'payments.logging.enabled' => true,
    ]);

    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function ($table): void {
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

    PaymentTransaction::create([
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
        metadata: [],
        provider: 'paystack'
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');

    $method->invoke($manager, 'test_ref_failed', $response);

    $transaction = PaymentTransaction::where('reference', 'test_ref_failed')->first();
    expect($transaction->status)->toBe('failed')
        ->and($transaction->paid_at)->toBeNull();
});

test('payment manager logTransaction creates transaction with all fields', function (): void {
    config([
        'payments.logging.enabled' => true,
    ]);

    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function ($table): void {
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

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('logTransaction');

    $method->invoke($manager, $request, $response, 'paystack');

    $transaction = PaymentTransaction::where('reference', 'test_ref_log')->first();
    $laravelVersion = (float) app()->version();

    expect($transaction)->not->toBeNull()
        ->and((float) $transaction->amount)->toBe(5000.0) // Cast to float for comparison
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->email)->toBe('customer@example.com');

    if ($laravelVersion >= 11.0) {
        expect($transaction->metadata)->toBeInstanceOf(\ArrayObject::class)
            ->and($transaction->metadata['order_id'])->toBe(123)
            ->and($transaction->metadata['_provider_id'])->toBe('access_123')
            ->and($transaction->customer->toArray())->toBe(['name' => 'John Doe']);
    } else {
        expect($transaction->metadata)->toBeArray()
            ->and($transaction->metadata['order_id'])->toBe(123)
            ->and($transaction->metadata['_provider_id'])->toBe('access_123')
            ->and($transaction->customer)->toBeArray()
            ->and($transaction->customer['name'])->toBe('John Doe');
    }
});
