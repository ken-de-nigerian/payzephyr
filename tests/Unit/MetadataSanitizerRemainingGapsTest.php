<?php

use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;

test('sanitize returns null once the max recursion depth is exceeded', function () {
    $sanitizer = new MetadataSanitizer;

    expect($sanitizer->sanitize('anything', 11))->toBeNull();
});

test('sanitize preserves boolean values as-is', function () {
    $sanitizer = new MetadataSanitizer;

    $sanitized = $sanitizer->sanitize([
        'is_active' => true,
        'is_deleted' => false,
    ]);

    expect($sanitized['is_active'])->toBeTrue()
        ->and($sanitized['is_deleted'])->toBeFalse();
});

test('sanitizeKey rejects keys longer than the max key length', function () {
    $sanitizer = new MetadataSanitizer;
    $longKey = str_repeat('a', 256);

    $sanitized = $sanitizer->sanitize([
        $longKey => 'value',
        'short_key' => 'ok',
    ]);

    expect($sanitized)->not->toHaveKey($longKey)
        ->and($sanitized)->toHaveKey('short_key');
});
