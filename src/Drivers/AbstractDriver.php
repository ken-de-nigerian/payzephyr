<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Traits\HasLogSanitization;
use KenDeNigerian\PayZephyr\Traits\HasNetworkErrorHandling;
use KenDeNigerian\PayZephyr\Traits\HasWebhookValidation;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;

/**
 * AbstractDriver - Base Class for All Payment Providers
 *
 * This is the parent class that all payment provider drivers extend.
 * It provides common functionality like HTTP requests, health checks,
 * currency validation, and reference generation.
 */
abstract class AbstractDriver implements DriverInterface
{
    use HasLogSanitization;
    use HasNetworkErrorHandling;
    use HasWebhookValidation;

    protected Client $client;

    /** @var array<string, mixed> */
    protected array $config;

    protected string $name;

    /**
     * The payment request currently being processed.
     * Used to access the idempotency key when making API requests.
     */
    protected ?ChargeRequestDTO $currentRequest = null;

    /**
     * Status normalizer instance.
     * Can be injected for testing or to use custom normalizer.
     */
    protected ?StatusNormalizer $statusNormalizer = null;

    /**
     * Channel mapper instance.
     * Can be injected for testing or to use custom mapper.
     */
    protected ?ChannelMapper $channelMapper = null;

    /**
     * Subscription repository instance.
     * Can be injected for testing or to use a custom repository.
     */
    protected ?SubscriptionRepositoryInterface $subscriptionRepository = null;

    /**
     * Refund repository instance.
     * Can be injected for testing or to use a custom repository.
     */
    protected ?RefundRepositoryInterface $refundRepository = null;

    /**
     * Create a new payment driver instance.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidConfigurationException If required, config is missing.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
        $this->initializeClient();
    }

    /**
     * Check that all required configuration is present (API keys, etc.).
     * Each driver implements this to check for their specific requirements.
     *
     * @throws InvalidConfigurationException If something is missing.
     */
    abstract protected function validateConfig(): void;

