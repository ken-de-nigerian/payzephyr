<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Support\Facades\Config;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use Throwable;

/**
 * Class PaymentManager
 *
 * Manages payment drivers and handles fallback logic
 */
class PaymentManager
{
    protected array $drivers = [];

    protected array $config;

    public function __construct()
    {
        $this->config = Config::get('payments', []);
    }

    /**
     * Get driver instance
     *
     * @throws DriverNotFoundException
     */
    public function driver(?string $name = null): DriverInterface
    {
        $name = $name ?? $this->getDefaultDriver();

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $config = $this->config['providers'][$name] ?? null;

        if (! $config || ! ($config['enabled'] ?? true)) {
            throw new DriverNotFoundException("Payment driver [$name] not found or disabled");
        }

        $driverClass = $this->resolveDriverClass($config['driver']);

        if (! class_exists($driverClass)) {
            throw new DriverNotFoundException("Driver class [$driverClass] not found");
        }

        $this->drivers[$name] = new $driverClass($config);

        return $this->drivers[$name];
    }

    /**
     * Attempt charge across multiple providers with fallback
     *
     * @throws ProviderException
     */
    public function chargeWithFallback(ChargeRequest $request, ?array $providers = null): ChargeResponse
    {
        $providers = $providers ?? $this->getFallbackChain();
        $exceptions = [];

        foreach ($providers as $providerName) {
            try {
                $driver = $this->driver($providerName);

                // Health check if enabled
                if ($this->config['health_check']['enabled'] ?? true) {
                    if (! $driver->getCachedHealthCheck()) {
                        logger()->warning("Provider [$providerName] failed health check, skipping");

                        continue;
                    }
                }

                // Check currency support
                if (! $driver->isCurrencySupported($request->currency)) {
                    logger()->info("Provider [$providerName] does not support currency $request->currency");

                    continue;
                }

                $response = $driver->charge($request);

                // Log to Database
                if (config('payments.logging.enabled', true)) {
                    PaymentTransaction::create([
                        'reference' => $response->reference,
                        'provider' => $providerName,
                        'status' => $response->status,
                        'amount' => $request->amount,
                        'currency' => $request->currency,
                        'email' => $request->email,
                        'metadata' => json_encode($request->metadata), // Ensure array is JSON
                        'description' => $request->description,
                        'paid_at' => null, // Not paid yet
                    ]);
                }

                logger()->info("Payment charged successfully via [$providerName]", [
                    'reference' => $response->reference,
                ]);

                return $response;
            } catch (Throwable $e) {
                $exceptions[$providerName] = $e;
                logger()->error("Provider [$providerName] failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        throw ProviderException::withContext(
            'All payment providers failed',
            ['exceptions' => array_map(fn ($e) => $e->getMessage(), $exceptions)]
        );
    }

    /**
     * Verify payment across all providers
     *
     * @throws ProviderException
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponse
    {
        $providers = $provider ? [$provider] : array_keys($this->config['providers'] ?? []);
        $exceptions = [];

        foreach ($providers as $providerName) {
            try {
                $driver = $this->driver($providerName);

                return $driver->verify($reference);
            } catch (Throwable $e) {
                $exceptions[$providerName] = $e;
            }
        }

        throw ProviderException::withContext(
            "Unable to verify payment reference: $reference",
            ['exceptions' => array_map(fn ($e) => $e->getMessage(), $exceptions)]
        );
    }

    /**
     * Get default driver name
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? array_key_first($this->config['providers'] ?? []);
    }

    /**
     * Get fallback provider chain
     */
    public function getFallbackChain(): array
    {
        $chain = [$this->getDefaultDriver()];

        if ($fallback = $this->config['fallback'] ?? null) {
            $chain[] = $fallback;
        }

        return array_unique(array_filter($chain));
    }

    /**
     * Resolve driver class from config
     */
    protected function resolveDriverClass(string $driver): string
    {
        $map = [
            'paystack' => PaystackDriver::class,
            'flutterwave' => FlutterwaveDriver::class,
            'monnify' => MonnifyDriver::class,
            'stripe' => StripeDriver::class,
            'paypal' => PayPalDriver::class,
        ];

        return $map[$driver] ?? $driver;
    }

    /**
     * Get all enabled providers
     */
    public function getEnabledProviders(): array
    {
        return array_filter(
            $this->config['providers'] ?? [],
            fn ($config) => $config['enabled'] ?? true
        );
    }
}
