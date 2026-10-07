<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
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
test('charge request rejects email whose naive domain split is empty due to quoted local-part containing @@', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
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
test('charge request rejects quoted email containing consecutive dots as a suspicious pattern', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => '"user..name"@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

/*
 * Line 144: fromArray() rejects an explicitly-provided idempotency_key that
 * doesn't match the allowed alphanumeric/dash/underscore format.
 */
test('charge request rejects idempotency key with invalid characters', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => 'has spaces!',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid idempotency key format');
});

test('charge request rejects idempotency key exceeding max reference length', function (): void {
    $key = str_repeat('a', PaymentConstants::MAX_REFERENCE_LENGTH + 1);

    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => $key,
    ]))->toThrow(InvalidArgumentException::class, 'Invalid idempotency key format');
});

test('charge request accepts a valid explicit idempotency key', function (): void {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'idempotency_key' => 'valid-key_123',
    ]);

    expect($request)->toBeInstanceOf(ChargeRequestDTO::class);
});

/*
 * The explicit 64-character local-part check: FILTER_VALIDATE_EMAIL counts a
 * quoted local part's length without its quotes, so "<63 or 64 chars>"@domain
 * passes it while the local part as written is 65-66 characters - over the
 * RFC 5321 limit. Only the explicit strlen() check catches that.
 */
test('charge request rejects a quoted local part longer than 64 characters including its quotes', function (): void {
    $email = '"'.str_repeat('a', 63).'"@example.com';

    expect(filter_var($email, FILTER_VALIDATE_EMAIL))->not->toBeFalse()
        ->and(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
            'amount' => 100,
            'currency' => 'NGN',
            'email' => $email,
        ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('charge request accepts a quoted local part of exactly 64 characters including its quotes', function (): void {
    $email = '"'.str_repeat('a', 62).'"@example.com';

    expect(ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => $email,
    ])->email)->toBe($email);
});

/*
 * fromArray() is public API and is handed whatever an application collected.
 * A value of the wrong kind used to reach a typed constructor parameter and
 * fail there with a TypeError; it is now read as the type it should be, or
 * left for validation to reject.
 */
test('fromArray turns a wrong-typed value into a validation error, not a TypeError', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray(['amount' => 100, 'currency' => 'NGN', 'email' => ['not', 'a', 'string']]))
        ->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('fromArray keeps only the channel names from a channels list', function (): void {
    $dto = ChargeRequestDTO::fromArray([
        'amount' => 100, 'currency' => 'NGN', 'email' => 'a@b.test',
        'channels' => ['card', 42, null, 'bank_transfer'],
    ]);

    expect($dto->channels)->toBe(['card', 'bank_transfer']);
});

test('fromArray rejects an idempotency key that is not a string or a number', function (): void {
    expect(fn (): ChargeRequestDTO => ChargeRequestDTO::fromArray([
        'amount' => 100, 'currency' => 'NGN', 'email' => 'a@b.test', 'idempotency_key' => ['nested'],
    ]))->toThrow(InvalidArgumentException::class, 'Invalid idempotency key format');
});
