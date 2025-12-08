<?php

use KenDeNigerian\PayZephyr\Constants\PaymentStatus;

test('payment status enum has all cases', function () {
    expect(PaymentStatus::cases())->toHaveCount(4)
        ->and(PaymentStatus::SUCCESS->value)->toBe('success')
        ->and(PaymentStatus::FAILED->value)->toBe('failed')
        ->and(PaymentStatus::PENDING->value)->toBe('pending')
        ->and(PaymentStatus::CANCELLED->value)->toBe('cancelled');
});

test('payment status all method returns all values', function () {
    $all = PaymentStatus::all();
    
    expect($all)->toHaveCount(4)
        ->and($all)->toContain('success', 'failed', 'pending', 'cancelled');
});

test('payment status fromString creates enum from valid string', function () {
    expect(PaymentStatus::fromString('success'))->toBe(PaymentStatus::SUCCESS)
        ->and(PaymentStatus::fromString('FAILED'))->toBe(PaymentStatus::FAILED)
        ->and(PaymentStatus::fromString('  PENDING  '))->toBe(PaymentStatus::PENDING)
        ->and(PaymentStatus::fromString('CANCELLED'))->toBe(PaymentStatus::CANCELLED);
});

test('payment status fromString throws ValueError for invalid string', function () {
    expect(fn() => PaymentStatus::fromString('invalid'))
        ->toThrow(\ValueError::class);
});

test('payment status isValid checks if string is valid', function () {
    expect(PaymentStatus::isValid('success'))->toBeTrue()
        ->and(PaymentStatus::isValid('failed'))->toBeTrue()
        ->and(PaymentStatus::isValid('pending'))->toBeTrue()
        ->and(PaymentStatus::isValid('cancelled'))->toBeTrue()
        ->and(PaymentStatus::isValid('invalid'))->toBeFalse()
        ->and(PaymentStatus::isValid(''))->toBeFalse();
});

test('payment status isSuccessfulString checks string status', function () {
    expect(PaymentStatus::isSuccessfulString('success'))->toBeTrue()
        ->and(PaymentStatus::isSuccessfulString('SUCCESS'))->toBeTrue()
        ->and(PaymentStatus::isSuccessfulString('failed'))->toBeFalse()
        ->and(PaymentStatus::isSuccessfulString('pending'))->toBeFalse()
        ->and(PaymentStatus::isSuccessfulString('invalid'))->toBeFalse();
});

test('payment status isFailedString checks string status', function () {
    expect(PaymentStatus::isFailedString('failed'))->toBeTrue()
        ->and(PaymentStatus::isFailedString('cancelled'))->toBeTrue()
        ->and(PaymentStatus::isFailedString('CANCELLED'))->toBeTrue()
        ->and(PaymentStatus::isFailedString('success'))->toBeFalse()
        ->and(PaymentStatus::isFailedString('pending'))->toBeFalse()
        ->and(PaymentStatus::isFailedString('invalid'))->toBeFalse();
});

test('payment status isPendingString checks string status', function () {
    expect(PaymentStatus::isPendingString('pending'))->toBeTrue()
        ->and(PaymentStatus::isPendingString('PENDING'))->toBeTrue()
        ->and(PaymentStatus::isPendingString('success'))->toBeFalse()
        ->and(PaymentStatus::isPendingString('failed'))->toBeFalse()
        ->and(PaymentStatus::isPendingString('invalid'))->toBeFalse();
});

test('payment status isSuccessful method works correctly', function () {
    expect(PaymentStatus::SUCCESS->isSuccessful())->toBeTrue()
        ->and(PaymentStatus::FAILED->isSuccessful())->toBeFalse()
        ->and(PaymentStatus::PENDING->isSuccessful())->toBeFalse()
        ->and(PaymentStatus::CANCELLED->isSuccessful())->toBeFalse();
});

test('payment status isFailed method works correctly', function () {
    expect(PaymentStatus::FAILED->isFailed())->toBeTrue()
        ->and(PaymentStatus::CANCELLED->isFailed())->toBeTrue()
        ->and(PaymentStatus::SUCCESS->isFailed())->toBeFalse()
        ->and(PaymentStatus::PENDING->isFailed())->toBeFalse();
});

test('payment status isPending method works correctly', function () {
    expect(PaymentStatus::PENDING->isPending())->toBeTrue()
        ->and(PaymentStatus::SUCCESS->isPending())->toBeFalse()
        ->and(PaymentStatus::FAILED->isPending())->toBeFalse()
        ->and(PaymentStatus::CANCELLED->isPending())->toBeFalse();
});

test('payment status tryFromString returns enum for valid values', function () {
    expect(PaymentStatus::tryFromString('success'))->toBe(PaymentStatus::SUCCESS)
        ->and(PaymentStatus::tryFromString('FAILED'))->toBe(PaymentStatus::FAILED)
        ->and(PaymentStatus::tryFromString('  PENDING  '))->toBe(PaymentStatus::PENDING)
        ->and(PaymentStatus::tryFromString('CANCELLED'))->toBe(PaymentStatus::CANCELLED);
});

test('payment status tryFromString returns null for invalid values', function () {
    expect(PaymentStatus::tryFromString('invalid'))->toBeNull()
        ->and(PaymentStatus::tryFromString(''))->toBeNull()
        ->and(PaymentStatus::tryFromString('unknown'))->toBeNull();
});
