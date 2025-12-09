<?php

use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('status normalizer normalizes success statuses', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('SUCCESS'))->toBe('success')
        ->and($normalizer->normalize('SUCCEEDED'))->toBe('success')
        ->and($normalizer->normalize('COMPLETED'))->toBe('success')
        ->and($normalizer->normalize('SUCCESSFUL'))->toBe('success')
        ->and($normalizer->normalize('PAID'))->toBe('success')
        ->and($normalizer->normalize('CAPTURED'))->toBe('success');
});

test('status normalizer normalizes failed statuses', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('FAILED'))->toBe('failed')
        ->and($normalizer->normalize('REJECTED'))->toBe('failed')
        ->and($normalizer->normalize('CANCELLED'))->toBe('failed')
        ->and($normalizer->normalize('CANCELED'))->toBe('failed')
        ->and($normalizer->normalize('DECLINED'))->toBe('failed')
        ->and($normalizer->normalize('DENIED'))->toBe('failed')
        ->and($normalizer->normalize('VOIDED'))->toBe('failed')
        ->and($normalizer->normalize('EXPIRED'))->toBe('failed');
});

test('status normalizer normalizes pending statuses', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('PENDING'))->toBe('pending')
        ->and($normalizer->normalize('PROCESSING'))->toBe('pending')
        ->and($normalizer->normalize('APPROVED'))->toBe('pending')
        ->and($normalizer->normalize('CREATED'))->toBe('pending')
        ->and($normalizer->normalize('SAVED'))->toBe('pending');
});

test('status normalizer handles provider-specific mappings', function () {
    $normalizer = new StatusNormalizer;

    $normalizer->registerProviderMappings('custom', [
        'success' => ['CUSTOM_SUCCESS'],
        'failed' => ['CUSTOM_FAILED'],
    ]);

    expect($normalizer->normalize('CUSTOM_SUCCESS', 'custom'))->toBe('success')
        ->and($normalizer->normalize('CUSTOM_FAILED', 'custom'))->toBe('failed');
});

test('status normalizer falls back to default mappings when provider mapping not found', function () {
    $normalizer = new StatusNormalizer;

    $normalizer->registerProviderMappings('custom', [
        'success' => ['CUSTOM_SUCCESS'],
    ]);

    // Should fall back to default mappings
    expect($normalizer->normalize('SUCCESS', 'custom'))->toBe('success')
        ->and($normalizer->normalize('FAILED', 'custom'))->toBe('failed');
});

test('status normalizer returns lowercase for unknown status', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('UNKNOWN_STATUS'))->toBe('unknown_status')
        ->and($normalizer->normalize('SOME_OTHER_STATUS'))->toBe('some_other_status');
});

test('status normalizer trims whitespace', function () {
    $normalizer = new StatusNormalizer;

    expect($normalizer->normalize('  SUCCESS  '))->toBe('success')
        ->and($normalizer->normalize("\tFAILED\n"))->toBe('failed');
});

test('status normalizer getProviderMappings returns registered mappings', function () {
    $normalizer = new StatusNormalizer;

    $normalizer->registerProviderMappings('custom', [
        'success' => ['CUSTOM_SUCCESS'],
    ]);

    $mappings = $normalizer->getProviderMappings();

    expect($mappings)->toHaveKey('custom')
        ->and($mappings['custom'])->toHaveKey('success');
});

test('status normalizer getDefaultMappings returns default mappings', function () {
    $normalizer = new StatusNormalizer;

    $mappings = $normalizer->getDefaultMappings();

    expect($mappings)->toHaveKeys(['success', 'failed', 'pending'])
        ->and($mappings['success'])->toContain('SUCCESS', 'COMPLETED')
        ->and($mappings['failed'])->toContain('FAILED', 'DECLINED')
        ->and($mappings['pending'])->toContain('PENDING', 'APPROVED');
});

test('status normalizer normalizeStatic works without container', function () {
    expect(StatusNormalizer::normalizeStatic('SUCCESS'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('FAILED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('PENDING'))->toBe('pending')
        ->and(StatusNormalizer::normalizeStatic('COMPLETED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('UNKNOWN'))->toBe('unknown');
});

test('status normalizer normalizeStatic trims and handles case', function () {
    expect(StatusNormalizer::normalizeStatic('  success  '))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('Success'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('FAILED'))->toBe('failed');
});

test('status normalizer registerProviderMappings allows chaining', function () {
    $normalizer = new StatusNormalizer;

    $result = $normalizer->registerProviderMappings('custom1', ['success' => ['CUSTOM1']])
        ->registerProviderMappings('custom2', ['failed' => ['CUSTOM2']]);

    expect($result)->toBe($normalizer)
        ->and($normalizer->getProviderMappings())->toHaveKeys(['custom1', 'custom2']);
});

test('status normalizer normalizeStatic handles all status variations', function () {
    expect(StatusNormalizer::normalizeStatic('SUCCESS'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('SUCCEEDED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('COMPLETED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('SUCCESSFUL'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('PAID'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('OVERPAID'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('CAPTURED'))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic('FAILED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('REJECTED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('CANCELLED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('CANCELED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('DECLINED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('DENIED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('VOIDED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('EXPIRED'))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('PENDING'))->toBe('pending')
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

test('status normalizer normalizeStatic handles whitespace', function () {
    expect(StatusNormalizer::normalizeStatic('  SUCCESS  '))->toBe('success')
        ->and(StatusNormalizer::normalizeStatic("\tFAILED\n"))->toBe('failed')
        ->and(StatusNormalizer::normalizeStatic('  PENDING  '))->toBe('pending');
});
