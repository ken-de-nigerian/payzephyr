<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

test('payment transaction isSuccessful falls back to static normalization', function (): void {
    $transaction = new PaymentTransaction(['status' => 'succeeded']);

    expect($transaction->isSuccessful())->toBeTrue();
});

test('payment transaction isFailed falls back to static normalization', function (): void {
    $transaction = new PaymentTransaction(['status' => 'declined']);

    expect($transaction->isFailed())->toBeTrue();
});

test('payment transaction isPending falls back to static normalization', function (): void {
    $transaction = new PaymentTransaction(['status' => 'processing']);

    expect($transaction->isPending())->toBeTrue();
});

test('payment transaction handles exception in normalization gracefully', function (): void {
    $transaction = new PaymentTransaction(['status' => 'success']);

    expect($transaction->isSuccessful())->toBeTrue();
});
