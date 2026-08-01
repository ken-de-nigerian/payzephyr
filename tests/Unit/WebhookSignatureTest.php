<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

// Payload shapes below match each provider's real webhook envelope
// (verified against provider docs, see ADR-0001), not a fabricated flat
// 'timestamp' field. This matters: extractWebhookTimestamp() now fails
// closed when it can't find a timestamp, so a test using a fake shape would
// either false-pass (bypassing the check entirely) or false-fail.

test('it validates paystack webhook signature correctly', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => now()->toIso8601String()],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects paystack webhook with invalid signature', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => now()->toIso8601String()],
    ];

    $body = json_encode($payload);

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => ['invalid_signature']],
        $body
    );

    expect($isValid)->toBeFalse();
});

test('it rejects paystack webhook with missing signature', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123'],
    ];

    $body = json_encode($payload);

    $isValid = $driver->validateWebhook([], $body);

    expect($isValid)->toBeFalse();
});

test('it rejects a validly-signed paystack webhook with no recognizable timestamp (ADR-0001)', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    // A top-level 'timestamp' field does NOT count for Paystack - real
    // Paystack payloads carry it under 'data'. Before ADR-0001 this used to
    // silently pass (the "missing timestamp = valid" bypass); it must now
    // be rejected even though the signature itself is genuinely valid.
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123'], // no paid_at / created_at
        'timestamp' => time(), // present, but Paystack never sends it here
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeFalse();
});

test('it validates flutterwave webhook signature correctly', function () {
    $config = [
        'secret_key' => 'FLW_SECRET_KEY',
        'webhook_secret' => 'FLW_WEBHOOK_SECRET',
        'currencies' => ['NGN'],
    ];

    $driver = new FlutterwaveDriver($config);

    $body = json_encode([
        'event' => 'charge.completed',
        'data' => ['id' => 123, 'created_at' => now()->toIso8601String()],
    ]);
    $secretHash = 'FLW_WEBHOOK_SECRET';

    $isValid = $driver->validateWebhook(
        ['verif-hash' => [$secretHash]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects flutterwave webhook when data has no created_at (ADR-0001)', function () {
    $config = [
        'secret_key' => 'FLW_SECRET_KEY',
        'webhook_secret' => 'FLW_WEBHOOK_SECRET',
        'currencies' => ['NGN'],
    ];

    $driver = new FlutterwaveDriver($config);

    $body = json_encode(['event' => 'charge.completed', 'data' => ['id' => 123]]);

    $isValid = $driver->validateWebhook(['verif-hash' => ['FLW_WEBHOOK_SECRET']], $body);

    expect($isValid)->toBeFalse();
});

test('it validates monnify webhook signature correctly', function () {
    $config = [
        'api_key' => 'MON_API_KEY',
        'secret_key' => 'MON_SECRET_KEY',
        'contract_code' => 'MON_CONTRACT',
        'currencies' => ['NGN'],
    ];

    $driver = new MonnifyDriver($config);

    $body = json_encode([
        'eventType' => 'SUCCESSFUL_TRANSACTION',
        'eventData' => [
            'transactionReference' => 'REF_123',
            'paidOn' => now()->format('Y-m-d H:i:s'),
        ],
    ]);
    $signature = hash_hmac('sha512', $body, 'MON_SECRET_KEY');

    $isValid = $driver->validateWebhook(
        ['monnify-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects monnify webhook when eventData has no paidOn/completedOn (ADR-0001)', function () {
    $config = [
        'api_key' => 'MON_API_KEY',
        'secret_key' => 'MON_SECRET_KEY',
        'contract_code' => 'MON_CONTRACT',
        'currencies' => ['NGN'],
    ];

    $driver = new MonnifyDriver($config);

    $body = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => 'REF_123']]);
    $signature = hash_hmac('sha512', $body, 'MON_SECRET_KEY');

    $isValid = $driver->validateWebhook(['monnify-signature' => [$signature]], $body);

    expect($isValid)->toBeFalse();
});

test('it validates opay webhook signature correctly', function () {
    $config = [
        'merchant_id' => 'OPAY_MERCHANT',
        'public_key' => 'OPAY_PUBLIC',
        'secret_key' => 'OPAY_SECRET',
        'currencies' => ['NGN'],
    ];

    $driver = new OPayDriver($config);

    $body = json_encode([
        'payload' => [
            'reference' => 'OPAY_123',
            'status' => 'SUCCESS',
            'timestamp' => now()->toIso8601String(),
        ],
        'type' => 'transaction-status',
    ]);
    $signature = hash_hmac('sha256', $body, 'OPAY_SECRET');

    $isValid = $driver->validateWebhook(
        ['x-opay-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects opay webhook when payload has no timestamp (ADR-0001)', function () {
    $config = [
        'merchant_id' => 'OPAY_MERCHANT',
        'public_key' => 'OPAY_PUBLIC',
        'secret_key' => 'OPAY_SECRET',
        'currencies' => ['NGN'],
    ];

    $driver = new OPayDriver($config);

    $body = json_encode(['payload' => ['reference' => 'OPAY_123', 'status' => 'SUCCESS'], 'type' => 'transaction-status']);
    $signature = hash_hmac('sha256', $body, 'OPAY_SECRET');

    $isValid = $driver->validateWebhook(['x-opay-signature' => [$signature]], $body);

    expect($isValid)->toBeFalse();
});

test('it validates square webhook signature correctly', function () {
    $config = [
        'access_token' => 'SQUARE_TOKEN',
        'location_id' => 'SQUARE_LOCATION',
        'webhook_signature_key' => 'SQUARE_SIG_KEY',
        'currencies' => ['USD'],
    ];

    $driver = new SquareDriver($config);

    $body = json_encode([
        'type' => 'payment.created',
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => ['id' => 'payment_123']],
    ]);
    $signature = base64_encode(hash_hmac('sha256', $body, 'SQUARE_SIG_KEY', true));

    $isValid = $driver->validateWebhook(
        ['x-square-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects webhook with wrong signature algorithm', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => now()->toIso8601String()],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeFalse();
});

test('it handles case-insensitive webhook headers', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123', 'paid_at' => now()->toIso8601String()],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    $isValid1 = $driver->validateWebhook(
        ['X-Paystack-Signature' => [$signature]],
        $body
    );

    $isValid2 = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid1)->toBeTrue()
        ->and($isValid2)->toBeTrue();
});

test('it validates webhook with timestamp within tolerance', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'paid_at' => date(DATE_ATOM, time() - 60), // 1 minute ago (within 5 min tolerance)
        ],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects webhook with timestamp outside tolerance', function () {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'paid_at' => date(DATE_ATOM, time() - 400), // 6.67 minutes ago (outside 5 min tolerance)
        ],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    $isValid = $driver->validateWebhook(
        ['x-paystack-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeFalse();
});
