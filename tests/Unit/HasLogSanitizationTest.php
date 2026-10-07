<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

function sanitizeViaDriver(mixed $data): mixed
{
    $driver = new PaystackDriver(['secret_key' => 'sk_test_irrelevant', 'currencies' => ['NGN']]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');

    return $method->invoke($driver, $data);
}

test('a short bearer token is still redacted despite being under the old 20-char gate', function (): void {
    // Regression: sanitizeLogContext() used to only pattern-match strings
    // longer than 20 characters (the since-removed
    // PaymentConstants::MAX_STRING_LENGTH_FOR_TOKEN_CHECK),
    // so a short real token like this ("Bearer test123" = 15 chars) slipped
    // through un-redacted.
    $result = sanitizeViaDriver(['auth' => 'Bearer test123']);

    expect($result['auth'])->toBe('[REDACTED_TOKEN]');
});

test('a short stripe-style secret key is redacted', function (): void {
    $result = sanitizeViaDriver(['value' => 'sk_test_abc']);

    expect($result['value'])->toBe('[REDACTED_TOKEN]');
});

test('a long bearer token is still redacted (no regression on the existing case)', function (): void {
    $result = sanitizeViaDriver(['auth' => 'Bearer '.str_repeat('a', 100)]);

    expect($result['auth'])->toBe('[REDACTED_TOKEN]');
});

test('authorization key is redacted by name regardless of value shape', function (): void {
    $result = sanitizeViaDriver(['Authorization' => 'some-opaque-value-not-matching-token-patterns']);

    expect($result['Authorization'])->toBe('[REDACTED]');
});

test('signature key is redacted by name regardless of value shape', function (): void {
    $result = sanitizeViaDriver(['signature' => 'abc123notatoken']);

    expect($result['signature'])->toBe('[REDACTED]');
});

test('ordinary short strings are left untouched', function (): void {
    $result = sanitizeViaDriver(['reference' => 'ORDER_123']);

    expect($result['reference'])->toBe('ORDER_123');
});
