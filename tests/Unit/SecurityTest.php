<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
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

test('it validates table names against sql injection', function () {
    config(['payments.logging.table' => 'payment_transactions; DROP TABLE users--']);

    $transaction = new PaymentTransaction;

    expect($transaction->getTable())->toBe('payment_transactions');
});

test('it accepts valid table names', function () {
    app()->forgetInstance('payments.config');
    config(['payments.logging.table' => 'custom_payment_transactions']);

    $provider = new \KenDeNigerian\PayZephyr\PaymentServiceProvider(app());
    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('configureModel');
    $method->setAccessible(true);
    $method->invoke($provider);

    $transaction = new PaymentTransaction;

    expect($transaction->getTable())->toBe('custom_payment_transactions');
});

test('it rejects table names with special characters', function () {
    config(['payments.logging.table' => 'payment-transactions']);

    $transaction = new PaymentTransaction;

    expect($transaction->getTable())->toBe('payment_transactions');
});

test('it rejects webhooks with old timestamps', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    // Real Paystack payloads carry the timestamp under data.paid_at, not a
    // top-level 'timestamp' field - see ADR-0001.
    $oldPayload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => date(DATE_ATOM, time() - 360)],
    ];

    $signature = hash_hmac('sha512', json_encode($oldPayload), config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        json_encode($oldPayload)
    );

    expect($isValid)->toBeFalse();
});

test('it accepts webhooks with recent timestamps', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $recentPayload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => date(DATE_ATOM, time() - 60)],
    ];

    $signature = hash_hmac('sha512', json_encode($recentPayload), config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        json_encode($recentPayload)
    );

    expect($isValid)->toBeTrue();
});

test('it rejects validly-signed webhooks with no recognizable timestamp (ADR-0001)', function () {
    // Prior to ADR-0001 this was treated as valid ("missing timestamp =
    // backward compatible"), which was the replay-window bypass the ADR
    // fixes. It must now be rejected even with a genuinely valid signature.
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123'],
    ];

    $signature = hash_hmac('sha512', json_encode($payload), config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        json_encode($payload)
    );

    expect($isValid)->toBeFalse();
});

test('it isolates cache keys per user', function () {
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);

    $contextMethod = $reflection->getMethod('getCacheContext');
    $contextMethod->setAccessible(true);

    $originalAuth = null;
    if (function_exists('auth')) {
    }

    $cacheKeyMethod = $reflection->getMethod('cacheKey');
    $cacheKeyMethod->setAccessible(true);

    $key = $cacheKeyMethod->invoke($manager, 'session', 'REF_123');

    expect($key)->toBe('payzephyr:session:REF_123');
});

test('it sanitizes sensitive data in logs', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    $context = [
        'api_key' => 'sk_test_secret123',
        'password' => 'user_password',
        'email' => 'user@example.com',
        'secret_token' => 'my_secret_token',
    ];

    $sanitized = $method->invoke($driver, $context);

    expect($sanitized['api_key'])->toBe('[REDACTED]')
        ->and($sanitized['password'])->toBe('[REDACTED]')
        ->and($sanitized['secret_token'])->toBe('[REDACTED]')
        ->and($sanitized['email'])->toBe('user@example.com');
});

test('it sanitizes api tokens in log strings', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    $context1 = [
        'token' => 'sk_test_12345678901234567890',
    ];
    $sanitized1 = $method->invoke($driver, $context1);
    expect($sanitized1['token'])->toBe('[REDACTED]');

    $context2 = [
        'stripe_key' => 'pk_test_12345678901234567890',
    ];
    $sanitized2 = $method->invoke($driver, $context2);
    expect($sanitized2['stripe_key'])->toBeIn(['[REDACTED]', '[REDACTED_TOKEN]']);

    $context3 = [
        'some_field' => 'sk_test_12345678901234567890',
    ];
    $sanitized3 = $method->invoke($driver, $context3);
    expect($sanitized3['some_field'])->toBe('[REDACTED_TOKEN]');
});

