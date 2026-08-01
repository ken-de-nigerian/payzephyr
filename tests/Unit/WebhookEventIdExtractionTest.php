<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

test('base extractWebhookEventId reads top-level id / event_id / payment_id', function () {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test']);

    expect($driver->extractWebhookEventId(['id' => 'evt_123']))->toBe('evt_123')
        ->and($driver->extractWebhookEventId(['event_id' => 'e_1']))->toBe('e_1')
        ->and($driver->extractWebhookEventId(['payment_id' => 555]))->toBe('555')
        ->and($driver->extractWebhookEventId(['nothing' => 'here']))->toBeNull();
});

test('paypal extractWebhookEventId reads the top-level id', function () {
    $driver = new PayPalDriver(['client_id' => 'x', 'client_secret' => 'y']);

    expect($driver->extractWebhookEventId(['id' => 'WH-123ABC']))->toBe('WH-123ABC');
});

test('square extractWebhookEventId reads the top-level event_id', function () {
    $driver = new SquareDriver(['access_token' => 'x', 'location_id' => 'loc_1']);

    expect($driver->extractWebhookEventId(['event_id' => 'sq-evt-1']))->toBe('sq-evt-1');
});

test('paystack extractWebhookEventId prefers the nested data.id over a top-level id', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test']);

    expect($driver->extractWebhookEventId(['data' => ['id' => 1234567890]]))->toBe('1234567890')
        ->and($driver->extractWebhookEventId(['id' => 'top_level_id', 'data' => ['id' => 'nested_id']]))
        ->toBe('nested_id');
});

test('flutterwave extractWebhookEventId reads data.id', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'x']);

    expect($driver->extractWebhookEventId(['data' => ['id' => 42]]))->toBe('42');
});

test('monnify extractWebhookEventId reads eventData.transactionReference, falling back to reference', function () {
    $driver = new MonnifyDriver(['api_key' => 'x', 'secret_key' => 'y', 'contract_code' => 'z']);

    expect($driver->extractWebhookEventId(['eventData' => ['transactionReference' => 'MNF_REF_1']]))->toBe('MNF_REF_1')
        ->and($driver->extractWebhookEventId(['eventData' => ['reference' => 'MNF_REF_2']]))->toBe('MNF_REF_2');
});

test('opay extractWebhookEventId reads payload.transactionId, falling back to reference', function () {
    $driver = new OPayDriver(['merchant_id' => 'x', 'public_key' => 'y', 'secret_key' => 'z']);

    expect($driver->extractWebhookEventId(['payload' => ['transactionId' => 'OPAY_TXN_1']]))->toBe('OPAY_TXN_1')
        ->and($driver->extractWebhookEventId(['payload' => ['reference' => 'OPAY_REF_2']]))->toBe('OPAY_REF_2');
});

test('extractWebhookEventId falls back to the base implementation when the nested field is absent', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test']);

    // No data.id, but a top-level id happens to be present - base fallback
    // still applies rather than returning null outright.
    expect($driver->extractWebhookEventId(['id' => 'fallback_id', 'data' => []]))->toBe('fallback_id');
});
