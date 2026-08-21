<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Services\PayloadRedactor;

beforeEach(function () {
    app()->forgetInstance('payments.config');
});

function redactor(): PayloadRedactor
{
    return new PayloadRedactor;
}

test('configured sensitive fields are replaced, not removed', function () {
    $redacted = redactor()->redact([
        'amount' => 5000,
        'card_number' => '4111111111111111',
        'cvv' => '123',
    ]);

    expect($redacted)->toBe([
        'amount' => 5000,
        'card_number' => PayloadRedactor::REDACTED,
        'cvv' => PayloadRedactor::REDACTED,
    ]);
});

test('redaction reaches sensitive fields nested inside the payload', function () {
    $redacted = redactor()->redact([
        'customer' => [
            'name' => 'Ada',
            'card' => ['card_number' => '4111111111111111'],
        ],
    ]);

    expect($redacted['customer']['name'])->toBe('Ada')
        ->and($redacted['customer']['card']['card_number'])->toBe(PayloadRedactor::REDACTED);
});

test('matching is case-insensitive, because providers are not consistent', function () {
    $redacted = redactor()->redact([
        'CVV' => '123',
        'Api_Key' => 'sk_live_x',
        'AUTHORIZATION' => 'Bearer x',
    ]);

    expect($redacted['CVV'])->toBe(PayloadRedactor::REDACTED)
        ->and($redacted['Api_Key'])->toBe(PayloadRedactor::REDACTED)
        ->and($redacted['AUTHORIZATION'])->toBe(PayloadRedactor::REDACTED);
});

test('matching is on substrings, so provider-prefixed secret names are still caught', function () {
    $redacted = redactor()->redact([
        'stripe_secret_key' => 'sk_live_x',
        'customer_access_token' => 'tok_x',
    ]);

    expect($redacted['stripe_secret_key'])->toBe(PayloadRedactor::REDACTED)
        ->and($redacted['customer_access_token'])->toBe(PayloadRedactor::REDACTED);
});

test('substring matching also redacts benign keys that merely contain a secret word', function () {
    // Asserted rather than glossed over: this is the documented cost of
    // substring matching, and a change in this behaviour should break a test
    // rather than quietly alter what ends up stored.
    $redacted = redactor()->redact([
        'tokenization_enabled' => true,
        'secret_santa' => 'Ada',
    ]);

    expect($redacted['tokenization_enabled'])->toBe(PayloadRedactor::REDACTED)
        ->and($redacted['secret_santa'])->toBe(PayloadRedactor::REDACTED);
});

test('a payload with nothing sensitive in it survives untouched', function () {
    $payload = ['amount' => 5000, 'currency' => 'NGN', 'items' => ['a', 'b']];

    expect(redactor()->redact($payload))->toBe($payload);
});

test('an empty payload stays empty', function () {
    expect(redactor()->redact([]))->toBe([]);
});

test('non-string keys are left alone rather than crashing the redactor', function () {
    $redacted = redactor()->redact([0 => 'first', 1 => 'second']);

    expect($redacted)->toBe([0 => 'first', 1 => 'second']);
});

test('recursion stops at the configured depth instead of following hostile nesting', function () {
    config(['payments.trace.redaction_max_depth' => 3]);

    $redacted = redactor()->redact([
        'l1' => ['l2' => ['l3' => ['l4' => ['secret' => 'deep']]]],
    ]);

    expect($redacted['l1']['l2']['l3'])->toBe(['_truncated' => PayloadRedactor::REDACTED.' max depth reached']);
});

test('the depth limit can be overridden per call', function () {
    $redacted = redactor()->redact(['l1' => ['l2' => 'value']], maxDepth: 1);

    expect($redacted['l1'])->toBe(['_truncated' => PayloadRedactor::REDACTED.' max depth reached']);
});

test('the redacted field list is driven by config, not hardcoded', function () {
    config(['payments.trace.redact_fields' => ['email']]);

    $redacted = redactor()->redact([
        'email' => 'ada@example.com',
        'card_number' => '4111111111111111',
    ]);

    expect($redacted['email'])->toBe(PayloadRedactor::REDACTED)
        ->and($redacted['card_number'])->toBe('4111111111111111');
});

test('an empty field list redacts nothing', function () {
    config(['payments.trace.redact_fields' => []]);

    expect(redactor()->redact(['cvv' => '123']))->toBe(['cvv' => '123']);
});
