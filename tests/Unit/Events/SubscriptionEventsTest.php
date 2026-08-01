<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Events\PaymentInitiated;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationFailed;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationSuccess;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;

test('SubscriptionCreated exposes its constructor arguments as readonly properties', function () {
    $event = new SubscriptionCreated('SUB_1', 'paystack', ['plan' => 'PLN_1']);

    expect($event->subscriptionCode)->toBe('SUB_1')
        ->and($event->provider)->toBe('paystack')
        ->and($event->data)->toBe(['plan' => 'PLN_1']);
});

test('SubscriptionRenewed exposes its constructor arguments as readonly properties', function () {
    $event = new SubscriptionRenewed('SUB_1', 'stripe', 'INV_1', ['amount' => 1000]);

    expect($event->subscriptionCode)->toBe('SUB_1')
        ->and($event->provider)->toBe('stripe')
        ->and($event->invoiceReference)->toBe('INV_1')
        ->and($event->data)->toBe(['amount' => 1000]);
});

test('SubscriptionCancelled exposes its constructor arguments as readonly properties', function () {
    $event = new SubscriptionCancelled('SUB_1', 'flutterwave', ['reason' => 'user request']);

    expect($event->subscriptionCode)->toBe('SUB_1')
        ->and($event->provider)->toBe('flutterwave')
        ->and($event->data)->toBe(['reason' => 'user request']);
});

test('SubscriptionPaymentFailed exposes its constructor arguments as readonly properties', function () {
    $event = new SubscriptionPaymentFailed('SUB_1', 'square', 'card_declined', ['attempt' => 2]);

    expect($event->subscriptionCode)->toBe('SUB_1')
        ->and($event->provider)->toBe('square')
        ->and($event->reason)->toBe('card_declined')
        ->and($event->data)->toBe(['attempt' => 2]);
});

test('PaymentVerificationFailed exposes its constructor arguments as readonly properties', function () {
    $verification = new VerificationResponseDTO(
        status: 'failed',
        reference: 'REF_1',
        amount: 100.0,
        currency: 'USD',
    );

    $event = new PaymentVerificationFailed('REF_1', $verification, 'mollie');

    expect($event->reference)->toBe('REF_1')
        ->and($event->verification)->toBe($verification)
        ->and($event->provider)->toBe('mollie');
});

test('PaymentVerificationSuccess exposes its constructor arguments as readonly properties', function () {
    $verification = new VerificationResponseDTO(
        status: 'success',
        reference: 'REF_2',
        amount: 250.0,
        currency: 'NGN',
    );

    $event = new PaymentVerificationSuccess('REF_2', $verification, 'paystack');

    expect($event->reference)->toBe('REF_2')
        ->and($event->verification)->toBe($verification)
        ->and($event->provider)->toBe('paystack');
});

test('PaymentInitiated exposes its constructor arguments as readonly properties', function () {
    $request = new ChargeRequestDTO(amount: 5000.0, currency: 'NGN', email: 'a@b.com');
    $response = new ChargeResponseDTO(
        reference: 'REF_3',
        authorizationUrl: 'https://pay.example.com/REF_3',
        accessCode: 'ACC_1',
        status: 'pending',
    );

    $event = new PaymentInitiated($request, $response, 'paystack');

    expect($event->request)->toBe($request)
        ->and($event->response)->toBe($response)
        ->and($event->provider)->toBe('paystack');
});