    /**
     * Set up the HTTP client for making API requests to the payment provider.
     */
    protected function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => $this->config['base_url'] ?? '',
            'timeout' => $this->config['timeout'] ?? 30,
            'verify' => true,
            'headers' => $this->getDefaultHeaders(),
        ]);
    }

    /**
     * Get the default HTTP headers needed for API requests (like Authorization).
     * Each driver implements this with their provider's specific headers.
     *
     * @return array<string, string>
     */
    abstract protected function getDefaultHeaders(): array;

    /**
     * Make an HTTP request to the payment provider's API.
     *
     * Automatically adds the idempotency key header if one was provided,
     * which prevents accidentally charging the same payment twice.
     *
     * Network errors are caught and wrapped with more user-friendly messages
     * to prevent crashes and provide better error context.
     *
     * @param  array<string, mixed>  $options  Additional Guzzle options (json, query, etc.)
     *
     * @throws ChargeException If the HTTP request fails.
     */
    protected function makeRequest(string $method, string $uri, array $options = []): ResponseInterface
    {
        if ($this->currentRequest?->idempotencyKey) {
            $idempotencyHeaders = $this->getIdempotencyHeader($this->currentRequest->idempotencyKey);

            $headersAlreadySet = false;
            foreach (array_keys($idempotencyHeaders) as $headerName) {
                if (isset($options['headers'][$headerName])) {
                    $headersAlreadySet = true;
                    break;
                }
            }

            if (! $headersAlreadySet) {
                $options['headers'] = array_merge(
                    $options['headers'] ?? [],
                    $idempotencyHeaders
                );
            }
        }

        try {
            return $this->client->request($method, $uri, $options);
        } catch (GuzzleException $e) {

            $this->handleNetworkError($e, $method, $uri);

            $context = [
                'method' => $method,
                'uri' => $uri,
                'provider' => $this->getName(),
            ];

            throw ChargeException::withContext(
                $this->getNetworkErrorMessage($e),
                $context,
                $e
            );
        }
    }

    /**
     * Get the HTTP header name and value for idempotency.
     * Most providers use 'Idempotency-Key', but some might use different names.
     * Override this in specific drivers if needed.
     *
     * @return array<string, string>
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Store the current payment request, so we can access it later (for idempotency keys).
     */
    protected function setCurrentRequest(ChargeRequestDTO $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Clear the stored request (cleanup after processing).
     */
    protected function clearCurrentRequest(): void
    {
        $this->currentRequest = null;
    }

    /**
     * Convert the HTTP response body from JSON to a PHP array.
     *
     * @return array<string, mixed>
     */
    protected function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Get the name of this payment provider (e.g., 'paystack', 'stripe').
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the list of currencies this provider supports (e.g., ['NGN', 'USD', 'EUR']).
     */
    /**
     * @return array<int, string>
     */
    public function getSupportedCurrencies(): array
    {
        return $this->config['currencies'] ?? [];
    }

    /**
     * Create a unique transaction reference (like 'PAYSTACK_1234567890_abc123def456').
     *
     * Format: PREFIX_TIMESTAMP_RANDOMHEX
     *
     * @param  string|null  $prefix  Custom prefix (defaults to provider name in uppercase)
     *
     * @throws RandomException If random number generation fails.
     */
    protected function generateReference(?string $prefix = null): string
    {
        $prefix = $prefix ?? strtoupper($this->getName());

        return $prefix.'_'.time().'_'.bin2hex(random_bytes(8));
    }

    /**
     * Check if this provider supports a specific currency (e.g., 'NGN', 'USD').
     */
    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->getSupportedCurrencies());
    }

    /**
     * Check if the provider is working (cached result).
     *
     * The result is cached for a few minutes, so we don't check too often.
     * This prevents slowing down payments with repeated health checks.
     */
    public function getCachedHealthCheck(): bool
    {
        $cacheKey = 'payments.health.'.$this->getName();
        $config = app('payments.config') ?? config('payments', []);
        $cacheTtl = $config['health_check']['cache_ttl'] ?? PaymentConstants::HEALTH_CHECK_CACHE_TTL_SECONDS;

        return Cache::remember($cacheKey, $cacheTtl, function () {
            return $this->healthCheck();
        });
    }

    /**
     * Write a log message (for debugging and monitoring).
     *
     * @param  string  $level  Log level: 'info', 'warning', 'error', etc.
     * @param  string  $message  The log message
     * @param  array<string, mixed>  $context  Extra data to include in the log
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $config = app('payments.config') ?? config('payments', []);
        if (! ($config['logging']['enabled'] ?? true)) {
            return;
        }

        $sanitizedContext = $this->sanitizeLogContext($context);
        $channelName = $config['logging']['channel'] ?? 'payments';

        try {
            Log::channel($channelName)->{$level}("[{$this->getName()}] $message", $sanitizedContext);
        } catch (InvalidArgumentException) {
            Log::{$level}("[{$this->getName()}] $message", $sanitizedContext);
        }
    }

    /**
     * Helper to append a query parameter to a URL.
     * Handles cases where the URL already has query params.
     */
    protected function appendQueryParam(?string $url, string $key, string $value): ?string
    {
        if (! $url) {
            return null;
        }

        $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';

        return "$url$separator$key=$value";
    }

    /**
     * Replace the HTTP client (mainly used for testing with mock clients).
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    /**
     * Normalize provider-specific status values to internal standard statuses.
     *
     * This method delegates to the StatusNormalizer service, which can be
     * extended without modifying this class (OCP compliance).
     *
     * Drivers can override this method to provide custom normalization logic,
     * or they can register provider-specific mappings with the normalizer.
     *
     * @param  string  $status  The provider-specific status value
     * @return string Normalized status value
     */
    protected function normalizeStatus(string $status): string
    {
        return $this->getStatusNormalizer()->normalize($status, $this->getName());
    }

    /**
     * Get the status normalizer instance.
     * Uses dependency injection if available, otherwise creates a new instance.
     */
    protected function getStatusNormalizer(): StatusNormalizer
    {
        if ($this->statusNormalizer === null) {
            $this->statusNormalizer = app(StatusNormalizer::class);
        }

        return $this->statusNormalizer;
    }

    /**
     * Set a custom status normalizer (mainly for testing).
     *
     * @return $this
     */
    public function setStatusNormalizer(StatusNormalizer $normalizer): self
    {
        $this->statusNormalizer = $normalizer;

        return $this;
    }

    /**
     * Get the channel mapper instance.
     * Uses dependency injection if available, otherwise creates a new instance.
     */
    protected function getChannelMapper(): ChannelMapper
    {
        if ($this->channelMapper === null) {
            $this->channelMapper = app(ChannelMapper::class);
        }

        return $this->channelMapper;
    }

    /**
     * Set a custom channel mapper (mainly for testing).
     *
     * @return $this
     */
    public function setChannelMapper(ChannelMapper $mapper): self
    {
        $this->channelMapper = $mapper;

        return $this;
    }

    /**
     * Get the subscription repository instance.
     * Uses dependency injection if available, otherwise resolves from the container.
     */
    protected function getSubscriptionRepository(): SubscriptionRepositoryInterface
    {
        if ($this->subscriptionRepository === null) {
            $this->subscriptionRepository = app(SubscriptionRepositoryInterface::class);
        }

        return $this->subscriptionRepository;
    }

    /**
     * Set a custom subscription repository (mainly for testing).
     *
     * @return $this
     */
    public function setSubscriptionRepository(SubscriptionRepositoryInterface $repository): self
    {
        $this->subscriptionRepository = $repository;

        return $this;
    }

    /**
     * Get the refund repository instance.
     * Uses dependency injection if available, otherwise resolves from the container.
     */
    protected function getRefundRepository(): RefundRepositoryInterface
    {
        if ($this->refundRepository === null) {
            $this->refundRepository = app(RefundRepositoryInterface::class);
        }

        return $this->refundRepository;
    }

    /**
     * Set a custom refund repository (mainly for testing).
     *
     * @return $this
     */
    public function setRefundRepository(RefundRepositoryInterface $repository): self
    {
        $this->refundRepository = $repository;

        return $this;
    }

    /**
     * Map unified channels to a provider-specific format.
     * If no channels are provided, returns null (provider uses its defaults).
     * Only returns default channels if explicitly needed by the provider.
     *
     * @param  ChargeRequestDTO  $request  The payment request
     * @return array<string>|null Provider-specific channels or null if not applicable
     */
    protected function mapChannels(ChargeRequestDTO $request): ?array
    {
        $mapper = $this->getChannelMapper();

        if (! $mapper->supportsChannels($this->getName())) {
            return null;
        }

        if (! empty($request->channels)) {
            return $mapper->mapChannels($request->channels, $this->getName());
        }

        return null;
    }

    /**
     * Get the transaction reference from a raw webhook payload.
     * Default implementation that can be overridden by specific drivers.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['reference'] ?? $payload['transactionReference'] ?? null;
    }

    /**
     * Get the payment status from a raw webhook payload (in provider-native format).
     * The normalizer will take care of converting this to standard format.
     * Default implementation that can be overridden by specific drivers.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['status'] ?? $payload['paymentStatus'] ?? 'unknown';
    }

    /**
     * Get the payment channel (e.g., 'card', 'bank_transfer') from a raw webhook payload.
     * Default implementation that can be overridden by specific drivers.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['channel'] ?? $payload['paymentMethod'] ?? null;
    }

    /**
     * Extract a provider-native event identifier from a raw webhook payload,
     * for event-level idempotency.
     *
     * Deliberately not part of DriverInterface - a driver implementing that
     * interface directly (rather than extending this class) isn't required
     * to define it; callers check method_exists() and fall back to a
     * content-hash key when absent, so this stays fully backward compatible.
     *
     * Checks common top-level field names (works as-is for Stripe, PayPal,
     * Square, Mollie). Providers that nest their event data under a
     * sub-object (Paystack, Flutterwave, Monnify, OPay) override this, the
     * same way they override extractWebhookTimestamp().
     *
     * @param  array<string, mixed>  $payload
     */
    public function extractWebhookEventId(array $payload): ?string
    {
        $value = $payload['id'] ?? $payload['event_id'] ?? $payload['payment_id'] ?? null;

        return $value !== null ? (string) $value : null;
    }

    /**
     * Resolve the actual ID needed for verification (which may differ from the
     * internal reference or the provider's Access Code).
     * Default implementation uses the provider's internal ID.
     *
     * @param  string  $reference  The package's unique reference (e.g., PAYSTACK_...)
     * @param  string  $providerId  The provider's internal ID saved during charge (e.g., Paystack access_code)
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        return $providerId;
    }
}
