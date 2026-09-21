<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * The order the fallback chain asks its two skip questions in is a performance
 * property, so it is pinned rather than left to whoever edits the loop next.
 *
 * Currency support is an in-memory array comparison. A health check can be a
 * live HTTP round trip whenever the cache has expired. Asking about currency
 * first means a provider that could never take this currency is skipped
 * without a network call on the checkout path.
 */
final class SkipOrderDriver extends AbstractDriver
{
    public int $healthCheckCalls = 0;

    /** @param array<int, string> $currencies */
    public function __construct(private string $providerName, private array $currencies)
    {
        parent::__construct(['currencies' => $currencies]);
        $this->name = $providerName;
    }

    protected function validateConfig(): void {}

    /** @return array<string, string> */
    protected function getDefaultHeaders(): array
    {
        return [];
    }

    public function healthCheck(): bool
    {
        $this->healthCheckCalls++;

        return true;
    }

    public function getCachedHealthCheck(): bool
    {
        return $this->healthCheck();
    }

    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        return new ChargeResponseDTO(
            reference: $request->reference ?? 'ref',
            authorizationUrl: 'https://example.test/pay',
            accessCode: 'code',
            status: 'pending',
            provider: $this->providerName,
        );
    }

    public function verify(string $reference): never
    {
        throw new RuntimeException('not used');
    }

    /** @param array<string, array<int, string>> $headers */
    public function validateWebhook(array $headers, string $body): bool
    {
        return true;
    }
}

/**
 * @param  array<string, DriverInterface>  $drivers
 * @param  array<string, mixed>  $config
 */
function skipOrderManager(array $drivers, array $config): PaymentManager
{
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);

    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, $drivers);

    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);
    $configProperty->setValue($manager, array_replace(
        $configProperty->getValue($manager),
        $config,
    ));

    return $manager;
}

test('a provider that cannot take the currency is skipped without a health check', function () {
    $wrongCurrency = new SkipOrderDriver('wrong_currency', ['USD']);
    $usable = new SkipOrderDriver('usable', ['NGN']);

    $manager = skipOrderManager(
        ['wrong_currency' => $wrongCurrency, 'usable' => $usable],
        ['default' => 'wrong_currency', 'fallback' => 'usable', 'health_check' => ['enabled' => true]],
    );

    $response = $manager->chargeWithFallback(new ChargeRequestDTO(
        100.0, 'NGN', 'a@b.test', null, 'https://example.test/cb'
    ));

    expect($response->provider)->toBe('usable')
        // The whole point: no network was spent asking whether a provider that
        // cannot process NGN happens to be up.
        ->and($wrongCurrency->healthCheckCalls)->toBe(0)
        ->and($usable->healthCheckCalls)->toBe(1);
});

test('health_check.enabled is declared in the shipped config', function () {
    // PaymentManager reads this with a `?? true` fallback. The key went
    // undeclared for long enough that the default could only be discovered by
    // reading the source, so its presence is asserted rather than assumed.
    $shipped = require __DIR__.'/../../config/payments.php';

    expect($shipped['health_check'])->toHaveKey('enabled')
        ->and($shipped['health_check']['enabled'])->toBeTrue();
});

test('the manager honours health_check.enabled being switched off', function () {
    $driver = new SkipOrderDriver('only', ['NGN']);

    $manager = skipOrderManager(
        ['only' => $driver],
        ['default' => 'only', 'fallback' => null, 'health_check' => ['enabled' => false]],
    );

    $manager->chargeWithFallback(new ChargeRequestDTO(
        100.0, 'NGN', 'a@b.test', null, 'https://example.test/cb'
    ));

    expect($driver->healthCheckCalls)->toBe(0);
});
