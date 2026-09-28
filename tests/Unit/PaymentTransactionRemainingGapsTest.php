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

test('the status scopes fall back to the built-in vocabulary when container resolution throws', function () {
    // The scopes and the is*() predicates must agree about the same row even
    // when the container cannot hand back a normalizer.
    PaymentTransaction::create(['reference' => 'SCOPE_OK', 'provider' => 'paystack', 'status' => 'succeeded', 'amount' => 10, 'currency' => 'NGN', 'email' => 'a@b.test']);
    PaymentTransaction::create(['reference' => 'SCOPE_BAD', 'provider' => 'paystack', 'status' => 'declined', 'amount' => 10, 'currency' => 'NGN', 'email' => 'a@b.test']);
    PaymentTransaction::create(['reference' => 'SCOPE_WAIT', 'provider' => 'paystack', 'status' => 'processing', 'amount' => 10, 'currency' => 'NGN', 'email' => 'a@b.test']);

    app()->bind(StatusNormalizerInterface::class, function () {
        throw new RuntimeException('container blew up');
    });

    expect(PaymentTransaction::successful()->pluck('reference')->all())->toBe(['SCOPE_OK'])
        ->and(PaymentTransaction::failed()->pluck('reference')->all())->toBe(['SCOPE_BAD'])
        ->and(PaymentTransaction::pending()->pluck('reference')->all())->toBe(['SCOPE_WAIT']);
});
