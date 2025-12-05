<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

/**
 * The Fluent Builder for Payment Operations.
 *
 * This class provides a chainable interface to construct a payment request step-by-step.
 * Once the data is gathered, it delegates the actual processing logic to the
 * PaymentManager.
 */
class Payment
{
    /**
     * The core manager instance.
     */
    protected PaymentManager $manager;

    /**
     * Accumulated transaction data to be sent to the provider.
     */
    protected array $data = [];

    /**
     * Specific providers chosen for this transaction (overrides defaults).
     */
    protected array $providers = [];

    /**
     * Create a new fluent payment builder instance.
     */
    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Set the monetary value of the transaction.
     */
    public function amount(float $amount): static
    {
        $this->data['amount'] = $amount;

        return $this;
    }

    /**
     * Set the currency code (ISO 4217).
     *
     * Automatically converts the input to uppercase (e.g., 'ngn' -> 'NGN').
     */
    public function currency(string $currency): static
    {
        $this->data['currency'] = strtoupper($currency);

        return $this;
    }

    /**
     * Set the primary customer email address.
     */
    public function email(string $email): static
    {
        $this->data['email'] = $email;

        return $this;
    }

    /**
     * Set a custom unique transaction reference.
     *
     * If not set, the specific Driver will usually generate one automatically.
     */
    public function reference(string $reference): static
    {
        $this->data['reference'] = $reference;

        return $this;
    }

    /**
     * Set the URL where the user should be redirected after payment.
     */
    public function callback(string $url): static
    {
        $this->data['callback_url'] = $url;

        return $this;
    }

    /**
     * Attach arbitrary metadata to the transaction.
     *
     * This data is usually passed to the provider and returned in webhooks.
     */
    public function metadata(array $metadata): static
    {
        $this->data['metadata'] = $metadata;

        return $this;
    }

    /**
     * Set the idempotency key for the request
     */
    public function idempotency(string $key): static
    {
        $this->data['idempotency_key'] = $key;

        return $this;
    }

    /**
     * Set a human-readable description for the transaction.
     */
    public function description(string $description): static
    {
        $this->data['description'] = $description;

        return $this;
    }

    /**
     * Set detailed customer information (name, phone, etc.).
     */
    public function customer(array $customer): static
    {
        $this->data['customer'] = $customer;

        return $this;
    }

    /**
     * Restrict the allowed payment channels.
     *
     * Useful for providers like Paystack where you might want to limit
     * payment to specific channels (e.g., ['card', 'bank_transfer']).
     */
    public function channels(array $channels): static
    {
        $this->data['channels'] = $channels;

        return $this;
    }

    /**
     * Explicitly specify which provider(s) to attempt for this transaction.
     *
     * If an array is passed, the system will attempt them in order (Fallback logic).
     * If not set, the default provider from config is used.
     *
     * @param  string|array  $providers  Single provider name or array of names.
     */
    public function with(string|array $providers): static
    {
        $this->providers = is_array($providers) ? $providers : [$providers];

        return $this;
    }

    /**
     * Alias for with().
     *
     * Syntactic sugar for readability (e.g., Payment::using('stripe')...).
     */
    public function using(string|array $providers): static
    {
        return $this->with($providers);
    }

    /**
     * Execute the charge operation.
     *
     * Compiles the fluent data into a ChargeRequest object and delegates
     * to the PaymentManager to execute the transaction, handling any
     * fallback logic defined in `with()`.
     *
     * @throws ProviderException If all attempted providers fail.
     */
    public function charge(): ChargeResponse
    {
        $request = ChargeRequest::fromArray(array_merge([
            'currency' => config('payments.currency.default', 'NGN'),
            'channels' => $this->data['channels'] ?? null,
        ], $this->data));

        return $this->manager->chargeWithFallback($request, $this->providers ?: null);
    }

    /**
     * Execute the charge and immediately return a Laravel Redirect response.
     *
     * This is a convenience method for controllers that need to send the
     * user directly to the payment gateway.
     *
     * @throws ProviderException
     */
    public function redirect(): RedirectResponse
    {
        $response = $this->charge();

        return redirect()->away($response->authorizationUrl);
    }

    /**
     * Verify the status of an existing transaction.
     *
     * @param  string  $reference  The unique transaction reference.
     * @param  string|null  $provider  Optional provider name. If null, the manager attempts to resolve it.
     *
     * @throws ProviderException
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponse
    {
        return $this->manager->verify($reference, $provider);
    }
}
