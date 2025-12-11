<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\ProviderDetectorInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use Throwable;

/**
 * Payment manager.
 */
class PaymentManager
{
    protected array $drivers = [];

    protected array $config;

    protected ProviderDetectorInterface $providerDetector;

    protected DriverFactory $driverFactory;

    public function __construct(
        ?ProviderDetectorInterface $providerDetector = null,
        ?DriverFactory $driverFactory = null
    ) {
        $this->config = app('payments.config') ?? Config::get('payments', []);
        $this->providerDetector = $providerDetector ?? app(ProviderDetectorInterface::class);
        $this->driverFactory = $driverFactory ?? app(DriverFactory::class);
    }

    /**
     * Get payment provider driver.
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

        $driverName = $config['driver'] ?? $name;
        $this->drivers[$name] = $this->driverFactory->create($driverName, $config);

        return $this->drivers[$name];
    }

    /**
     * Process payment with automatic fallback.
     *
     * @param  array<string>|null  $providers
     *
     * @throws ProviderException
     */
    public function chargeWithFallback(ChargeRequestDTO $request, ?array $providers = null): ChargeResponseDTO
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
                $this->cacheSessionData($response->reference, $providerName, $response->accessCode);

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
     * Log transaction to database.
     */
    protected function logTransaction(ChargeRequestDTO $request, ChargeResponseDTO $response, string $provider): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        try {
            $metadata = array_merge($request->metadata, $response->metadata, [
                '_provider_id' => $response->accessCode,
            ]);

            PaymentTransaction::create([
                'reference' => $response->reference,
                'provider' => $provider,
                'status' => $response->status,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'email' => $request->email,
                'channel' => null,
                'metadata' => $metadata,
                'customer' => $request->customer,
                'paid_at' => null,
            ]);
        } catch (Throwable $e) {
            logger()->error('Failed to log transaction', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
            ]);
        }
    }

    /**
     * Verify payment by reference.
     *
     * @throws ProviderException
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponseDTO
    {
        $resolution = $this->resolveVerificationContext($reference, $provider);
        $providers = $resolution['provider'] ? [$resolution['provider']] : array_keys($this->getEnabledProviders());
        $verificationId = $resolution['id'];

        $exceptions = [];

        foreach ($providers as $providerName) {
            try {
                $driver = $this->driver($providerName);
                $response = $driver->verify($verificationId);
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
     * Cache session data.
     */
    protected function cacheSessionData(string $reference, string $provider, string $providerId): void
    {
        Cache::put(
            $this->cacheKey('session', $reference),
            [
                'provider' => $provider,
                'id' => $providerId,
            ],
            now()->addHour()
        );
    }

    /**
     * Generate cache key.
     */
    protected function cacheKey(string $type, string $identifier): string
    {
        return sprintf('payzephyr:%s:%s', $type, $identifier);
    }

    /**
     * Resolve verification context.
     */
    protected function resolveVerificationContext(string $reference, ?string $explicitProvider): array
    {
        // 1. Check Cache (Fastest & Works without DB)
        $cached = Cache::get($this->cacheKey('session', $reference));
        if ($cached) {
            $driver = $this->driver($cached['provider']);
            $verificationId = $driver->resolveVerificationId($reference, $cached['id']);

            return [
                'provider' => $cached['provider'],
                'id' => $verificationId,
            ];
        }

        // 2. Check Database (If logging is enabled)
        if ($this->config['logging']['enabled'] ?? true) {
            $transaction = PaymentTransaction::where('reference', $reference)->first();
            if ($transaction) {
                try {
                    $driver = $this->driver($transaction->provider);

                    // Ensure metadata is an array (handle both array and ArrayObject)
                    $metadata = $transaction->metadata;
                    if ($metadata instanceof \ArrayObject) {
                        $metadata = $metadata->getArrayCopy();
                    } elseif (!is_array($metadata)) {
                        $metadata = [];
                    }

                    $providerId = $metadata['_provider_id']
                        ?? $metadata['session_id']
                        ?? $metadata['order_id']
                        ?? $reference;

                    $verificationId = $driver->resolveVerificationId($reference, $providerId);

                    return [
                        'provider' => $transaction->provider,
                        'id' => $verificationId,
                    ];
                } catch (DriverNotFoundException) {
                    // Provider not configured - fall back to using reference
                    // Ensure metadata is an array (handle both array and ArrayObject)
                    $metadata = $transaction->metadata;
                    if ($metadata instanceof \ArrayObject) {
                        $metadata = $metadata->getArrayCopy();
                    } elseif (is_string($metadata)) {
                        // Decode JSON string if cast didn't apply
                        $decoded = json_decode($metadata, true);
                        $metadata = is_array($decoded) ? $decoded : [];
                    } elseif (!is_array($metadata)) {
                        $metadata = [];
                    }

                    $providerId = $metadata['_provider_id']
                        ?? $metadata['session_id']
                        ?? $metadata['order_id']
                        ?? $reference;

                    return [
                        'provider' => $transaction->provider,
                        'id' => $providerId,
                    ];
                }
            }
        }

        // 3. Heuristic / Explicit Fallback
        $provider = $explicitProvider ?? $this->detectProviderFromReference($reference);

        return [
            'provider' => $provider,
            'id' => $reference,
        ];
    }

    /**
     * Detect provider from reference.
     */
    protected function detectProviderFromReference(string $reference): ?string
    {
        return $this->providerDetector->detectFromReference($reference);
    }

    /**
     * Update transaction from verification.
     */
    protected function updateTransactionFromVerification(string $reference, VerificationResponseDTO $response): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        try {
            DB::transaction(function () use ($reference, $response) {
                $transaction = PaymentTransaction::where('reference', $reference)
                    ->lockForUpdate() // Prevent race conditions
                    ->first();

                if ($transaction && ! $transaction->isSuccessful()) {
                    $statusEnum = PaymentStatus::tryFromString($response->status);
                    $transaction->update([
                        'status' => $response->status,
                        'channel' => $response->channel,
                        'paid_at' => $statusEnum?->isSuccessful() ? ($response->paidAt ?? now()) : null,
                    ]);
                }
            });
        } catch (Throwable $e) {
            logger()->error('Failed to update transaction from verification', [
                'error' => $e->getMessage(),
                'reference' => $reference,
            ]);
        }
    }

    /**
     * Get default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? array_key_first($this->config['providers'] ?? []);
    }

    /**
     * Get fallback provider chain.
     */
    public function getFallbackChain(): array
    {
        $chain = [$this->getDefaultDriver()];

        $fallback = $this->config['fallback'] ?? null;
        if ($fallback && $fallback !== '' && $fallback !== $chain[0]) {
            $chain[] = $fallback;
        }

        return array_unique(array_filter($chain));
    }

    /**
     * Get enabled providers.
     */
    public function getEnabledProviders(): array
    {
        return array_filter(
            $this->config['providers'] ?? [],
            fn ($config) => $config['enabled'] ?? true
        );
    }
}
