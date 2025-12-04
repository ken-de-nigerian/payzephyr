<?php

use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\PaymentServiceProvider;

beforeEach(function () {
    $this->provider = new PaymentServiceProvider($this->app);
});

test('service provider registers payment manager as singleton', function () {
    $this->provider->register();

    $manager1 = app(PaymentManager::class);
    $manager2 = app(PaymentManager::class);

    expect($manager1)->toBe($manager2);
});

test('service provider registers payment class', function () {
    $this->provider->register();

    $payment = app(Payment::class);

    expect($payment)->toBeInstanceOf(Payment::class);
});

test('service provider merges config from package', function () {
    $this->provider->register();

    expect(config('payments'))->toBeArray()
        ->and(config('payments.default'))->not->toBeNull();
});

test('config has all required keys', function () {
    $this->provider->register();

    $config = config('payments');

    expect($config)->toHaveKeys([
        'default',
        'fallback',
        'providers',
        'currency',
        'webhook',
        'health_check',
        'logging',
        'security',
        'testing_mode',
    ]);
});

test('config providers section has paystack', function () {
    $this->provider->register();

    expect(config('payments.providers'))->toHaveKey('paystack');
});

test('config providers section has all five providers', function () {
    $this->provider->register();

    $providers = config('payments.providers');

    expect($providers)->toHaveKeys([
        'paystack',
        'flutterwave',
        'monnify',
        'stripe',
        'paypal',
    ]);
});

test('default provider is configurable', function () {
    config(['payments.default' => 'stripe']);

    expect(config('payments.default'))->toBe('stripe');
});

test('fallback provider is configurable', function () {
    config(['payments.fallback' => 'paystack']);

    expect(config('payments.fallback'))->toBe('paystack');
});

test('webhook settings are configurable', function () {
    config([
        'payments.webhook.path' => '/custom/webhook',
        'payments.webhook.verify_signature' => false,
    ]);

    expect(config('payments.webhook.path'))->toBe('/custom/webhook')
        ->and(config('payments.webhook.verify_signature'))->toBeFalse();
});

test('health check is configurable', function () {
    config([
        'payments.health_check.enabled' => false,
        'payments.health_check.cache_ttl' => 600,
    ]);

    expect(config('payments.health_check.enabled'))->toBeFalse()
        ->and(config('payments.health_check.cache_ttl'))->toBe(600);
});

test('logging is configurable', function () {
    config([
        'payments.logging.enabled' => true,
        'payments.logging.table' => 'custom_transactions',
    ]);

    expect(config('payments.logging.enabled'))->toBeTrue()
        ->and(config('payments.logging.table'))->toBe('custom_transactions');
});

test('currency settings are configurable', function () {
    config([
        'payments.currency.default' => 'USD',
        'payments.currency.converter' => 'custom',
    ]);

    expect(config('payments.currency.default'))->toBe('USD')
        ->and(config('payments.currency.converter'))->toBe('custom');
});

test('testing mode is configurable', function () {
    config(['payments.testing_mode' => true]);

    expect(config('payments.testing_mode'))->toBeTrue();
});

test('provider can be enabled/disabled', function () {
    config(['payments.providers.paystack.enabled' => false]);

    expect(config('payments.providers.paystack.enabled'))->toBeFalse();
});

test('provider credentials are configurable', function () {
    config([
        'payments.providers.paystack.secret_key' => 'sk_test_custom',
        'payments.providers.paystack.public_key' => 'pk_test_custom',
    ]);

    expect(config('payments.providers.paystack.secret_key'))->toBe('sk_test_custom')
        ->and(config('payments.providers.paystack.public_key'))->toBe('pk_test_custom');
});

test('provider currencies are configurable', function () {
    config(['payments.providers.paystack.currencies' => ['NGN', 'USD', 'GHS']]);

    expect(config('payments.providers.paystack.currencies'))
        ->toBe(['NGN', 'USD', 'GHS']);
});

test('security settings are configurable', function () {
    config([
        'payments.security.encrypt_keys' => true,
        'payments.security.rate_limit.enabled' => true,
        'payments.security.rate_limit.max_attempts' => 100,
    ]);

    expect(config('payments.security.encrypt_keys'))->toBeTrue()
        ->and(config('payments.security.rate_limit.enabled'))->toBeTrue()
        ->and(config('payments.security.rate_limit.max_attempts'))->toBe(100);
});

test('webhook middleware is configurable', function () {
    config(['payments.webhook.middleware' => ['api', 'throttle:60,1']]);

    expect(config('payments.webhook.middleware'))
        ->toBe(['api', 'throttle:60,1']);
});

test('webhook tolerance is configurable', function () {
    config(['payments.webhook.tolerance' => 600]);

    expect(config('payments.webhook.tolerance'))->toBe(600);
});

test('health check timeout is configurable', function () {
    config(['payments.health_check.timeout' => 10]);

    expect(config('payments.health_check.timeout'))->toBe(10);
});

test('each provider has required configuration keys', function () {
    $this->provider->register();

    $providers = ['paystack', 'flutterwave', 'monnify', 'stripe', 'paypal'];

    foreach ($providers as $provider) {
        $config = config("payments.providers.$provider");

        expect($config)->toHaveKeys([
            'driver',
            'enabled',
            'currencies',
        ]);
    }
});

test('paystack has all required config keys', function () {
    $this->provider->register();

    $config = config('payments.providers.paystack');

    expect($config)->toHaveKeys([
        'driver',
        'secret_key',
        'public_key',
        'currencies',
        'enabled',
    ]);
});

test('flutterwave has all required config keys', function () {
    $this->provider->register();

    $config = config('payments.providers.flutterwave');

    expect($config)->toHaveKeys([
        'driver',
        'secret_key',
        'public_key',
        'encryption_key',
        'currencies',
        'enabled',
    ]);
});

test('monnify has all required config keys', function () {
    $this->provider->register();

    $config = config('payments.providers.monnify');

    expect($config)->toHaveKeys([
        'driver',
        'api_key',
        'secret_key',
        'contract_code',
        'currencies',
        'enabled',
    ]);
});

test('stripe has all required config keys', function () {
    $this->provider->register();

    $config = config('payments.providers.stripe');

    expect($config)->toHaveKeys([
        'driver',
        'secret_key',
        'public_key',
        'webhook_secret',
        'currencies',
        'enabled',
    ]);
});

test('paypal has all required config keys', function () {
    $this->provider->register();

    $config = config('payments.providers.paypal');

    expect($config)->toHaveKeys([
        'driver',
        'client_id',
        'client_secret',
        'mode',
        'currencies',
        'enabled',
    ]);
});
