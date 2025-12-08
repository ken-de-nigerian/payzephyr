<?php

use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment redirect method returns redirect response', function () {

    // Mock the charge method to return a response
    $mockManager = Mockery::mock(PaymentManager::class);
    $mockResponse = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://paystack.com/pay/ref_123',
        accessCode: 'code_123',
        status: 'pending',
    );

    $mockManager->shouldReceive('chargeWithFallback')->andReturn($mockResponse);

    $payment = new Payment($mockManager);
    $payment->amount(10000)->currency('NGN')->email('test@example.com');

    $redirect = $payment->redirect();

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toBe('https://paystack.com/pay/ref_123');
});
