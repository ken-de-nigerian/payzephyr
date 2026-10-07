<?php

use KenDeNigerian\PayZephyr\Facades\Payment;

test('can create payment with fluent api', function (): void {
    config(['payments.providers.paystack.enabled' => true]);

    $payment = Payment::amount(10000)
        ->email('test@example.com')
        ->currency('NGN')
        ->with('paystack');

    expect($payment)->toBeInstanceOf(\KenDeNigerian\PayZephyr\Payment::class);
});

test('payment helper function works', function (): void {
    $payment = payment()->amount(5000)->email('test@example.com');
    expect($payment)->toBeInstanceOf(\KenDeNigerian\PayZephyr\Payment::class);
});
