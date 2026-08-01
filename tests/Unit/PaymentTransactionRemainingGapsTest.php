<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

test('isSuccessful falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
    app()->bind(StatusNormalizerInterface::class, function () {
        throw new RuntimeException('container blew up');
    });

    $model = new PaymentTransaction(['status' => 'succeeded']);

    expect($model->isSuccessful())->toBeTrue();
});

test('isFailed falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
    app()->bind(StatusNormalizerInterface::class, function () {
        throw new RuntimeException('container blew up');
    });

    $model = new PaymentTransaction(['status' => 'declined']);

    expect($model->isFailed())->toBeTrue();
});

test('isPending falls back to StatusNormalizer::normalizeStatic when container resolution throws', function () {
    app()->bind(StatusNormalizerInterface::class, function () {
        throw new RuntimeException('container blew up');
    });

    $model = new PaymentTransaction(['status' => 'processing']);

    expect($model->isPending())->toBeTrue();
});

test('isSuccessful returns false for an unrecognized status even when the container throws', function () {
    app()->bind(StatusNormalizerInterface::class, function () {
        throw new RuntimeException('container blew up');
    });

    $model = new PaymentTransaction(['status' => 'totally_unknown_status']);

    expect($model->isSuccessful())->toBeFalse();
});
