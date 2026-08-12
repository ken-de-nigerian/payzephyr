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
