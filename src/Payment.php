<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use Throwable;

final class Payment
{
    protected PaymentManager $manager;

    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<int, string> */
    protected array $providers = [];

    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    public function amount(float $amount): Payment
    {
        $this->data['amount'] = $amount;

        return $this;
    }

    public function currency(string $currency): Payment
    {
        $this->data['currency'] = strtoupper($currency);

        return $this;
    }

    public function email(string $email): Payment
    {
        $this->data['email'] = $email;

        return $this;
    }

    public function reference(string $reference): Payment
    {
        $this->data['reference'] = $reference;

        return $this;
    }

    public function callback(string $url): Payment
    {
        $this->data['callback_url'] = $url;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): Payment
    {
        $this->data['metadata'] = $metadata;

        return $this;
    }

    public function idempotency(string $key): Payment
    {
        $this->data['idempotency_key'] = $key;

        return $this;
    }

    public function description(string $description): Payment
    {
        $this->data['description'] = $description;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function customer(array $customer): Payment
    {
        $this->data['customer'] = $customer;

        return $this;
    }

    /**
     * @param  array<int, string>  $channels
     */
    public function channels(array $channels): Payment
    {
        $this->data['channels'] = $channels;

        return $this;
    }

    /**
     * @param  string|array<int, string>  $providers
     */
    public function with(string|array $providers): Payment
    {
        $this->providers = is_array($providers) ? $providers : [$providers];

        return $this;
    }

    /**
     * @param  string|array<int, string>  $providers
     */
    public function using(string|array $providers): Payment
    {
        return $this->with($providers);
    }

    /**
     * @throws InvalidConfigurationException
     * @throws ProviderException|ChargeException|Throwable
     */
    public function charge(): ChargeResponseDTO
    {
        $key = $this->getRateLimitKey();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ChargeException(
                "Too many payment attempts. Please try again in $seconds seconds."
            );
        }

        RateLimiter::hit($key);

        if (empty($this->data['callback_url'] ?? null)) {
            throw new InvalidConfigurationException(
                'Callback URL is required. Please use ->callback(url) in your payment chain.'
            );
        }

        $config = app('payments.config') ?? config('payments', []);
        $defaultCurrency = $config['currency']['default'] ?? 'NGN';

        $request = ChargeRequestDTO::fromArray(array_merge([
            'currency' => $defaultCurrency,
            'channels' => $this->data['channels'] ?? null,
        ], $this->data));

        return $this->manager->chargeWithFallback($request, $this->providers ?: null);
    }

    protected function getRateLimitKey(): string
    {
        // auth() returns the Auth Factory, which only exposes guard()/
        // shouldUse() - check()/id() live on the Guard the factory resolves.
        if (function_exists('auth') && auth()->guard()->check()) {
            return 'payment_charge:user_'.auth()->guard()->id();
        }

        if (! empty($this->data['email'])) {
            return 'payment_charge:email_'.hash('sha256', $this->data['email']);
        }

        if (app()->bound('request')) {
            return 'payment_charge:ip_'.app('request')->ip();
        }

        return 'payment_charge:global';
    }

    /**
     * @throws ProviderException
     * @throws InvalidConfigurationException|ChargeException|Throwable
     */
    public function redirect(): RedirectResponse
    {
        $response = $this->charge();

        return redirect()->away($response->authorizationUrl);
    }

    /**
     * @throws ProviderException|Exceptions\DriverNotFoundException
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponseDTO
    {
        return $this->manager->verify($reference, $provider);
    }

    public function subscription(?string $code = null): Subscription
    {
        $subscription = new Subscription($this->manager);

        if ($code) {
            $subscription->code($code);
        }

        return $subscription;
    }

    /**
     * Create a subscription query builder for advanced filtering and retrieval.
     */
    public function subscriptions(): SubscriptionQuery
    {
        return new SubscriptionQuery($this->manager);
    }

    public function refund(?string $transactionReference = null): Refund
    {
        $refund = new Refund($this->manager);

        if ($transactionReference) {
            $refund->transaction($transactionReference);
        }

        return $refund;
    }
}
