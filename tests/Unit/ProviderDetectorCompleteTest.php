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

test('provider detector detects all known providers', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYSTACK_ref_123'))->toBe('paystack')
        ->and($detector->detectFromReference('FLW_ref_123'))->toBe('flutterwave')
        ->and($detector->detectFromReference('MON_ref_123'))->toBe('monnify')
        ->and($detector->detectFromReference('STRIPE_ref_123'))->toBe('stripe')
        ->and($detector->detectFromReference('PAYPAL_ref_123'))->toBe('paypal');
});

test('provider detector is case insensitive for prefixes', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('paystack_ref_123'))->toBe('paystack')
        ->and($detector->detectFromReference('PayStack_ref_123'))->toBe('paystack')
        ->and($detector->detectFromReference('flw_ref_123'))->toBe('flutterwave')
        ->and($detector->detectFromReference('MON_ref_123'))->toBe('monnify');
});

test('provider detector returns null for references without prefix', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('ref_123'))->toBeNull()
        ->and($detector->detectFromReference('unknown_ref_123'))->toBeNull()
        ->and($detector->detectFromReference(''))->toBeNull();
});

test('provider detector requires underscore after prefix', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYSTACKref123'))->toBeNull()
        ->and($detector->detectFromReference('PAYSTACK'))->toBeNull()
        ->and($detector->detectFromReference('PAYSTACK-ref-123'))->toBeNull();
});

test('provider detector registerPrefix adds custom prefix', function (): void {
    $detector = new ProviderDetector;

    $detector->registerPrefix('SQUARE', 'square');

    expect($detector->detectFromReference('SQUARE_ref_123'))->toBe('square')
        ->and($detector->detectFromReference('square_ref_123'))->toBe('square');
});

test('provider detector registerPrefix is case insensitive for prefix', function (): void {
    $detector = new ProviderDetector;

    $detector->registerPrefix('custom', 'custom_provider');

    expect($detector->detectFromReference('CUSTOM_ref_123'))->toBe('custom_provider')
        ->and($detector->detectFromReference('custom_ref_123'))->toBe('custom_provider');
});

test('provider detector registerPrefix allows chaining', function (): void {
    $detector = new ProviderDetector;

    $result = $detector->registerPrefix('CUSTOM1', 'custom1')
        ->registerPrefix('CUSTOM2', 'custom2');

    expect($result)->toBe($detector)
        ->and($detector->detectFromReference('CUSTOM1_ref_123'))->toBe('custom1')
        ->and($detector->detectFromReference('CUSTOM2_ref_123'))->toBe('custom2');
});

test('provider detector getPrefixes returns all registered prefixes', function (): void {
    $detector = new ProviderDetector;

    $prefixes = $detector->getPrefixes();

    expect($prefixes)->toHaveKeys(['PAYSTACK', 'FLW', 'MON', 'STRIPE', 'PAYPAL'])
        ->and($prefixes['PAYSTACK'])->toBe('paystack')
        ->and($prefixes['FLW'])->toBe('flutterwave')
        ->and($prefixes['MON'])->toBe('monnify')
        ->and($prefixes['STRIPE'])->toBe('stripe')
        ->and($prefixes['PAYPAL'])->toBe('paypal');
});

test('provider detector getPrefixes includes custom registered prefixes', function (): void {
    $detector = new ProviderDetector;

    $detector->registerPrefix('CUSTOM', 'custom');

    $prefixes = $detector->getPrefixes();

    expect($prefixes)->toHaveKey('CUSTOM')
        ->and($prefixes['CUSTOM'])->toBe('custom');
});

test('provider detector handles long references', function (): void {
    $detector = new ProviderDetector;

    expect($detector->detectFromReference('PAYSTACK_'.str_repeat('a', 100)))->toBe('paystack')
        ->and($detector->detectFromReference('FLW_'.str_repeat('b', 50)))->toBe('flutterwave');
});
