<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

/*
 * The manager reads its configuration, its session cache and a transaction's
 * metadata through typed readers. Each of these is a value of the wrong shape
 * that used to surface as a TypeError, or be read as its opposite.
 */

function typedConfigManager(array $config): PaymentManager
{
    config($config);
    app()->forgetInstance('payments.config');

    return new PaymentManager;
}

function resolveVerificationContextOf(PaymentManager $manager, string $reference, ?string $provider = null): array
{
    return (new ReflectionMethod($manager, 'resolveVerificationContext'))->invoke($manager, $reference, $provider);
}

function seedTypedConfigTransaction(string $reference, string $provider, array $metadata): void
{
    PaymentTransaction::create([
        'reference' => $reference,
        'provider' => $provider,
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'metadata' => $metadata,
    ]);
}

test('with no default and no provider configured, the manager says so', function () {
    $manager = typedConfigManager(['payments.default' => null, 'payments.providers' => []]);

    expect(fn () => $manager->getDefaultDriver())->toThrow(
        DriverNotFoundException::class,
        'No payment provider is configured: set payments.default, or add a provider under payments.providers'
    );
});

test('the first configured provider is the default when none is named', function () {
    $manager = typedConfigManager([
        'payments.default' => null,
        'payments.providers' => ['stripe' => ['driver' => 'stripe', 'secret_key' => 'sk_test_x']],
    ]);

    expect($manager->getDefaultDriver())->toBe('stripe');
});

test('a provider switched off with the string an env file produces is off', function (mixed $off) {
    $manager = typedConfigManager([
        'payments.providers' => [
            'paystack' => ['driver' => 'paystack', 'secret_key' => 'sk_test_x', 'enabled' => true],
            'stripe' => ['driver' => 'stripe', 'secret_key' => 'sk_test_x', 'enabled' => $off],
        ],
    ]);

    expect(array_keys($manager->getEnabledProviders()))->toBe(['paystack'])
        ->and(fn () => $manager->driver('stripe'))
        ->toThrow(DriverNotFoundException::class, 'Payment driver [stripe] not found or disabled');
})->with(['false', 'off', '0', 0]);

test('a provider entry that is not an array is not an enabled provider', function () {
    $manager = typedConfigManager([
        'payments.providers' => [
            'paystack' => ['driver' => 'paystack', 'secret_key' => 'sk_test_x'],
            'stripe' => 'sk_test_x',
        ],
    ]);

    expect(array_keys($manager->getEnabledProviders()))->toBe(['paystack'])
        ->and(fn () => $manager->driver('stripe'))->toThrow(DriverNotFoundException::class);
});

test('a numeric provider id in stored metadata is the verification id', function () {
    $manager = typedConfigManager([
        'payments.logging.enabled' => true,
        'payments.providers.stripe' => ['driver' => 'stripe', 'secret_key' => 'sk_test_x', 'enabled' => true],
    ]);
    seedTypedConfigTransaction('ORDER_NUMERIC_ID', 'stripe', ['_provider_id' => 987654]);

    expect(resolveVerificationContextOf($manager, 'ORDER_NUMERIC_ID'))
        ->toBe(['provider' => 'stripe', 'id' => '987654']);
});

test('a numeric provider id survives when the transaction names a provider that is no longer configured', function () {
    $manager = typedConfigManager(['payments.logging.enabled' => true]);
    seedTypedConfigTransaction('ORDER_GONE', 'retired', ['session_id' => 4242]);

    expect(resolveVerificationContextOf($manager, 'ORDER_GONE'))
        ->toBe(['provider' => 'retired', 'id' => '4242']);
});

test('a provider id that is not a string or a number falls back to the reference', function () {
    $manager = typedConfigManager(['payments.logging.enabled' => true]);
    seedTypedConfigTransaction('ORDER_ODD', 'retired', ['_provider_id' => ['nested' => 'value']]);

    expect(resolveVerificationContextOf($manager, 'ORDER_ODD'))
        ->toBe(['provider' => 'retired', 'id' => 'ORDER_ODD']);
});

test('a session cache entry of the wrong shape is ignored, not fatal', function (mixed $cached) {
    $manager = typedConfigManager(['payments.logging.enabled' => false]);
    Cache::put('payzephyr:session:ORDER_CACHED', $cached, 60);

    expect(resolveVerificationContextOf($manager, 'ORDER_CACHED', 'paystack'))
        ->toBe(['provider' => 'paystack', 'id' => 'ORDER_CACHED']);
})->with([
    'a string' => ['not-an-array'],
    'no id' => [['provider' => 'paystack']],
    'an array where the provider belongs' => [['provider' => ['paystack'], 'id' => 'abc']],
]);
