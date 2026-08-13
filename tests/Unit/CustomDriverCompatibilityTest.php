<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * Third-party driver compatibility.
 *
 * docs/custom-drivers.md documents DriverInterface as the contract and
 * extending AbstractDriver as merely the usual convenience. DriverFactory and
 * PaymentManager::driver() are both typed to DriverInterface accordingly.
 *
 * So a driver that implements DriverInterface and nothing more is a fully
 * legitimate driver, and every code path PayZephyr runs must work with one.
 * Regression: chargeWithFallback() called isCurrencySupported() and
 * getCachedHealthCheck(), neither of which is on DriverInterface - they only
 * exist on AbstractDriver. A pure-interface driver therefore hit
 * "Call to undefined method" mid-charge, at runtime, on the payment path.
 */

/**
 * Implements DriverInterface EXACTLY - no extra methods, nothing from
 * AbstractDriver. Do not add convenience methods to this class; its whole
 * purpose is to be the minimum a third-party driver can legally be.
 */
function makeBareInterfaceDriver(string $name, array $currencies = ['NGN']): DriverInterface
{
    return new class($name, $currencies) implements DriverInterface
    {
        public int $chargeCalls = 0;

        public int $healthCheckCalls = 0;

        /** @param array<int, string> $currencies */
        public function __construct(
            private readonly string $driverName,
            private readonly array $currencies
        ) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            $this->chargeCalls++;

            return new ChargeResponseDTO(
                reference: $request->reference ?? 'ref_bare',
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_bare',
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO(
                reference: $reference,
                status: 'success',
                amount: 100.0,
                currency: 'NGN',
                provider: $this->driverName,
            );
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return true;
        }

        public function healthCheck(): bool
        {
            $this->healthCheckCalls++;

            return true;
        }

        public function getName(): string
        {
            return $this->driverName;
        }

        public function getSupportedCurrencies(): array
        {
            return $this->currencies;
        }

        public function extractWebhookReference(array $payload): ?string
        {
            return null;
        }

        public function extractWebhookStatus(array $payload): string
        {
            return 'success';
        }

        public function extractWebhookChannel(array $payload): ?string
        {
            return null;
        }

        public function resolveVerificationId(string $reference, string $providerId): string
        {
            return $reference;
        }
    };
}

function managerWithBareDriver(DriverInterface $driver): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, ['primary' => $driver]);

    return $manager;
}

function bareChargeRequest(string $reference, string $currency = 'NGN'): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => $currency,
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.fallback' => null,
        // Health checks ON - this is what exercises getCachedHealthCheck().
        'payments.health_check.enabled' => true,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('a driver implementing only DriverInterface can complete a charge', function () {
    $driver = makeBareInterfaceDriver('primary');

    $response = managerWithBareDriver($driver)->chargeWithFallback(bareChargeRequest('order_bare_1'));

    expect($response->reference)->toBe('order_bare_1')
        ->and($driver->chargeCalls)->toBe(1);
});

test('the health check still runs for a driver without getCachedHealthCheck', function () {
    // The fallback must actually perform the check, not silently skip it -
    // skipping would route payments to a provider known to be down.
    $driver = makeBareInterfaceDriver('primary');

    managerWithBareDriver($driver)->chargeWithFallback(bareChargeRequest('order_bare_2'));

    expect($driver->healthCheckCalls)->toBeGreaterThanOrEqual(1);
});

test('currency support is still enforced for a driver without isCurrencySupported', function () {
    // The fallback must not degrade to "assume every currency is supported" -
    // that would send a charge to a provider that cannot process it.
    $driver = makeBareInterfaceDriver('primary', ['NGN']);

    // USD is unsupported, so the provider is skipped and the chain is
    // exhausted - the charge must never reach the driver.
    expect(fn () => managerWithBareDriver($driver)->chargeWithFallback(bareChargeRequest('order_bare_3', 'USD')))
        ->toThrow(ProviderException::class);

    expect($driver->chargeCalls)->toBe(0);
});

test('a driver that does provide getCachedHealthCheck still uses the cached path', function () {
    // The guard must prefer the driver's own cached implementation when it
    // exists, not always fall back - otherwise every AbstractDriver-based
    // provider would lose health-check caching and hit its API on each charge.
    $driver = new class extends KenDeNigerian\PayZephyr\Drivers\AbstractDriver
    {
        public int $cachedCalls = 0;

        protected string $name = 'primary';

        public function __construct()
        {
            parent::__construct(['secret_key' => 'test', 'currencies' => ['NGN']]);
        }

        public function getCachedHealthCheck(): bool
        {
            $this->cachedCalls++;

            return true;
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO(
                reference: $request->reference ?? 'ref',
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code',
                status: 'pending',
                provider: 'primary',
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO(
                reference: $reference, status: 'success', amount: 100.0,
                currency: 'NGN', provider: 'primary',
            );
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return true;
        }

        public function healthCheck(): bool
        {
            return true;
        }

        public function extractWebhookReference(array $payload): ?string
        {
            return null;
        }

        public function extractWebhookStatus(array $payload): string
        {
            return 'success';
        }

        public function extractWebhookChannel(array $payload): ?string
        {
            return null;
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }
    };

    managerWithBareDriver($driver)->chargeWithFallback(bareChargeRequest('order_cached'));

    expect($driver->cachedCalls)->toBe(1);
});

test('currency matching is case-insensitive for a bare interface driver, matching AbstractDriver', function () {
    $driver = makeBareInterfaceDriver('primary', ['ngn']);

    $response = managerWithBareDriver($driver)->chargeWithFallback(bareChargeRequest('order_bare_4', 'NGN'));

    expect($response->reference)->toBe('order_bare_4')
        ->and($driver->chargeCalls)->toBe(1);
});
