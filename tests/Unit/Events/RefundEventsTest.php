<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;

test('RefundCreated exposes its constructor arguments as readonly properties', function () {
    $event = new RefundCreated('RE_1', 'TXN_1', 'paystack', ['amount' => 5000]);

    expect($event->refundReference)->toBe('RE_1')
        ->and($event->transactionReference)->toBe('TXN_1')
        ->and($event->provider)->toBe('paystack')
        ->and($event->data)->toBe(['amount' => 5000]);
});

test('RefundCompleted exposes its constructor arguments as readonly properties', function () {
    $event = new RefundCompleted('RE_1', 'TXN_1', 'stripe', ['amount' => 5000]);

    expect($event->refundReference)->toBe('RE_1')
        ->and($event->transactionReference)->toBe('TXN_1')
        ->and($event->provider)->toBe('stripe')
        ->and($event->data)->toBe(['amount' => 5000]);
});

test('RefundFailed exposes its constructor arguments as readonly properties', function () {
    $event = new RefundFailed('RE_1', 'TXN_1', 'square', 'insufficient_funds', ['amount' => 5000]);

    expect($event->refundReference)->toBe('RE_1')
        ->and($event->transactionReference)->toBe('TXN_1')
        ->and($event->provider)->toBe('square')
        ->and($event->reason)->toBe('insufficient_funds')
        ->and($event->data)->toBe(['amount' => 5000]);
});
