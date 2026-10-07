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

test('it validates paystack webhook signature correctly', function (): void {
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

test('it rejects paystack webhook with invalid signature', function (): void {
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

test('it rejects paystack webhook with missing signature', function (): void {
    $driver = app(PaymentManager::class)->driver('paystack');

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TEST_123'],
    ];

    $body = json_encode($payload);

    $isValid = $driver->validateWebhook([], $body);

    expect($isValid)->toBeFalse();
});

test('it validates flutterwave webhook signature correctly', function (): void {
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

test('it validates monnify webhook signature correctly', function (): void {
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

test('it validates opay webhook signature correctly', function (): void {
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

test('it validates square webhook signature correctly', function (): void {
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
    $signature = squareWebhookSignature($body, 'SQUARE_SIG_KEY');

    $isValid = $driver->validateWebhook(
        ['x-square-hmacsha256-signature' => [$signature]],
        $body
    );

    expect($isValid)->toBeTrue();
});

test('it rejects webhook with wrong signature algorithm', function (): void {
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

test('it handles case-insensitive webhook headers', function (): void {
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

test('it validates webhook with timestamp within tolerance', function (): void {
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

test('providers without an event time accept a validly-signed webhook on its signature (ADR-0017)', function (object $driver, string $body, Closure $headers): void {
    // Paystack, Flutterwave, Monnify and OPay carry no field that says when an
    // event happened, so a window on whatever timestamp is there rejected real
    // events. Their replay defence is deduplication: a replay is
    // byte-identical to a delivery already recorded (ADR-0016).
    expect($driver->validateWebhook($headers($body), $body))->toBeTrue();
})->with([
    'paystack, no timestamp at all' => [
        fn () => app(PaymentManager::class)->driver('paystack'),
        (string) json_encode(['event' => 'charge.success', 'data' => ['reference' => 'TEST_123']]),
        fn (string $b): array => ['x-paystack-signature' => [hash_hmac('sha512', $b, config('payments.providers.paystack.secret_key'))]],
    ],
    'flutterwave, no created_at' => [
        fn (): FlutterwaveDriver => new FlutterwaveDriver(['secret_key' => 'FLW_SECRET_KEY', 'webhook_secret' => 'FLW_WEBHOOK_SECRET', 'currencies' => ['NGN']]),
        (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => 123]]),
        fn (string $b): array => ['verif-hash' => ['FLW_WEBHOOK_SECRET']],
    ],
    'monnify, no paidOn' => [
        fn (): MonnifyDriver => new MonnifyDriver(['api_key' => 'MON_API_KEY', 'secret_key' => 'MON_SECRET_KEY', 'contract_code' => 'MON_CONTRACT', 'currencies' => ['NGN']]),
        (string) json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => 'REF_123']]),
        fn (string $b): array => ['monnify-signature' => [hash_hmac('sha512', $b, 'MON_SECRET_KEY')]],
    ],
    'opay, no timestamp' => [
        fn (): OPayDriver => new OPayDriver(['merchant_id' => 'OPAY_MERCHANT', 'public_key' => 'OPAY_PUBLIC', 'secret_key' => 'OPAY_SECRET', 'currencies' => ['NGN']]),
        (string) json_encode(['payload' => ['reference' => 'OPAY_123', 'status' => 'SUCCESS'], 'type' => 'transaction-status']),
        fn (string $b): array => ['x-opay-signature' => [hash_hmac('sha256', $b, 'OPAY_SECRET')]],
    ],
]);

test('a paystack charge.success is accepted however long the customer took to pay', function (): void {
    // data.created_at is when the transaction was initialised. The old window
    // read it before paid_at, so a customer who spent more than five minutes
    // on checkout had their payment notification rejected - and every
    // Paystack retry after it, since a retry repeats the body.
    $driver = app(PaymentManager::class)->driver('paystack');
    $body = (string) json_encode(['event' => 'charge.success', 'data' => [
        'reference' => 'TEST_SLOW_PAYER',
        'status' => 'success',
        'created_at' => date(DATE_ATOM, time() - 1800),
        'paid_at' => date(DATE_ATOM, time() - 1200),
    ]]);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    expect($driver->validateWebhook(['x-paystack-signature' => [$signature]], $body))->toBeTrue();
});

test('a paystack subscription cancellation is accepted for a subscription created long ago', function (): void {
    // Its only timestamp is the subscription's own createdAt, so the old
    // window rejected every cancellation of a subscription older than five
    // minutes.
    $driver = app(PaymentManager::class)->driver('paystack');
    $body = (string) json_encode(['event' => 'subscription.disable', 'data' => [
        'subscription_code' => 'SUB_OLD',
        'status' => 'complete',
        'createdAt' => date(DATE_ATOM, time() - 86400 * 90),
    ]]);
    $signature = hash_hmac('sha512', $body, config('payments.providers.paystack.secret_key'));

    expect($driver->validateWebhook(['x-paystack-signature' => [$signature]], $body))->toBeTrue();
});

test('square accepts a retry of an event created hours ago, and rejects one older than the replay window', function (): void {
    // Square's created_at is the event's own creation time and is signed, so
    // it bounds replays - but every retry repeats it, so the window has to
    // outlast Square's retry schedule rather than be five minutes.
    $driver = new SquareDriver([
        'access_token' => 'SQUARE_TOKEN',
        'location_id' => 'SQUARE_LOCATION',
        'webhook_signature_key' => 'SQUARE_SIG_KEY',
        'webhook_url' => 'https://shop.example.com/payments/webhook/square',
        'currencies' => ['USD'],
    ]);
    $deliver = function (int $createdAt) use ($driver): bool {
        $body = (string) json_encode(['event_id' => 'evt', 'type' => 'payment.updated', 'created_at' => date(DATE_ATOM, $createdAt)]);
        $signature = base64_encode(hash_hmac('sha256', 'https://shop.example.com/payments/webhook/square'.$body, 'SQUARE_SIG_KEY', true));

        return $driver->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body);
    };

    expect($deliver(time() - 3 * 3600))->toBeTrue()
        ->and($deliver(time() - 4 * 86400))->toBeFalse();
});

test('the replay window for event timestamps follows payments.webhook.events.replay_window', function (): void {
    config(['payments.webhook.events.replay_window' => 3600]);
    app()->forgetInstance('payments.config');

    $driver = new SquareDriver([
        'access_token' => 'SQUARE_TOKEN',
        'location_id' => 'SQUARE_LOCATION',
        'webhook_signature_key' => 'SQUARE_SIG_KEY',
        'webhook_url' => 'https://shop.example.com/payments/webhook/square',
        'currencies' => ['USD'],
    ]);
    $body = (string) json_encode(['event_id' => 'evt', 'type' => 'payment.updated', 'created_at' => date(DATE_ATOM, time() - 2 * 3600)]);
    $signature = base64_encode(hash_hmac('sha256', 'https://shop.example.com/payments/webhook/square'.$body, 'SQUARE_SIG_KEY', true));

    expect($driver->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body))->toBeFalse()
        ->and($driver->webhookReplayHorizon())->toBe(3600);
});
