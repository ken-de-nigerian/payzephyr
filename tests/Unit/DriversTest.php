<?php

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;

// Flutterwave Driver Tests
test('flutterwave driver initializes correctly', function () {
    $config = [
        'secret_key' => 'test_secret',
        'public_key' => 'test_public',
        'encryption_key' => 'test_encryption',
        'base_url' => 'https://api.flutterwave.com/v3',
        'currencies' => ['NGN', 'USD', 'EUR'],
    ];

    $driver = new FlutterwaveDriver($config);

    expect($driver->getName())->toBe('flutterwave')
        ->and($driver->getSupportedCurrencies())->toBe(['NGN', 'USD', 'EUR'])
        ->and($driver->isCurrencySupported('NGN'))->toBeTrue()
        ->and($driver->isCurrencySupported('GBP'))->toBeFalse();
});

test('flutterwave driver requires secret key', function () {
    new FlutterwaveDriver(['public_key' => 'test']);
})->throws(InvalidConfigurationException::class);

test('flutterwave driver validates webhook with correct signature', function () {
    $config = [
        'secret_key' => 'test_secret',
        'webhook_secret' => 'webhook_secret',
    ];

    $driver = new FlutterwaveDriver($config);
    $headers = ['verif-hash' => ['webhook_secret']];
    $body = '{"event":"charge.completed"}';

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('flutterwave driver rejects invalid webhook signature', function () {
    $config = [
        'secret_key' => 'test_secret',
        'webhook_secret' => 'webhook_secret',
    ];

    $driver = new FlutterwaveDriver($config);
    $headers = ['verif-hash' => ['wrong_signature']];
    $body = '{"event":"charge.completed"}';

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

// Stripe Driver Tests
test('stripe driver initializes correctly', function () {
    $config = [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['USD', 'EUR', 'GBP'],
    ];

    $driver = new StripeDriver($config);

    expect($driver->getName())->toBe('stripe')
        ->and($driver->getSupportedCurrencies())->toContain('USD')
        ->and($driver->isCurrencySupported('USD'))->toBeTrue();
});

test('stripe driver requires secret key', function () {
    new StripeDriver(['public_key' => 'test']);
})->throws(InvalidConfigurationException::class);

// Monnify Driver Tests
test('monnify driver initializes correctly', function () {
    $config = [
        'api_key' => 'test_api',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'base_url' => 'https://api.monnify.com',
        'currencies' => ['NGN'],
    ];

    $driver = new MonnifyDriver($config);

    expect($driver->getName())->toBe('monnify')
        ->and($driver->getSupportedCurrencies())->toBe(['NGN'])
        ->and($driver->isCurrencySupported('NGN'))->toBeTrue()
        ->and($driver->isCurrencySupported('USD'))->toBeFalse();
});

test('monnify driver requires api key and secret', function () {
    new MonnifyDriver(['contract_code' => 'test']);
})->throws(InvalidConfigurationException::class);

test('monnify driver requires contract code', function () {
    new MonnifyDriver([
        'api_key' => 'test',
        'secret_key' => 'test',
    ]);
})->throws(InvalidConfigurationException::class);

test('monnify driver validates webhook signature', function () {
    $config = [
        'api_key' => 'test_api',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
    ];

    $driver = new MonnifyDriver($config);
    $body = '{"eventType":"SUCCESSFUL_TRANSACTION"}';
    $signature = hash_hmac('sha512', $body, 'test_secret');
    $headers = ['monnify-signature' => [$signature]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

// PayPal Driver Tests
test('paypal driver initializes correctly', function () {
    $config = [
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'base_url' => 'https://api.sandbox.paypal.com',
        'currencies' => ['USD', 'EUR'],
    ];

    $driver = new PayPalDriver($config);

    expect($driver->getName())->toBe('paypal')
        ->and($driver->getSupportedCurrencies())->toContain('USD');
});

test('paypal driver requires client id and secret', function () {
    new PayPalDriver(['mode' => 'sandbox']);
})->throws(InvalidConfigurationException::class);

// Paystack Driver Tests
test('paystack driver validates correct webhook signature', function () {
    $config = ['secret_key' => 'test_secret'];
    $driver = new PaystackDriver($config);

    $body = '{"event":"charge.success"}';
    $signature = hash_hmac('sha512', $body, 'test_secret');

    $headers = ['x-paystack-signature' => [$signature]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('paystack driver rejects invalid webhook signature', function () {
    $config = ['secret_key' => 'test_secret'];
    $driver = new PaystackDriver($config);

    $body = '{"event":"charge.success"}';
    $headers = ['x-paystack-signature' => ['invalid_signature']];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('paystack driver rejects webhook without signature', function () {
    $config = ['secret_key' => 'test_secret'];
    $driver = new PaystackDriver($config);

    $body = '{"event":"charge.success"}';
    $headers = [];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

// Currency Support Tests
test('all drivers check currency support correctly', function () {
    $paystackDriver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => ['NGN', 'USD'],
    ]);

    $stripeDriver = new StripeDriver([
        'secret_key' => 'test',
        'currencies' => ['USD', 'EUR'],
    ]);

    expect($paystackDriver->isCurrencySupported('NGN'))->toBeTrue()
        ->and($paystackDriver->isCurrencySupported('EUR'))->toBeFalse()
        ->and($stripeDriver->isCurrencySupported('EUR'))->toBeTrue()
        ->and($stripeDriver->isCurrencySupported('NGN'))->toBeFalse();
});

test('drivers return correct supported currencies list', function () {
    $config = [
        'secret_key' => 'test',
        'currencies' => ['USD', 'EUR', 'GBP'],
    ];

    $driver = new PaystackDriver($config);

    expect($driver->getSupportedCurrencies())
        ->toBe(['USD', 'EUR', 'GBP'])
        ->and($driver->getSupportedCurrencies())->toHaveCount(3);
});

// Driver Name Tests
test('all drivers return correct names', function () {
    $drivers = [
        new PaystackDriver(['secret_key' => 'test']),
        new FlutterwaveDriver(['secret_key' => 'test']),
        new MonnifyDriver(['api_key' => 'test', 'secret_key' => 'test', 'contract_code' => 'test']),
        new StripeDriver(['secret_key' => 'test']),
        new PayPalDriver(['client_id' => 'test', 'client_secret' => 'test']),
    ];

    expect($drivers[0]->getName())->toBe('paystack')
        ->and($drivers[1]->getName())->toBe('flutterwave')
        ->and($drivers[2]->getName())->toBe('monnify')
        ->and($drivers[3]->getName())->toBe('stripe')
        ->and($drivers[4]->getName())->toBe('paypal');
});
