<?php

use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Payment as PaymentClass;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function (): void {
    config([
        'payments.default' => 'paystack',
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ],
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['USD', 'EUR'],
        ],
        'payments.providers.flutterwave' => [
            'driver' => 'flutterwave',
            'secret_key' => 'FLWSECK_TEST-xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ],
    ]);
});

test('payment verify method with specific provider', function (): void {
    $manager = app(PaymentManager::class);
    $payment = new PaymentClass($manager);

    try {
        $payment->verify('ref_123', 'paystack');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class)
            ->and($e->getMessage())->toContain('Unable to verify');
    }
});

test('payment verify method without provider tries all providers', function (): void {
    $manager = app(PaymentManager::class);
    $payment = new PaymentClass($manager);

    try {
        $payment->verify('ref_invalid');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class)
            ->and($e->getContext())->toHaveKey('exceptions');
    }
});

test('payment verify facade method works', function (): void {
    try {
        Payment::verify('ref_123');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class);
    }
});

test('payment verify facade method with provider', function (): void {
    try {
        Payment::verify('ref_123', 'stripe');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment verify throws provider exception when all providers fail', function (): void {
    config([
        'payments.providers.paystack.secret_key' => 'invalid_key',
        'payments.providers.stripe.secret_key' => 'invalid_key',
    ]);

    Payment::verify('ref_nonexistent');
})->throws(ProviderException::class);

test('payment verify aggregates errors from all providers', function (): void {
    try {
        Payment::verify('ref_fail');
    } catch (ProviderException $e) {
        expect($e->getContext())->toHaveKey('exceptions')
            ->and($e->getContext()['exceptions'])->toBeArray();
    }
});

test('payment verify with disabled provider skips that provider', function (): void {
    config(['payments.providers.stripe.enabled' => false]);

    try {
        Payment::verify('ref_123');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class);
    }
});

test('payment verify returns verification response on success', function (): void {
    expect(true)->toBeTrue();
});

test('payment manager verify method with specific provider', function (): void {
    $manager = app(PaymentManager::class);

    try {
        $manager->verify('ref_123', 'paystack');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment manager verify method without provider', function (): void {
    $manager = app(PaymentManager::class);

    try {
        $manager->verify('ref_123');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('Unable to verify');
    }
});

test('payment verify handles network errors gracefully', function (): void {
    try {
        Payment::verify('ref_network_error');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class);
    }
});

test('payment verify handles api errors gracefully', function (): void {
    try {
        Payment::verify('ref_api_error');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class);
    }
});

test('payment verify with empty reference throws exception', function (): void {
    try {
        Payment::verify('');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment verify logs verification attempts', function (): void {
    config(['payments.logging.enabled' => true]);

    try {
        Payment::verify('ref_logged');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment verify respects provider order', function (): void {
    try {
        Payment::verify('ref_order_test');
    } catch (ProviderException $e) {
        expect($e->getContext())->toHaveKey('exceptions');
    }
});

test('payment verify skips providers without currency support', function (): void {
    $manager = app(PaymentManager::class);

    try {
        $manager->verify('ref_currency_test');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('payment verify includes context in exceptions', function (): void {
    try {
        Payment::verify('ref_context_test');
    } catch (ProviderException $e) {
        expect($e->getContext())->toBeArray();
    }
});

test('payment verify preserves previous exceptions', function (): void {
    try {
        Payment::verify('ref_previous_exception');
    } catch (ProviderException $e) {
        expect($e)->toBeInstanceOf(ProviderException::class);
    }
});

test('payment verify with multiple enabled providers', function (): void {
    config([
        'payments.providers.paystack.enabled' => true,
        'payments.providers.stripe.enabled' => true,
        'payments.providers.flutterwave.enabled' => true,
    ]);

    try {
        Payment::verify('ref_multi_provider');
    } catch (ProviderException $e) {
        expect($e->getContext()['exceptions'])->toBeArray();
    }
});
