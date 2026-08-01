<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use ArrayObject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\ProviderDetectorInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Events\PaymentInitiated;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationFailed;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationSuccess;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

final class PaymentManager
{
    use LogsToPaymentChannel;

    /** @var array<string, DriverInterface> */
    protected array $drivers = [];

    /** @var array<string, mixed> */
    protected array $config;

    protected ProviderDetectorInterface $providerDetector;

    protected DriverFactory $driverFactory;

    protected MetadataSanitizer $metadataSanitizer;

    protected TransactionRepositoryInterface $transactionRepository;

    protected ?string $cachedContext = null;

    public function __construct(
        ?ProviderDetectorInterface $providerDetector = null,
        ?DriverFactory $driverFactory = null,
        ?MetadataSanitizer $metadataSanitizer = null,
        ?TransactionRepositoryInterface $transactionRepository = null
    ) {
        $this->config = app('payments.config') ?? Config::get('payments', []);
        $this->providerDetector = $providerDetector ?? app(ProviderDetectorInterface::class);
        $this->driverFactory = $driverFactory ?? app(DriverFactory::class);
        $this->metadataSanitizer = $metadataSanitizer ?? app(MetadataSanitizer::class);
        $this->transactionRepository = $transactionRepository ?? app(TransactionRepositoryInterface::class);
    }

