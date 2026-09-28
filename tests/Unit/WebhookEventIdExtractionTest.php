<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

test('base extractWebhookEventId reads a top-level event id', function () {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test']);

    expect($driver->extractWebhookEventId(['id' => 'evt_123']))->toBe('evt_123')
        ->and($driver->extractWebhookEventId(['event_id' => 'e_1']))->toBe('e_1')
        ->and($driver->extractWebhookEventId(['nothing' => 'here']))->toBeNull();
});

test('base extractWebhookEventId does not mistake a payment id for an event id', function () {
    // Every event about one payment carries its payment_id; keying on it
    // would drop every event after the first as a duplicate.
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test']);

    expect($driver->extractWebhookEventId(['payment_id' => 555]))->toBeNull();
});

test('paypal extractWebhookEventId reads the top-level id', function () {
    $driver = new PayPalDriver(['client_id' => 'x', 'client_secret' => 'y']);

    expect($driver->extractWebhookEventId(['id' => 'WH-123ABC']))->toBe('WH-123ABC');
});

test('square extractWebhookEventId reads the top-level event_id', function () {
    $driver = new SquareDriver(['access_token' => 'x', 'location_id' => 'loc_1']);

    expect($driver->extractWebhookEventId(['event_id' => 'sq-evt-1']))->toBe('sq-evt-1');
});

test('providers that send no event id return none, whatever object ids the body carries', function (Closure $makeDriver, array $payload) {
    // What these used to return - data.id, eventData.transactionReference,
    // payload.transactionId - identifies the object, not the event.
    expect($makeDriver()->extractWebhookEventId($payload))->toBeNull();
})->with([
    'paystack' => [fn () => new PaystackDriver(['secret_key' => 'sk_test']), ['id' => 'top', 'data' => ['id' => 1234567890]]],
    'flutterwave' => [fn () => new FlutterwaveDriver(['secret_key' => 'x']), ['data' => ['id' => 42]]],
    'monnify' => [fn () => new MonnifyDriver(['api_key' => 'x', 'secret_key' => 'y', 'contract_code' => 'z']), ['eventData' => ['transactionReference' => 'MNF_REF_1', 'reference' => 'R']]],
    'opay' => [fn () => new OPayDriver(['merchant_id' => 'x', 'public_key' => 'y', 'secret_key' => 'z']), ['payload' => ['transactionId' => 'OPAY_TXN_1', 'reference' => 'R']]],
]);
