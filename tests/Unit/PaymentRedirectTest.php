<?php

use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment redirect method returns redirect response', function () {
    $manager = Mockery::mock(PaymentManager::class);
    
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://paystack.com/checkout/ref_123',
        accessCode: 'code_123',
        status: 'pending'
    );
    
    $manager->shouldReceive('chargeWithFallback')
        ->once()
        ->andReturn($response);
    
    $payment = new Payment($manager);
    $payment->amount(10000)
        ->currency('NGN')
        ->email('test@example.com');
    
    $redirect = $payment->redirect();
    
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toBe('https://paystack.com/checkout/ref_123');
});
