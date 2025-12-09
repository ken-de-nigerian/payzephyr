<?php

use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

test('payment transaction isFailed handles all failed status variations', function () {
    $failedStatuses = ['failed', 'declined', 'rejected', 'cancelled', 'denied', 'voided', 'expired'];

    foreach ($failedStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isFailed())->toBeTrue("Failed asserting true for status: $status");
    }
});

test('payment transaction isPending handles all pending status variations', function () {
    $pendingStatuses = ['pending', 'processing', 'approved', 'created', 'saved'];

    foreach ($pendingStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isPending())->toBeTrue("Failed asserting true for status: $status");
    }
});

test('payment transaction isSuccessful handles all success status variations', function () {
    $successStatuses = ['success', 'succeeded', 'completed', 'successful', 'paid'];

    foreach ($successStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isSuccessful())->toBeTrue("Failed asserting true for status: $status");
    }
});

test('payment transaction methods handle normalization when container unavailable', function () {
    // Test that methods work even without container
    $model = new PaymentTransaction(['status' => 'completed']);

    expect($model->isSuccessful())->toBeTrue();

    $model = new PaymentTransaction(['status' => 'declined']);
    expect($model->isFailed())->toBeTrue();

    $model = new PaymentTransaction(['status' => 'processing']);
    expect($model->isPending())->toBeTrue();
});
