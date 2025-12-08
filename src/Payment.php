<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Http\RedirectResponse;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

/**
 * The Payment Builder - Your main interface for processing payments.
 *
 * This class lets you build payment requests step-by-step using a simple chainable syntax.
 * For example, Payment::amount(1000)->email('user@example.com')->redirect()
 *
 * Once you call redirect() or charge(), it sends everything to PaymentManager to handle.
 */
class Payment
{
    /**
     * The payment manager that handles all the actual payment processing.
     */
    protected PaymentManager $manager;

    /**
     * All the payment details you've set (amount, email, currency, etc.)
     */
    protected array $data = [];

    /**
     * Which payment provider(s) to use for this payment (e.g., 'paystack', 'stripe').
     * If empty, use the default from config.
     */
    protected array $providers = [];

    /**
     * Create a new payment builder.
     */
    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Set how much money to charge (in the main currency unit, e.g., 100.00 for $100).
     */
    public function amount(float $amount): static
    {
        $this->data['amount'] = $amount;

        return $this;
    }

    /**
     * Set the currency (e.g., 'NGN', 'USD', 'EUR').
     * Automatically converts to uppercase, so 'ngn' becomes 'NGN'.
     */
    public function currency(string $currency): static
    {
        $this->data['currency'] = strtoupper($currency);

        return $this;
    }

    /**
     * Set the customer's email address (required for most providers).
     */
    public function email(string $email): static
    {
        $this->data['email'] = $email;

        return $this;
    }

    /**
     * Set your own unique transaction reference (like 'ORDER_12345').
     * If you don't set this, the system will generate one automatically.
     */
    public function reference(string $reference): static
    {
        $this->data['reference'] = $reference;

        return $this;
    }

    /**
     * Set the URL where the customer should be sent after they finish paying.
     * This is where you'll verify the payment status.
     *
     * **Required: ** This method must be called when using the fluent API.
     * The payment will fail if the callback URL is not provided.
     */
    public function callback(string $url): static
    {
        $this->data['callback_url'] = $url;

        return $this;
    }

    /**
     * Add extra information to the payment (like order ID, user ID, etc.).
     * This data gets sent to the payment provider and comes back in webhooks.
     */
    public function metadata(array $metadata): static
    {
        $this->data['metadata'] = $metadata;

        return $this;
    }

    /**
     * Set an idempotency key to prevent charging the same payment twice.
     * Use a unique value (like a UUID) for each payment attempt.
     */
    public function idempotency(string $key): static
    {
        $this->data['idempotency_key'] = $key;

        return $this;
    }

    /**
     * Set a description for the payment (what the customer is paying for).
     */
    public function description(string $description): static
    {
        $this->data['description'] = $description;

        return $this;
    }

    /**
     * Set customer details like name, phone number, address, etc.
     * Pass an array: ['name' => 'John Doe', 'phone' => '+1234567890']
     */
    public function customer(array $customer): static
    {
        $this->data['customer'] = $customer;

        return $this;
    }

    /**
     * Limit which payment methods the customer can use.
     * For example, ['card', 'bank_transfer'] means only cards and bank transfers.
     * Useful for providers like Paystack.
     */
    public function channels(array $channels): static
    {
        $this->data['channels'] = $channels;

        return $this;
    }

    /**
     * Choose which payment provider(s) to use for this payment.
     *
     * Examples:
     * - with('paystack') - Use only Paystack
     * - with(['paystack', 'stripe']) - Try Paystack first, then Stripe if it fails
     *
     * If you don't call this, it uses the default provider from your config.
     *
     * @param  string|array  $providers  Provider name(s) like 'paystack', 'stripe', etc.
     */
    public function with(string|array $providers): static
    {
        $this->providers = is_array($providers) ? $providers : [$providers];

        return $this;
    }

    /**
     * Same as with() - just an alternative name for better readability.
     * You can use either: with('paystack') or using('paystack')
     */
    public function using(string|array $providers): static
    {
        return $this->with($providers);
    }

    /**
     * Process the payment and get the response (without redirecting the user).
     *
     * This creates a payment request and sends it to the payment provider.
     * Returns a ChargeResponseDTO object with details like the payment URL.
     *
     * Use this when you want to handle the redirect yourself (e.g., for API responses).
     * For automatic redirects, use redirect() instead.
     *
     * @throws InvalidConfigurationException If callback URL is not set.
     * @throws ProviderException If all payment providers fail.
     */
    public function charge(): ChargeResponseDTO
    {
        if (empty($this->data['callback_url'] ?? null)) {
            throw new InvalidConfigurationException(
                'Callback URL is required. Please use ->callback(url) in your payment chain.'
            );
        }

        $request = ChargeRequestDTO::fromArray(array_merge([
            'currency' => config('payments.currency.default', 'NGN'),
            'channels' => $this->data['channels'] ?? null,
        ], $this->data));

        return $this->manager->chargeWithFallback($request, $this->providers ?: null);
    }

    /**
     * Process the payment and automatically redirect the customer to the payment page.
     *
     * This is the easiest way to handle payments - it processes the payment
     * and sends the customer to the provider's checkout page.
     *
     * Use this in your controller: return Payment::amount(1000)->email('user@example.com')->redirect();
     *
     * @throws ProviderException|InvalidConfigurationException If all payment providers fail.
     */
    public function redirect(): RedirectResponse
    {
        $response = $this->charge();

        return redirect()->away($response->authorizationUrl);
    }

    /**
     * Check if a payment was successful by looking up the transaction reference.
     *
     * This searches for the payment across all enabled providers (or the one you specify).
     * Use this in your callback route after the customer returns from payment.
     *
     * @param  string  $reference  The transaction reference (from the payment response or callback).
     * @param  string|null  $provider  Optional: specify which provider to check (e.g., 'paystack').
     *                                 If null, searches all providers automatically.
     *
     * @throws ProviderException If the payment can't be found or verified.
     */
    public function verify(string $reference, ?string $provider = null): VerificationResponseDTO
    {
        return $this->manager->verify($reference, $provider);
    }
}
