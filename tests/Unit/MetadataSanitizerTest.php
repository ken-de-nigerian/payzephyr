<?php

use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;

test('metadata sanitizer removes script tags', function (): void {
    $sanitizer = new MetadataSanitizer;
    $data = [
        'description' => '<script>alert("xss")</script>Safe content',
    ];

    $sanitized = $sanitizer->sanitize($data);

    expect($sanitized['description'])->not->toContain('<script>')
        ->and($sanitized['description'])->toContain('Safe content');
});

test('metadata sanitizer removes javascript protocol', function (): void {
    $sanitizer = new MetadataSanitizer;
    $data = [
        'url' => 'javascript:alert("xss")',
    ];

    $sanitized = $sanitizer->sanitize($data);

    expect($sanitized['url'])->not->toContain('javascript:');
});

test('metadata sanitizer limits string length', function (): void {
    $sanitizer = new MetadataSanitizer;
    $longString = str_repeat('a', 20000);
    $data = ['description' => $longString];

    $sanitized = $sanitizer->sanitize($data);

    expect(strlen($sanitized['description']))->toBeLessThanOrEqual(10000);
});

test('metadata sanitizer limits array size', function (): void {
    $sanitizer = new MetadataSanitizer;
    $largeArray = array_fill(0, 200, 'value');
    $data = ['items' => $largeArray];

    $sanitized = $sanitizer->sanitize($data);

    expect(count($sanitized['items']))->toBeLessThanOrEqual(100);
});

test('metadata sanitizer sanitizes nested arrays', function (): void {
    $sanitizer = new MetadataSanitizer;
    $data = [
        'user' => [
            'name' => '<script>alert("xss")</script>John',
            'email' => 'john@example.com',
        ],
    ];

    $sanitized = $sanitizer->sanitize($data);

    expect($sanitized['user']['name'])->not->toContain('<script>')
        ->and($sanitized['user']['email'])->toBe('john@example.com');
});

test('metadata sanitizer rejects invalid keys', function (): void {
    $sanitizer = new MetadataSanitizer;
    $data = [
        'valid_key' => 'value',
        'invalid key with spaces' => 'value',
        'invalid.key' => 'value',
    ];

    $sanitized = $sanitizer->sanitize($data);

    expect($sanitized)->toHaveKey('valid_key')
        ->and($sanitized)->not->toHaveKey('invalid key with spaces')
        ->and($sanitized)->not->toHaveKey('invalid.key');
});
