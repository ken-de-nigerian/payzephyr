<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment redirect method returns redirect response', function (): void {
    config([
        'payments.default' => 'paystack',
        'payments.health_check.enabled' => false, // Disable health check for testing
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'public_key' => 'pk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN'],
        ],
    ]);

    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('charge')
        ->once()
        ->andReturn(new ChargeResponseDTO(
            reference: 'ref_123',
            authorizationUrl: 'https://paystack.com/checkout/ref_123',
            accessCode: 'code_123',
            status: 'pending'
        ));
    $mockDriver->shouldReceive('getSupportedCurrencies')
        ->andReturn(['NGN']);
    $mockDriver->shouldReceive('getCachedHealthCheck')
        ->andReturn(true);
    $mockDriver->shouldReceive('isCurrencySupported')
        ->with('NGN')
        ->andReturn(true);

    $manager = new PaymentManager;

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);
    $payment = new Payment($manager);
    $payment->amount(10000)
        ->currency('NGN')
        ->email('test@example.com')
        ->callback('https://example.com/callback');

    $redirect = $payment->redirect();

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toBe('https://paystack.com/checkout/ref_123');
});
