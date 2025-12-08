<?php

use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('status normalizer normalizeStatic handles all success variations', function () {
    expect(StatusNormalizer::normalizeStatic('SUCCESS'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('SUCCEEDED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('COMPLETED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('SUCCESSFUL'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('PAID'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('OVERPAID'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('CAPTURED'))->toBe('success');
});

test('status normalizer normalizeStatic handles all failed variations', function () {
    expect(StatusNormalizer::normalizeStatic('FAILED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('REJECTED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('CANCELLED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('CANCELED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('DECLINED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('DENIED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('VOIDED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('EXPIRED'))->toBe('failed');
});

test('status normalizer normalizeStatic handles all pending variations', function () {
    expect(StatusNormalizer::normalizeStatic('PENDING'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('PROCESSING'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('PARTIALLY_PAID'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('CREATED'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('SAVED'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('APPROVED'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('PAYER_ACTION_REQUIRED'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('REQUIRES_ACTION'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('REQUIRES_PAYMENT_METHOD'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('REQUIRES_CONFIRMATION'))->toBe('pending');
});

test('status normalizer normalizeStatic returns lowercase for unknown status', function () {
    expect(StatusNormalizer::normalizeStatic('UNKNOWN_STATUS'))->toBe('unknown_status')
        ->and(StatusNormalizer::normalizeStatic('CUSTOM_STATUS'))->toBe('custom_status');
});

test('status normalizer normalizeStatic trims whitespace', function () {
    expect(StatusNormalizer::normalizeStatic('  SUCCESS  '))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic("\tFAILED\n"))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('  PENDING  '))->toBe('pending');
});

test('status normalizer normalizeStatic handles case variations', function () {
    expect(StatusNormalizer::normalizeStatic('success'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('Success'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('SUCCESS'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('sUcCeSs'))->toBe('success');
});