    /**
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
     * @param  array<int, string>|null  $providers
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

                if ($this->config['health_check']['enabled'] ?? true) {
                    if (! $driver->getCachedHealthCheck()) {
                        $this->log('warning', "Provider [$providerName] failed health check, skipping");

                        continue;
                    }
                }

                if (! $driver->isCurrencySupported($request->currency)) {
                    $this->log('info', "Provider [$providerName] does not support currency $request->currency");

                    continue;
                }

                $response = $driver->charge($request);
                $this->cacheSessionData($response->reference, $providerName, $response->accessCode);

                $this->logTransaction($request, $response, $providerName);

                PaymentInitiated::dispatch($request, $response, $providerName);

                $this->log('info', "Payment charged successfully via [$providerName]", [
                    'reference' => $response->reference,
                ]);

                return $response;
            } catch (Throwable $e) {
                $exceptions[$providerName] = $e;
                $this->log('error', "Provider [$providerName] failed", [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                    'request_context' => [
                        'amount' => $request->amount,
                        'currency' => $request->currency,
                        'reference' => $request->reference,
                    ],
                    'provider_config' => [
                        'name' => $providerName,
                        'enabled' => ($this->config['providers'][$providerName]['enabled'] ?? true),
                    ],
                ]);
            }
        }

        throw ProviderException::withContext(
            'All payment providers failed',
            ['exceptions' => array_map(fn ($e) => $e->getMessage(), $exceptions)]
        );
    }

    protected function logTransaction(ChargeRequestDTO $request, ChargeResponseDTO $response, string $provider): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        try {
            $rawMetadata = array_merge($request->metadata, $response->metadata, [
                '_provider_id' => $response->accessCode,
            ]);

            $metadata = $this->metadataSanitizer->sanitize($rawMetadata);
            $customer = $request->customer ? $this->metadataSanitizer->sanitize($request->customer) : null;

            $this->transactionRepository->create([
                'reference' => $response->reference,
                'provider' => $provider,
                'status' => $response->status,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'email' => $request->email,
                'channel' => null,
                'metadata' => $metadata,
                'customer' => $customer,
                'paid_at' => null,
            ]);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to log transaction', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
            ]);
        }
    }

    /**
     * @throws ProviderException|DriverNotFoundException
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

                if ($response->isSuccessful()) {
                    PaymentVerificationSuccess::dispatch($reference, $response, $providerName);
                } elseif ($response->isFailed()) {
                    PaymentVerificationFailed::dispatch($reference, $response, $providerName);
                }

                Cache::forget($this->cacheKey('session', $reference));

                return $response;
            } catch (Throwable $e) {
                $exceptions[$providerName] = $e;
                $this->log('error', "Provider [$providerName] verification failed", [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'reference' => $reference,
                    'provider' => $providerName,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        throw ProviderException::withContext(
            "Unable to verify payment reference: $reference",
            ['exceptions' => array_map(fn ($e) => $e->getMessage(), $exceptions)]
        );
    }

    protected function cacheSessionData(string $reference, string $provider, string $providerId): void
    {
        $config = app('payments.config') ?? config('payments', []);
        $cacheTtl = $config['cache']['session_ttl'] ?? 3600;

        Cache::put(
            $this->cacheKey('session', $reference),
            [
                'provider' => $provider,
                'id' => $providerId,
            ],
            now()->addSeconds($cacheTtl)
        );
    }

    protected function cacheKey(string $type, string $identifier): string
    {
        $prefix = 'payzephyr';
        $context = $this->getCacheContext();

        if ($context) {
            return sprintf('%s:%s:%s:%s', $prefix, $context, $type, $identifier);
        }

        return sprintf('%s:%s:%s', $prefix, $type, $identifier);
    }

    protected function getCacheContext(): ?string
    {
        if ($this->cachedContext !== null) {
            return $this->cachedContext;
        }

        try {
            if (function_exists('auth') && auth()->check()) {
                $this->cachedContext = 'user_'.auth()->id();

                return $this->cachedContext;
            }

            if (app()->bound('request')) {
                $request = app('request');

                if ($request->user()) {
                    $this->cachedContext = 'user_'.$request->user()->id;

                    return $this->cachedContext;
                }

                if ($request->session() && $request->session()->has('user_id')) {
                    $this->cachedContext = 'user_'.$request->session()->get('user_id');

                    return $this->cachedContext;
                }
            }
        } catch (Throwable $e) {
            $this->log('debug', 'Could not resolve cache context', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->cachedContext = null;

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DriverNotFoundException
     */
    protected function resolveVerificationContext(string $reference, ?string $explicitProvider): array
    {
        $cached = Cache::get($this->cacheKey('session', $reference));
        if ($cached) {
            $driver = $this->driver($cached['provider']);
            $verificationId = $driver->resolveVerificationId($reference, $cached['id']);

            return [
                'provider' => $cached['provider'],
                'id' => $verificationId,
            ];
        }

        if ($this->config['logging']['enabled'] ?? true) {
            $transaction = $this->transactionRepository->findByReference($reference);
            if ($transaction instanceof PaymentTransaction) {
                try {
                    /** @var string $provider */
                    $provider = $transaction->getAttribute('provider');
                    $driver = $this->driver($provider);

                    $metadata = $transaction->getAttribute('metadata');
                    if ($metadata instanceof ArrayObject) {
                        $metadata = $metadata->getArrayCopy();
                    } elseif (! is_array($metadata)) {
                        $metadata = [];
                    }

                    $providerId = $metadata['_provider_id']
                        ?? $metadata['session_id']
                        ?? $metadata['order_id']
                        ?? $reference;

                    $verificationId = $driver->resolveVerificationId($reference, $providerId);

                    return [
                        'provider' => $provider,
                        'id' => $verificationId,
                    ];
                } catch (DriverNotFoundException) {
                    $metadata = $transaction->getAttribute('metadata');
                    if ($metadata instanceof ArrayObject) {
                        $metadata = $metadata->getArrayCopy();
                    } elseif (is_string($metadata)) {
                        $decoded = json_decode($metadata, true);
                        $metadata = is_array($decoded) ? $decoded : [];
                    } elseif (! is_array($metadata)) {
                        $metadata = [];
                    }

                    $providerId = $metadata['_provider_id']
                        ?? $metadata['session_id']
                        ?? $metadata['order_id']
                        ?? $reference;

                    /** @var string $transactionProvider */
                    $transactionProvider = $transaction->getAttribute('provider');

                    return [
                        'provider' => $transactionProvider,
                        'id' => $providerId,
                    ];
                }
            }
        }

        $provider = $explicitProvider ?? $this->detectProviderFromReference($reference);

        return [
            'provider' => $provider,
            'id' => $reference,
        ];
    }

    protected function detectProviderFromReference(string $reference): ?string
    {
        return $this->providerDetector->detectFromReference($reference);
    }

    protected function updateTransactionFromVerification(string $reference, VerificationResponseDTO $response): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        try {
            $statusEnum = PaymentStatus::tryFromString($response->status);

            $this->transactionRepository->updateIfNotSuccessful($reference, [
                'status' => $response->status,
                'channel' => $response->channel,
                'paid_at' => $statusEnum?->isSuccessful() ? ($response->paidAt ?? now()) : null,
            ]);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to update transaction from verification', [
                'error' => $e->getMessage(),
                'reference' => $reference,
            ]);
        }
    }

    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? array_key_first($this->config['providers'] ?? []);
    }

    /**
     * @return array<int, string>
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
     * @return array<int, string>
     */
    public function getEnabledProviders(): array
    {
        return array_filter(
            $this->config['providers'] ?? [],
            fn ($config) => $config['enabled'] ?? true
        );
    }
}
