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
 * PaymentManager - The Brain of the Payment System
 *
 * This class coordinates everything:
 * - Creates and manages payment provider drivers (Paystack, Stripe, etc.)
 * - Handles automatic fallback (if one provider fails, tries the next)
 * - Logs all transactions to the database
 * - Verifies payments across multiple providers
 */
class PaymentManager
{
    /**
     * Cache of payment provider drivers we've already created (so we don't recreate them).
     */
    protected array $drivers = [];

    /**
     * The payment configuration from config/payments.php
     */
    protected array $config;

    public function __construct()
    {
        $this->config = Config::get('payments', []);
    }

    /**
     * Get a payment provider driver (like PaystackDriver, StripeDriver, etc.).
     *
     * If you don't specify a name, it uses the default provider from your config.
     * Drivers are cached so we only create each one once per request.
     *
     * @param  string|null  $name  Provider name like 'paystack', 'stripe', etc. (null = use default)
     * @throws DriverNotFoundException If the provider doesn't exist or is disabled.
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
     * Process a payment, trying multiple providers if needed (automatic fallback).
     *
     * How it works:
     * 1. Checks if the provider is working (health check)
     * 2. Checks if the provider supports the currency (e.g., NGN, USD)
     * 3. Tries to process the payment
     * 4. If successful: saves to database and returns the result
     * 5. If failed: tries the next provider in the list
     *
     * @param  ChargeRequest  $request  The payment details (amount, email, etc.)
     * @param  array|null  $providers  List of providers to try (e.g., ['paystack', 'stripe'])
     *                                 If null, uses the default fallback chain from config.
     * @throws ProviderException If ALL providers fail (none of them could process the payment).
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

                // Log transaction to database
                $this->logTransaction($request, $response, $providerName);

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
     * Save the payment transaction to the database for tracking.
     *
     * This is wrapped in try-catch so that if the database is down,
     * the payment still processes (we just can't log it).
     * The payment itself is more important than the log.
     */
    protected function logTransaction(ChargeRequest $request, ChargeResponse $response, string $provider): void
    {
        if (! config('payments.logging.enabled', true)) {
            return;
        }

        try {
            PaymentTransaction::create([
                'reference' => $response->reference,
                'provider' => $provider,
                'status' => $response->status,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'email' => $request->email,
                'channel' => null, // Will be updated by webhook
                'metadata' => $request->metadata,
                'customer' => $request->customer,
                'paid_at' => null, // Will be updated on verification/webhook
            ]);
        } catch (Throwable $e) {
            // Don't fail the payment if logging fails
            logger()->error('Failed to log transaction', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
            ]);
        }
    }

    /**
     * Check if a payment was successful by looking up the transaction reference.
     *
     * If you don't specify a provider, it searches ALL enabled providers automatically.
     * This is super useful because you might not know which provider processed the payment
     * (especially if fallback was used).
     *
     * @param  string  $reference  The transaction reference to look up
     * @param  string|null  $provider  Optional: check only this provider (e.g., 'paystack')
     * @throws ProviderException If the payment can't be found on any provider.
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponse
    {
        $providers = $provider ? [$provider] : array_keys($this->config['providers'] ?? []);
        $exceptions = [];

        foreach ($providers as $providerName) {
            try {
                $driver = $this->driver($providerName);
                $response = $driver->verify($reference);

                // Update transaction in database
                $this->updateTransactionFromVerification($reference, $response);

                return $response;
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
     * Update the payment record in the database after verifying it.
     *
     * Updates the status (success/failed/pending), payment method used, and when it was paid.
     */
    protected function updateTransactionFromVerification(string $reference, VerificationResponse $response): void
    {
        if (! config('payments.logging.enabled', true)) {
            return;
        }

        try {
            PaymentTransaction::where('reference', $reference)->update([
                'status' => $response->status,
                'channel' => $response->channel,
                'paid_at' => $response->isSuccessful() ? ($response->paidAt ?? now()) : null,
            ]);
        } catch (Throwable $e) {
            logger()->error('Failed to update transaction from verification', [
                'error' => $e->getMessage(),
                'reference' => $reference,
            ]);
        }
    }

    /**
     * Get the default payment provider name from config (e.g., 'paystack').
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? array_key_first($this->config['providers'] ?? []);
    }

    /**
     * Get the list of providers to try in order (default + fallback).
     *
     * Returns something like ['paystack', 'stripe'] - first tries Paystack,
     * then Stripe if Paystack fails.
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
     * Convert a short provider name (like 'paystack') to the full class name.
     *
     * This lets you use simple names in config instead of long class paths.
     * Example: 'paystack' becomes 'KenDeNigerian\PayZephyr\Drivers\PaystackDriver'
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
     * Get all payment providers that are currently enabled in your config.
     */
    public function getEnabledProviders(): array
    {
        return array_filter(
            $this->config['providers'] ?? [],
            fn ($config) => $config['enabled'] ?? true
        );
    }
}
