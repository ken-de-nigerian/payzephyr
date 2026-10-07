<?php

use KenDeNigerian\PayZephyr\Services\ProviderDetector;

beforeEach(function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'enabled' => true,
        ],
        'payments.providers.flutterwave' => [
            'driver' => 'flutterwave',
            'reference_prefix' => 'FLW',
            'enabled' => true,
        ],
        'payments.providers.monnify' => [
            'driver' => 'monnify',
            'reference_prefix' => 'MON',
            'enabled' => true,
        ],
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'enabled' => true,
        ],
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'enabled' => true,
        ],
    ]);
    app()->forgetInstance('payments.config');
});

test('provider detector detects paystack from reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYSTACK_ref_123'))->toBe('paystack')
        ->and($detector->detectFromReference('paystack_ref_123'))->toBe('paystack')
        ->and($detector->detectFromReference('PAYSTACK_abc'))->toBe('paystack');
});

test('provider detector detects flutterwave from reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('FLW_ref_123'))->toBe('flutterwave')
        ->and($detector->detectFromReference('flw_ref_123'))->toBe('flutterwave');
});

test('provider detector detects monnify from reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('MON_ref_123'))->toBe('monnify')
        ->and($detector->detectFromReference('mon_ref_123'))->toBe('monnify');
});

test('provider detector detects stripe from reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('STRIPE_ref_123'))->toBe('stripe')
        ->and($detector->detectFromReference('stripe_ref_123'))->toBe('stripe');
});

test('provider detector detects paypal from reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYPAL_ref_123'))->toBe('paypal')
        ->and($detector->detectFromReference('paypal_ref_123'))->toBe('paypal');
});

test('provider detector returns null for unknown reference', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('unknown_ref_123'))->toBeNull()
        ->and($detector->detectFromReference('ref_123'))->toBeNull()
        ->and($detector->detectFromReference(''))->toBeNull();
});

test('provider detector registerPrefix adds custom prefix', function (): void {
    $detector = new ProviderDetector;

    $detector->registerPrefix('SQUARE', 'square');

    expect($detector->detectFromReference('SQUARE_ref_123'))->toBe('square')
        ->and($detector->detectFromReference('square_ref_123'))->toBe('square');
});

test('provider detector registerPrefix allows chaining', function (): void {
    $detector = new ProviderDetector;

    $result = $detector->registerPrefix('CUSTOM', 'custom');

    expect($result)->toBe($detector)
        ->and($detector->detectFromReference('CUSTOM_ref_123'))->toBe('custom');
});

test('provider detector getPrefixes returns all prefixes', function (): void {
    $detector = new ProviderDetector;

    $prefixes = $detector->getPrefixes();

    expect($prefixes)->toHaveKeys(['PAYSTACK', 'FLW', 'MON', 'STRIPE', 'PAYPAL'])
        ->and($prefixes['PAYSTACK'])->toBe('paystack')
        ->and($prefixes['FLW'])->toBe('flutterwave');
});

test('provider detector registerPrefix is case insensitive for prefix', function (): void {
    $detector = new ProviderDetector;

    $detector->registerPrefix('custom', 'custom');

    expect($detector->detectFromReference('CUSTOM_ref_123'))->toBe('custom')
        ->and($detector->detectFromReference('custom_ref_123'))->toBe('custom');
});

test('provider detector requires underscore after prefix', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYSTACKref123'))->toBeNull()
        ->and($detector->detectFromReference('PAYSTACK'))->toBeNull();
});
