<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

/*
 * getNormalizedStatus() resolves app(StatusNormalizer::class) inside a
 * try/catch and falls back to StatusNormalizer::normalizeStatic() on any
 * Throwable. Force the container to throw when resolving StatusNormalizer
 * to exercise the catch block and the static fallback return.
 */
test('getNormalizedStatus falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
    app()->bind(StatusNormalizer::class, function () {
        throw new RuntimeException('container blew up');
    });

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'succeeded',
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('isPending falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
    app()->bind(StatusNormalizer::class, function () {
        throw new RuntimeException('container blew up');
    });

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending',
    );

    expect($response->isPending())->toBeTrue();
});

test('isSuccessful returns false for an unrecognized status even when the container throws', function () {
    app()->bind(StatusNormalizer::class, function () {
        throw new RuntimeException('container blew up');
    });

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'totally_unknown_status',
    );

    expect($response->isSuccessful())->toBeFalse();
});
