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
 * Abstract Class AbstractDriver
 *
 * Base class for all payment drivers
 */
abstract class AbstractDriver implements DriverInterface
{
    protected Client $client;

    protected array $config;

    protected string $name;

    /**
     * Current request being processed (for idempotency key access)
     */
    protected ?ChargeRequest $currentRequest = null;

    /**
     * AbstractDriver constructor.
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
        $this->initializeClient();
    }

    /**
     * Validate driver configuration
     *
     * @throws InvalidConfigurationException
     */
    abstract protected function validateConfig(): void;

    /**
     * Initialize HTTP client
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
     * Get default HTTP headers
     */
    abstract protected function getDefaultHeaders(): array;

    /**
     * Make HTTP request with automatic idempotency key injection
     *
     * @throws GuzzleException
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
     * Get provider-specific idempotency header
     *
     * Override this in specific drivers if they use different header names
     */
    protected function getIdempotencyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * Set the current request context (called by charge method)
     */
    protected function setCurrentRequest(ChargeRequest $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Clear the current request context
     */
    protected function clearCurrentRequest(): void
    {
        $this->currentRequest = null;
    }

    /**
     * Parse JSON response
     */
    protected function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Get provider name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return $this->config['currencies'] ?? [];
    }

    /**
     * Generate unique reference
     *
     * @throws RandomException
     */
    protected function generateReference(?string $prefix = null): string
    {
        $prefix = $prefix ?? strtoupper($this->getName());

        return $prefix.'_'.time().'_'.bin2hex(random_bytes(8));
    }

    /**
     * Check if currency is supported
     */
    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->getSupportedCurrencies());
    }

    /**
     * Get cached health check result
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
     * Log activity
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (config('payments.logging.enabled', true)) {
            logger()->{$level}("[{$this->getName()}] $message", $context);
        }
    }

    /**
     * Set HTTP client (for testing)
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }
}
