<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;

/*
 * Line 88 (domain fails FILTER_VALIDATE_DOMAIN): ChargeRequestDTO::isValidEmail()
 * naively does [$local, $domain] = explode('@', $email) after filter_var() has
 * already validated the FULL email string. A quoted local-part may legally
 * contain an internal '@' (RFC 5321 quoted-string), e.g. "a@@b"@example.com,
 * which filter_var(..., FILTER_VALIDATE_EMAIL) accepts as valid overall, but
 * the naive explode() splits it into more than two pieces and list-assignment
 * takes only the first two, leaving $domain as an EMPTY string (the segment
 * between the two literal '@' characters) - which fails FILTER_VALIDATE_DOMAIN.
 */
test('charge request rejects email whose naive domain split is empty due to quoted local-part containing @@', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => '"a@@b"@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

/*
 * Line 100 (suspicious pattern match => return false): a quoted local-part is
 * allowed to contain consecutive dots ("..") per RFC 5321, e.g. "user..name"@example.com,
 * which passes both FILTER_VALIDATE_EMAIL and the (very lenient) FILTER_VALIDATE_DOMAIN
 * check on the correctly-split domain, but the suspicious-pattern loop matches
 * '/\.\./' against the raw $email string and rejects it anyway.
 */
test('charge request rejects quoted email containing consecutive dots as a suspicious pattern', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => '"user..name"@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

/*
 * Line 144: fromArray() rejects an explicitly-provided idempotency_key that
 * doesn't match the allowed alphanumeric/dash/underscore format.
 */
test('charge request rejects idempotency key with invalid characters', function () {
    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => 'has spaces!',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid idempotency key format');
});

test('charge request rejects idempotency key exceeding max reference length', function () {
    $key = str_repeat('a', \KenDeNigerian\PayZephyr\Constants\PaymentConstants::MAX_REFERENCE_LENGTH + 1);

    expect(fn () => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => $key,
    ]))->toThrow(InvalidArgumentException::class, 'Invalid idempotency key format');
});

test('charge request accepts a valid explicit idempotency key', function () {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => 'valid-key_123',
    ]);

    expect($request)->toBeInstanceOf(ChargeRequestDTO::class);
});
