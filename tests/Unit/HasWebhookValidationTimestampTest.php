<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

/**
 * StripeDriver doesn't override extractWebhookTimestamp(), so it exercises
 * HasWebhookValidation::matchTimestampField()'s base flat-field matching
 * directly - the exact logic RELEASE_AUDIT_2026-07-31.md M-4 flagged.
 */
function validateStripeTimestamp(array $payload): bool
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('validateWebhookTimestamp');

    return $method->invoke($driver, $payload);
}

test('a small non-timestamp value under a matched field name no longer wins over a real timestamp in a later field', function () {
    // Regression for M-4: previously matchTimestampField() returned on the
    // FIRST matched field name regardless of plausibility. A payload where
    // "created" holds an unrelated small integer (e.g. a record/version
    // counter, not a date) would have been accepted as the timestamp,
    // computing a multi-decade time difference from now and causing this
    // otherwise-legitimately-timestamped webhook (via "paid_at") to be
    // falsely rejected as outside the replay tolerance window.
    $payload = [
        'created' => 5,
        'paid_at' => time(),
    ];

    expect(validateStripeTimestamp($payload))->toBeTrue();
});

test('a payload whose only matched field is an implausible small value is rejected as unrecognized, not misinterpreted', function () {
    $payload = ['time' => 30];

    expect(validateStripeTimestamp($payload))->toBeFalse();
});

test('a genuinely large but out-of-calendar-range value is also rejected as implausible', function () {
    // Comfortably beyond any real timestamp (year ~2100+), guards the same
    // "field name matched, value nonsensical" case from the other end.
    $payload = ['created' => 99999999999];

    expect(validateStripeTimestamp($payload))->toBeFalse();
});

test('a real, current Unix timestamp under a recognized field is still accepted', function () {
    $payload = ['created' => time()];

    expect(validateStripeTimestamp($payload))->toBeTrue();
});

test('a real timestamp expressed as a date string is still accepted', function () {
    $payload = ['created_at' => now()->toIso8601String()];

    expect(validateStripeTimestamp($payload))->toBeTrue();
});

function setWebhookTolerance(mixed $value): void
{
    config(['payments.security.webhook_timestamp_tolerance' => $value]);
    app()->forgetInstance('payments.config');
}

test('the configured webhook_timestamp_tolerance widens the replay window', function () {
    // Documented as the window for every provider, but only Paddle used to
    // read it - widening it had no effect anywhere else.
    $tenMinutesAgo = ['created' => time() - 600];

    setWebhookTolerance(300);
    expect(validateStripeTimestamp($tenMinutesAgo))->toBeFalse();

    setWebhookTolerance(900);
    expect(validateStripeTimestamp($tenMinutesAgo))->toBeTrue();
});

test('the configured webhook_timestamp_tolerance narrows the replay window', function () {
    setWebhookTolerance(60);

    expect(validateStripeTimestamp(['created' => time() - 120]))->toBeFalse();
});

test('the tolerance from env arrives as a string and is still honoured', function () {
    setWebhookTolerance('900');

    expect(validateStripeTimestamp(['created' => time() - 600]))->toBeTrue();
});

test('a tolerance that is not a positive number falls back to five minutes', function (mixed $value) {
    // Zero would reject every webhook; a negative number would reject them
    // all too, since abs() is never below it. Neither can be what was meant.
    setWebhookTolerance($value);

    expect(validateStripeTimestamp(['created' => time() - 240]))->toBeTrue()
        ->and(validateStripeTimestamp(['created' => time() - 360]))->toBeFalse();
})->with([
    'zero' => [0],
    'negative' => [-60],
    'not a number' => ['five minutes'],
    'null' => [null],
]);

test('a replay window that is not a positive number falls back to 72 hours', function (mixed $value) {
    config(['payments.webhook.events.replay_window' => $value]);
    app()->forgetInstance('payments.config');

    $driver = new \KenDeNigerian\PayZephyr\Drivers\SquareDriver(['access_token' => 'x', 'location_id' => 'l', 'currencies' => ['USD']]);

    expect($driver->webhookReplayHorizon())->toBe(259200);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'not a number' => ['three days'],
    'null' => [null],
]);

test('drivers that enforce no replay window declare no horizon', function (string $provider) {
    // payzephyr:webhooks:prune reads this; a driver claiming a horizon it
    // does not enforce would let its deduplication records be deleted while
    // a replay could still get through.
    expect(app(\KenDeNigerian\PayZephyr\PaymentManager::class)->driver($provider)->webhookReplayHorizon())->toBeNull();
})->with(['paystack', 'flutterwave', 'monnify', 'opay', 'mollie', 'razorpay']);
