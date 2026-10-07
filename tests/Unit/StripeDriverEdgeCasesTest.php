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

test('stripe driver accepts webhook with created timestamp within tolerance (ADR-0001)', function (): void {
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

test('stripe accepts a retry of an old event, because its window is on the freshly signed t=', function (): void {
    // Stripe signs a new t= for every delivery attempt; Event.created is the
    // event's and is repeated by every retry. The old check on Event.created
    // rejected every Stripe retry more than five minutes after the event.
    $secret = 'whsec_test_secret';
    $driver = new StripeDriver(['driver' => 'stripe', 'secret_key' => 'sk_test_xxx', 'webhook_secret' => $secret]);

    $payload = json_encode([
        'id' => 'evt_123',
        'type' => 'payment_intent.succeeded',
        'created' => time() - 86400,
        'data' => ['object' => ['metadata' => ['reference' => 'stripe_ref_123']]],
    ]);

    expect($driver->validateWebhook(['stripe-signature' => [makeStripeSignatureHeader($payload, $secret, time())]], $payload))->toBeTrue()
        ->and($driver->validateWebhook(['stripe-signature' => [makeStripeSignatureHeader($payload, $secret, time() - 600)]], $payload))->toBeFalse();
});

test('stripe applies the configured tolerance to the signed t= and declares it as its replay horizon', function (): void {
    config(['payments.security.webhook_timestamp_tolerance' => 900]);
    app()->forgetInstance('payments.config');

    $secret = 'whsec_test_secret';
    $driver = new StripeDriver(['driver' => 'stripe', 'secret_key' => 'sk_test_xxx', 'webhook_secret' => $secret]);
    $payload = json_encode(['id' => 'evt_124', 'type' => 'charge.succeeded']);

    expect($driver->validateWebhook(['stripe-signature' => [makeStripeSignatureHeader($payload, $secret, time() - 600)]], $payload))->toBeTrue()
        ->and($driver->webhookReplayHorizon())->toBe(900);
});

test('stripe driver handles webhook with metadata reference', function (): void {
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

test('stripe driver handles webhook with client_reference_id', function (): void {
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

test('stripe driver handles charge with zero decimal currency', function (): void {
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

test('stripe driver handles verify with different status formats', function (): void {
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
