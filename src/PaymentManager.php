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
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
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
     * @throws ProviderException|Throwable
     */
    public function chargeWithFallback(ChargeRequestDTO $request, ?array $providers = null): ChargeResponseDTO
    {
        $providers = $providers ?? $this->getFallbackChain();
        $exceptions = [];

        $claimed = $this->claimChargeInFlight($request);

        try {
            return $this->attemptChargeChain($request, $providers, $exceptions, $claimed);
        } catch (Throwable $e) {
            if ($claimed !== null && ! $this->isAmbiguousOutcome($e)) {
                $this->releaseChargeClaim($claimed);
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $providers
     * @param  array<string, Throwable>  $exceptions
     *
     * @throws ProviderException
     */
    private function attemptChargeChain(
        ChargeRequestDTO $request,
        array $providers,
        array $exceptions,
        ?string $claimed
    ): ChargeResponseDTO {
        foreach ($providers as $providerName) {
            try {
                $driver = $this->driver($providerName);

                if ($this->config['health_check']['enabled'] ?? true) {
                    if (! $this->driverIsHealthy($driver)) {
                        $this->log('warning', "Provider [$providerName] failed health check, skipping");

                        continue;
                    }
                }

                if (! $this->driverSupportsCurrency($driver, $request->currency)) {
                    $this->log('info', "Provider [$providerName] does not support currency $request->currency");

                    continue;
                }

                $response = $driver->charge($request);
                $this->completeSuccessfulCharge($request, $response, $providerName);

                return $response;
            } catch (Throwable $e) {
                if ($e instanceof ChargeException && $e->isAmbiguousProviderOutcome()) {
                    $this->log('error', "Provider [$providerName] charge outcome is ambiguous - not retrying against a fallback provider", [
                        'error' => $e->getMessage(),
                        'provider' => $providerName,
                        'reference' => $request->reference,
                    ]);

                    throw ProviderException::withContext(
                        "Charge via [$providerName] timed out or lost its response before payment status could be confirmed. ".
                        "PayZephyr will not retry this against a different provider, since [$providerName] may have already ".
                        'processed it - doing so could charge the customer twice. Verify the transaction with '.
                        "[$providerName] directly, or via Payment::verify(), before attempting another charge.",
                        ['provider' => $providerName, 'ambiguous_outcome' => true],
                        $e
                    );
                }

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

    /**
     * Health check for any driver, including one that implements only
     * DriverInterface.
     *
     * getCachedHealthCheck() lives on AbstractDriver, not on DriverInterface,
     * but DriverFactory and driver() are both typed to the interface and
     * docs/custom-drivers.md documents the interface as the contract - so a
     * driver without it is legitimate. Calling it unguarded raised
     * "Call to undefined method", which the fallback loop's catch(Throwable)
     * then swallowed into a generic "all providers failed", silently skipping
     * that provider forever.
     *
     * The fallback is the uncached healthCheck() the interface does
     * guarantee: same answer, just without the caching AbstractDriver adds.
     *
     * Public so every caller that needs a driver's health - including the
     * health endpoint in PaymentServiceProvider - goes through one
     * implementation rather than re-deriving this guard.
     */
    public function driverIsHealthy(DriverInterface $driver): bool
    {
        if (method_exists($driver, 'getCachedHealthCheck')) {
            return $driver->getCachedHealthCheck();
        }

        return $driver->healthCheck();
    }

    /**
     * Currency support for any driver, including one that implements only
     * DriverInterface. See driverIsHealthy() for why the guard exists.
     *
     * The fallback mirrors AbstractDriver::isCurrencySupported() exactly,
     * including its case-insensitive comparison, using getSupportedCurrencies()
     * which the interface does guarantee. It must never degrade to "assume
     * supported" - that would route a charge to a provider that cannot
     * process the currency.
     */
    private function driverSupportsCurrency(DriverInterface $driver, string $currency): bool
    {
        if (method_exists($driver, 'isCurrencySupported')) {
            return $driver->isCurrencySupported($currency);
        }

        return in_array(
            strtoupper($currency),
            array_map(strtoupper(...), $driver->getSupportedCurrencies()),
            true
        );
    }

    /**
     * How long a charge in-flight claim is held. Long enough to cover a
     * provider call plus a realistic double-submit/retry storm; short enough
     * that a crashed process never leaves a payment permanently unchargeable.
     *
     * This is deliberately NOT a substitute for provider-side idempotency over
     * longer windows - that is what the (reference-derived) idempotency key
     * sent to the provider is for. See docs/idempotency.md.
     */
    private const CHARGE_CLAIM_TTL_SECONDS = 300;

    /**
     * Atomically claim a logical payment before any provider is contacted.
     *
     * Returns the claim key on success, or null when the request carries no
     * stable identity to claim (no caller-supplied reference) - in which case
     * no protection is possible and the charge proceeds unguarded.
     *
     * @throws ProviderException when the same logical payment is already in flight.
     */
    private function claimChargeInFlight(ChargeRequestDTO $request): ?string
    {
        $reference = $request->reference;

        if ($reference === null || $reference === '') {
            return null;
        }

        $key = $this->cacheKey('charge-inflight', $reference);

        try {
            $won = Cache::add($key, true, self::CHARGE_CLAIM_TTL_SECONDS);
        } catch (Throwable $e) {
            $this->log('error', 'Could not claim charge in-flight lock - proceeding without double-submission protection', [
                'error' => $e->getMessage(),
                'reference' => $reference,
            ]);

            return null;
        }

        if (! $won) {
            $this->log('warning', 'Rejected a duplicate in-flight charge submission', [
                'reference' => $reference,
            ]);

            throw ProviderException::withContext(
                "A charge for reference [$reference] is already in progress or was recently submitted. ".
                'PayZephyr did not contact a provider again, because doing so could charge the customer twice. '.
                "Verify the outcome with Payment::verify('$reference') before retrying.",
                ['reference' => $reference, 'duplicate_submission' => true]
            );
        }

        return $key;
    }

    private function releaseChargeClaim(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            $this->log('warning', 'Failed to release charge in-flight claim', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether an exception means "the provider may already have processed this
     * charge" - the case where retrying anything is unsafe.
     */
    private function isAmbiguousOutcome(Throwable $e): bool
    {
        if ($e instanceof ChargeException && $e->isAmbiguousProviderOutcome()) {
            return true;
        }

        if ($e instanceof ProviderException && ($e->getContext()['ambiguous_outcome'] ?? false)) {
            return true;
        }

        return $e instanceof ProviderException && ($e->getContext()['duplicate_submission'] ?? false);
    }

    /**
     * Run every post-success side effect of a charge the provider has already
     * accepted: session caching, local transaction logging, PaymentInitiated.
     *
     * Guaranteed not to throw. A charge that has really happened must be
     * reported to the caller as successful even if PayZephyr's own local
     * bookkeeping is completely broken (cache backend down, database
     * unreachable, a listener throwing) - the alternative is telling the caller
     * a real charge failed, which invites them to charge the customer again.
     * The blanket catch is the point of this method, not an oversight.
     */
    protected function completeSuccessfulCharge(
        ChargeRequestDTO $request,
        ChargeResponseDTO $response,
        string $providerName
    ): void {
        try {
            $this->cacheSessionData($response->reference, $providerName, $response->accessCode);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to cache session data after a successful charge', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
                'provider' => $providerName,
            ]);
        }

        try {
            $this->logTransaction($request, $response, $providerName);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to log transaction after a successful charge', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
                'provider' => $providerName,
            ]);
        }

        try {
            PaymentInitiated::dispatch($request, $response, $providerName);
        } catch (Throwable $e) {
            $this->log('error', 'A PaymentInitiated listener failed after a successful charge', [
                'error' => $e->getMessage(),
                'reference' => $response->reference,
                'provider' => $providerName,
            ]);
        }

        try {
            $this->log('info', "Payment charged successfully via [$providerName]", [
                'reference' => $response->reference,
            ]);
        } catch (Throwable) {
            // Even the logger failing must not surface as a charge failure.
        }
    }

    protected function logTransaction(ChargeRequestDTO $request, ChargeResponseDTO $response, string $provider): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        // Deliberately unguarded: the single caller already runs this inside
        // the post-success try/catch that absorbs and logs any failure here.
        // A second catch would just duplicate that, and made the caller's
        // guard unreachable - which meant the guard protecting the package's
        // central invariant could never actually be exercised by a test.
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

                try {
                    if ($response->isSuccessful()) {
                        PaymentVerificationSuccess::dispatch($reference, $response, $providerName);
                    } elseif ($response->isFailed()) {
                        PaymentVerificationFailed::dispatch($reference, $response, $providerName);
                    }
                } catch (Throwable $e) {
                    $this->log('error', 'A payment verification listener failed after a successful verify', [
                        'error' => $e->getMessage(),
                        'reference' => $reference,
                        'provider' => $providerName,
                    ]);
                }

                try {
                    Cache::forget($this->cacheKey('session', $reference));
                } catch (Throwable $e) {
                    $this->log('error', 'Failed to clear cached session data after verification', [
                        'error' => $e->getMessage(),
                        'reference' => $reference,
                    ]);
                }

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
            // auth() returns the Auth Factory, which only exposes guard()/
            // shouldUse() - check()/id() live on the Guard it resolves.
            if (function_exists('auth') && auth()->guard()->check()) {
                $this->cachedContext = 'user_'.auth()->guard()->id();

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
        if ($fallback && $fallback !== $chain[0]) {
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
