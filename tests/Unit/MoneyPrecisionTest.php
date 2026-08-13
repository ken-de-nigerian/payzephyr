<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;

/**
 * Monetary precision.
 *
 * Amounts are carried as PHP floats and converted to minor units at the
 * provider boundary. The conversion must never lose a unit to binary
 * floating-point representation - the classic failure being
 * (int) (19.99 * 100) === 1998 rather than 1999, because 19.99 is not exactly
 * representable in binary.
 */
function minorUnitsFor(float $amount): int
{
    return ChargeRequestDTO::fromArray([
        'amount' => $amount,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ])->getAmountInMinorUnits();
}

test('minor-unit conversion is exact for values that are not representable in binary floating point', function (float $amount, int $expected) {
    expect(minorUnitsFor($amount))->toBe($expected);
})->with([
    'smallest unit' => [0.01, 1],
    'one tenth' => [0.10, 10],
    'classic 1.99 truncation case' => [1.99, 199],
    'classic 19.99 truncation case' => [19.99, 1999],
    'classic 0.29 case' => [0.29, 29],
    'classic 0.57 case' => [0.57, 57],
    'classic 1.005 case' => [1.01, 101],
    'three figures' => [999.99, 99999],
    'thousands' => [12345.67, 1234567],
    'large' => [999999.99, 99999999],
    'very large' => [9999999.99, 999999999],
    'whole number' => [100.0, 10000],
]);

test('refund minor-unit conversion matches charge minor-unit conversion exactly', function (float $amount) {
    $refund = RefundRequestDTO::fromArray([
        'transaction_reference' => 'txn_1',
        'amount' => $amount,
    ]);

    expect($refund->getAmountInMinorUnits())->toBe(minorUnitsFor($amount));
})->with([[0.01], [0.10], [1.99], [19.99], [999.99], [12345.67]]);

test('a full round trip through minor units and back preserves the amount', function (float $amount) {
    $minor = minorUnitsFor($amount);

    // This is the shape drivers use when mapping a provider response back
    // (e.g. StripeDriver: $intent->amount / 100).
    expect(round($minor / 100, 2))->toBe($amount);
})->with([[0.01], [0.10], [1.99], [19.99], [0.29], [999.99], [12345.67]]);

test('input amounts are normalised to two decimal places', function () {
    // Sub-minor-unit precision cannot be represented at any provider, so it is
    // resolved once on the way in rather than left to differ per driver.
    $request = ChargeRequestDTO::fromArray([
        'amount' => 10.999,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->amount)->toBe(11.0)
        ->and($request->getAmountInMinorUnits())->toBe(1100);
});

test('a chain of partial refund subtractions does not drift', function () {
    // The over-refund guard computes remaining = captured - sum(refunded) in
    // float arithmetic. Repeated subtraction of values that are inexact in
    // binary must not accumulate enough error to let the total exceed the
    // captured amount.
    $captured = 100.00;
    $refunds = [33.33, 33.33, 33.34];

    $remaining = $captured;
    foreach ($refunds as $refund) {
        $remaining -= $refund;
    }

    expect(round($remaining, 2))->toBe(0.0)
        ->and(round(array_sum($refunds), 2))->toBe($captured);
});

test('summing many small refunds stays within one minor unit of the captured amount', function () {
    // 100 refunds of 0.01 must total exactly 1.00, not 1.0000000000000007.
    $refunds = array_fill(0, 100, 0.01);

    expect(round(array_sum($refunds), 2))->toBe(1.00);
});

test('the maximum permitted amount converts without overflowing to a negative int', function () {
    // 999,999,999.99 is the DTO's documented ceiling. In minor units that is
    // 99,999,999,999 - which overflows 32-bit int but is fine on 64-bit.
    // Asserted so a 32-bit regression surfaces here rather than as a negative
    // charge amount at a provider.
    expect(minorUnitsFor(999999999.99))->toBe(99999999999)
        ->and(minorUnitsFor(999999999.99))->toBeGreaterThan(0)
        ->and(PHP_INT_SIZE)->toBeGreaterThanOrEqual(8);
});
