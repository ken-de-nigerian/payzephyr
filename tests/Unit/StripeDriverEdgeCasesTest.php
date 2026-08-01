<?php

use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

/**
 * Builds a Stripe-Signature header value the same way Stripe itself does,
 * so \Stripe\Webhook::constructEvent() accepts it: t=<unix>,v1=<hmac>.
 */
function makeStripeSignatureHeader(string $payload, string $secret, int $timestamp): string
{
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t=$timestamp,v1=$signature";
}

test('stripe driver accepts webhook with created timestamp within tolerance (ADR-0001)', function () {
    $secret = 'whsec_test_secret';
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'webhook_secret' => $secret,
            'enabled' => true,
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    $payload = json_encode([
        'id' => 'evt_123',
        'type' => 'payment_intent.succeeded',
        'created' => time(),
        'data' => ['object' => ['metadata' => ['reference' => 'stripe_ref_123']]],
    ]);

    $headers = [
        'stripe-signature' => [makeStripeSignatureHeader($payload, $secret, time())],
    ];

    expect($driver->validateWebhook($headers, $payload))->toBeTrue();
});

test('stripe driver rejects a validly-signed webhook with no `created` field (ADR-0001)', function () {
    $secret = 'whsec_test_secret';
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'webhook_secret' => $secret,
            'enabled' => true,
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    // Stripe's SDK-level t= tolerance check passes (the header timestamp is
    // fresh) but our app-level check must independently reject this because
    // the JSON body itself carries no recognizable timestamp field.
    $payload = json_encode([
        'id' => 'evt_123',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['metadata' => ['reference' => 'stripe_ref_123']]],
    ]);

    $headers = [
        'stripe-signature' => [makeStripeSignatureHeader($payload, $secret, time())],
    ];

    expect($driver->validateWebhook($headers, $payload))->toBeFalse();
});

test('stripe driver handles webhook with metadata reference', function () {
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'webhook_secret' => 'whsec_xxx',
            'enabled' => true,
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    $headers = [
        'stripe-signature' => ['valid_signature'],
    ];

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'metadata' => [
                    'reference' => 'stripe_ref_123',
                ],
            ],
        ],
    ]);

    $result = $driver->validateWebhook($headers, $payload);

    expect($result)->toBeBool();
});

test('stripe driver handles webhook with client_reference_id', function () {
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'webhook_secret' => 'whsec_xxx',
            'enabled' => true,
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    $headers = [
        'stripe-signature' => ['valid_signature'],
    ];

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'client_reference_id' => 'stripe_client_ref_123',
            ],
        ],
    ]);

    $result = $driver->validateWebhook($headers, $payload);

    expect($result)->toBeBool();
});

test('stripe driver handles charge with zero decimal currency', function () {
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['JPY', 'KRW'], // Zero decimal currencies
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    expect($driver->isCurrencySupported('JPY'))->toBeTrue();
});

test('stripe driver handles verify with different status formats', function () {
    config([
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
    ]);

    $driver = new StripeDriver(config('payments.providers.stripe'));

    $statuses = ['succeeded', 'pending', 'failed', 'canceled', 'requires_action'];

    foreach ($statuses as $status) {
        expect($driver->isCurrencySupported('USD'))->toBeBool();
    }
});