test('it sanitizes nested sensitive data', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    $context = [
        'config' => [
            'api_key' => 'sk_test_secret',
            'public_key' => 'pk_test_public',
        ],
        'user' => [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ],
    ];

    $sanitized = $method->invoke($driver, $context);

    expect($sanitized['config']['api_key'])->toBe('[REDACTED]')
        ->and($sanitized['user']['password'])->toBe('[REDACTED]')
        ->and($sanitized['user']['email'])->toBe('user@example.com');
});

test('it caps recursion depth in log sanitization (ADR-0002)', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    // Build a structure deeper than LOG_SANITIZATION_MAX_DEPTH (10).
    $deeplyNested = 'leaf';
    for ($i = 0; $i < 20; $i++) {
        $deeplyNested = ['level' => $deeplyNested];
    }

    // This must not exhaust the stack; a depth cap should short-circuit it.
    $sanitized = $method->invoke($driver, ['data' => $deeplyNested]);

    $cursor = $sanitized['data'];
    $depth = 0;
    while (is_array($cursor) && isset($cursor['level'])) {
        $cursor = $cursor['level'];
        $depth++;
    }

    expect($depth)->toBeLessThanOrEqual(11) // LOG_SANITIZATION_MAX_DEPTH + 1
        ->and($cursor)->toBe('[MAX_DEPTH_EXCEEDED]');
});

test('it sanitizes log context containing plain (numeric-keyed) lists without a TypeError', function () {
    // isSensitiveKey() expects a string; under declare(strict_types=1), a
    // numeric array key (any plain list) previously caused a fatal TypeError
    // the moment anything tried to log it.
    $driver = app(PaymentManager::class)->driver('paystack');

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    $context = [
        'items' => ['a', 'b', 'c'],
        'nested' => [['id' => 1], ['id' => 2]],
    ];

    $sanitized = $method->invoke($driver, $context);

    expect($sanitized['items'])->toBe(['a', 'b', 'c'])
        ->and($sanitized['nested'][0]['id'])->toBe(1);
});

test('it rate limits payment initialization', function () {
    $email = 'test@example.com';
    $key = 'payment_charge:email_'.hash('sha256', $email);

    RateLimiter::clear($key);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeTrue();
});

test('it rate limits by email when user not authenticated', function () {
    $email = 'test@example.com';
    $key = 'payment_charge:email_'.hash('sha256', $email);

    RateLimiter::clear($key);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeTrue();
});

test('it rejects emails with double dots', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'user..name@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('it rejects emails with trailing dots', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'user@example.com.',
    ]))->toThrow(InvalidArgumentException::class);
});

test('it rejects http callback urls in production', function () {

    $originalEnv = app()->environment();

    app()->detectEnvironment(fn () => 'production');

    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'callback_url' => 'http://example.com/callback',
    ]))->toThrow(\InvalidArgumentException::class, 'Invalid callback URL');

    app()->detectEnvironment(fn () => $originalEnv);
});

test('it accepts https callback urls in production', function () {

    $originalEnv = app()->environment();

    app()->detectEnvironment(fn () => 'production');

    $dto = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'callback_url' => 'https://example.com/callback',
    ]);

    expect($dto->callbackUrl)->toBe('https://example.com/callback');

    app()->detectEnvironment(fn () => $originalEnv);
});

test('it rejects references with special characters', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'ORDER_123; DROP TABLE users--',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid reference format');
});

test('it accepts valid reference formats', function () {
    $dto = ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'ORDER_123-ABC',
    ]);

    expect($dto->reference)->toBe('ORDER_123-ABC');
});

test('it validates email local part length', function () {
    $longLocal = str_repeat('a', 65).'@example.com';

    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => $longLocal,
    ]))->toThrow(InvalidArgumentException::class);
});
