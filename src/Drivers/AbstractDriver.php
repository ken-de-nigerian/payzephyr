<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;

/**
 * AbstractDriver - Base Class for All Payment Providers
 *
 * This is the parent class that all payment provider drivers extend.
 * It provides common functionality like HTTP requests, health checks,
 * currency validation, and reference generation.
 * 
 * Each provider (Paystack, Stripe, etc.) extends this class and implements
 * the provider-specific logic.
 */
abstract class AbstractDriver implements DriverInterface
{
    protected Client $client;

    protected array $config;

    protected string $name;

    /**
     * The payment request currently being processed.
     * Used to access the idempotency key when making API requests.
     */
    protected ?ChargeRequest $currentRequest = null;

    /**
     * Create a new payment driver instance.
     *
     * @throws InvalidConfigurationException If required config is missing.
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
            'verify' => ! ($this->config['testing_mode'] ?? false),
            'headers' => $this->getDefaultHeaders(),
        ]);
    }

    /**
     * Get the default HTTP headers needed for API requests (like Authorization).
     * Each driver implements this with their provider's specific headers.
     */
    abstract protected function getDefaultHeaders(): array;

    /**
     * Make an HTTP request to the payment provider's API.
     *
     * Automatically adds the idempotency key header if one was provided,
     * which prevents accidentally charging the same payment twice.
     *
     * @throws GuzzleException If the HTTP request fails.
     */
    protected function makeRequest(string $method, string $uri, array $options = []): ResponseInterface
    {
        // Inject idempotency key if available and not already set
        if ($this->currentRequest?->idempotencyKey && ! isset($options['headers']['Idempotency-Key'])) {
            $options['headers'] = array_merge(
                $options['headers'] ?? [],
                $this->getIdempotencyHeader($this->currentRequest->idempotencyKey)
            );
        }

        return $this->client->request($method, $uri, $options);
    }

    /**
     * Get the HTTP header name and value for idempotency.
     * Most providers use 'Idempotency-Key', but some might use different names.
     * Override this in specific drivers if needed.
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Store the current payment request so we can access it later (for idempotency keys).
     */
    protected function setCurrentRequest(ChargeRequest $request): void
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
     * The result is cached for a few minutes so we don't check too often.
     * This prevents slowing down payments with repeated health checks.
     */
    public function getCachedHealthCheck(): bool
    {
        $cacheKey = 'payments.health.'.$this->getName();
        $cacheTtl = config('payments.health_check.cache_ttl', 300);

        return Cache::remember($cacheKey, $cacheTtl, function () {
            return $this->healthCheck();
        });
    }

    /**
     * Write a log message (for debugging and monitoring).
     * 
     * @param  string  $level  Log level: 'info', 'warning', 'error', etc.
     * @param  string  $message  The log message
     * @param  array  $context  Extra data to include in the log
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (config('payments.logging.enabled', true)) {
            logger()->{$level}("[{$this->getName()}] $message", $context);
        }
    }

    /**
     * Replace the HTTP client (mainly used for testing with mock clients).
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }
}
