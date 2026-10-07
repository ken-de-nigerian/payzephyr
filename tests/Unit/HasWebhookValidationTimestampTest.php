<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

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

test('a small non-timestamp value under a matched field name no longer wins over a real timestamp in a later field', function (): void {
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

test('a payload whose only matched field is an implausible small value is rejected as unrecognized, not misinterpreted', function (): void {
    $payload = ['time' => 30];

    expect(validateStripeTimestamp($payload))->toBeFalse();
});

test('a genuinely large but out-of-calendar-range value is also rejected as implausible', function (): void {
    // Comfortably beyond any real timestamp (year ~2100+), guards the same
    // "field name matched, value nonsensical" case from the other end.
    $payload = ['created' => 99999999999];

    expect(validateStripeTimestamp($payload))->toBeFalse();
});

test('a real, current Unix timestamp under a recognized field is still accepted', function (): void {
    $payload = ['created' => time()];

    expect(validateStripeTimestamp($payload))->toBeTrue();
});

test('a real timestamp expressed as a date string is still accepted', function (): void {
    $payload = ['created_at' => now()->toIso8601String()];

    expect(validateStripeTimestamp($payload))->toBeTrue();
});

function setWebhookTolerance(mixed $value): void
{
    config(['payments.security.webhook_timestamp_tolerance' => $value]);
    app()->forgetInstance('payments.config');
}

test('the configured webhook_timestamp_tolerance widens the replay window', function (): void {
    // Documented as the window for every provider, but only Paddle used to
    // read it - widening it had no effect anywhere else.
    $tenMinutesAgo = ['created' => time() - 600];

    setWebhookTolerance(300);
    expect(validateStripeTimestamp($tenMinutesAgo))->toBeFalse();

    setWebhookTolerance(900);
    expect(validateStripeTimestamp($tenMinutesAgo))->toBeTrue();
});

test('the configured webhook_timestamp_tolerance narrows the replay window', function (): void {
    setWebhookTolerance(60);

    expect(validateStripeTimestamp(['created' => time() - 120]))->toBeFalse();
});

test('the tolerance from env arrives as a string and is still honoured', function (): void {
    setWebhookTolerance('900');

    expect(validateStripeTimestamp(['created' => time() - 600]))->toBeTrue();
});

test('a tolerance that is not a positive number falls back to five minutes', function (mixed $value): void {
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

test('a replay window that is not a positive number falls back to 72 hours', function (mixed $value): void {
    config(['payments.webhook.events.replay_window' => $value]);
    app()->forgetInstance('payments.config');

    $driver = new SquareDriver(['access_token' => 'x', 'location_id' => 'l', 'currencies' => ['USD']]);

    expect($driver->webhookReplayHorizon())->toBe(259200);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'not a number' => ['three days'],
    'null' => [null],
]);

test('drivers that enforce no replay window declare no horizon', function (string $provider): void {
    // payzephyr:webhooks:prune reads this; a driver claiming a horizon it
    // does not enforce would let its deduplication records be deleted while
    // a replay could still get through.
    expect(app(PaymentManager::class)->driver($provider)->webhookReplayHorizon())->toBeNull();
})->with(['paystack', 'flutterwave', 'monnify', 'opay', 'mollie', 'razorpay']);

function stripeDriverMethod(string $method, mixed ...$args): mixed
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);

    return (new ReflectionClass($driver))->getMethod($method)->invoke($driver, ...$args);
}

test('a missing timestamp is rejected with its own warning, not the tolerance one', function (): void {
    $logs = captureLogs();

    expect(validateStripeTimestamp(['event' => 'charge.success']))->toBeFalse();

    $entry = loggedEntry($logs, 'Webhook timestamp missing or unrecognized');

    expect($entry['level'])->toBe('warning')
        ->and($entry['context']['hint'])->toContain('override extractWebhookTimestamp()')
        ->and(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'outside tolerance window')))->toBe([]);
});

test('a timestamp outside the window is logged with what was compared', function (): void {
    $logs = captureLogs();
    $sent = time() - 3600;

    expect(stripeDriverMethod('validateWebhookTimestamp', ['timestamp' => $sent], 300))->toBeFalse();

    $context = loggedEntry($logs, 'Webhook timestamp outside tolerance window')['context'];

    expect($context['timestamp'])->toBe($sent)
        ->and($context['current_time'])->toBeGreaterThanOrEqual($sent + 3600)
        ->and($context['difference_seconds'])->toBe($context['current_time'] - $sent)
        ->and($context['tolerance_seconds'])->toBe(300);
});

test('a timestamp sent as a numeric string is read as one', function (): void {
    // strtotime() does not read a bare number, so the string falls through
    // to the numeric branch, which must make it an int.
    expect(validateStripeTimestamp(['timestamp' => (string) time()]))->toBeTrue();
});

test('a tolerance or replay window below one second falls back to the default', function (string $key, string $method, int $default): void {
    config([$key => 0.5]);
    app()->forgetInstance('payments.config');

    expect(stripeDriverMethod($method))->toBe($default);
})->with([
    'tolerance' => ['payments.security.webhook_timestamp_tolerance', 'webhookTimestampTolerance', PaymentConstants::WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS],
    'replay window' => ['payments.webhook.events.replay_window', 'webhookReplayWindow', PaymentConstants::WEBHOOK_REPLAY_WINDOW_SECONDS],
]);

test('a tolerance or replay window of exactly one second is used', function (string $key, string $method): void {
    config([$key => 1]);
    app()->forgetInstance('payments.config');

    expect(stripeDriverMethod($method))->toBe(1);
})->with([
    'tolerance' => ['payments.security.webhook_timestamp_tolerance', 'webhookTimestampTolerance'],
    'replay window' => ['payments.webhook.events.replay_window', 'webhookReplayWindow'],
]);

test('every field name a provider puts its event time under is read', function (string $field): void {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setWebhookReceivedAt(1_800_000_000);

    $validate = (new ReflectionClass($driver))->getMethod('validateWebhookTimestamp');

    expect($validate->invoke($driver, [$field => 1_800_000_000], 300))->toBeTrue();
})->with(['timestamp', 'created_at', 'createdAt', 'created', 'create_time', 'paid_at', 'paidOn', 'completedOn', 'createdOn', 'event_time', 'eventTime', 'time']);

test('a timestamp exactly at the edge of the window is accepted, and one second past it is not', function (int $age, bool $accepted): void {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setWebhookReceivedAt(1_800_000_000);

    $validate = (new ReflectionClass($driver))->getMethod('validateWebhookTimestamp');

    expect($validate->invoke($driver, ['timestamp' => 1_800_000_000 - $age], 300))->toBe($accepted);
})->with([
    'at the edge' => [300, true],
    'past it' => [301, false],
]);

test('a plausible timestamp is one from 2000 up to, not including, 2100', function (int $candidate, bool $plausible): void {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);

    expect((new ReflectionClass($driver))->getMethod('isPlausibleUnixTimestamp')->invoke($driver, $candidate))->toBe($plausible);
})->with([
    '2000-01-01 00:00:00' => [946684800, true],
    'a second before' => [946684799, false],
    'the last second of 2099' => [4102444799, true],
    '2100-01-01 00:00:00' => [4102444800, false],
]);
