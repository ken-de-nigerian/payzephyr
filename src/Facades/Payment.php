<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Facades;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Facade;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Refund;
use KenDeNigerian\PayZephyr\Subscription;
use KenDeNigerian\PayZephyr\SubscriptionQuery;

/**
 * Main Facade for the PayZephyr payment processing system.
 *
 * This class provides a static interface to the fluid Payment builder,
 * allowing for easy transaction initialization, charging, and verification
 * across multiple configured providers.
 *
 * @method static \KenDeNigerian\PayZephyr\Payment amount(float $amount)
 * @method static \KenDeNigerian\PayZephyr\Payment currency(string $currency)
 * @method static \KenDeNigerian\PayZephyr\Payment email(string $email)
 * @method static \KenDeNigerian\PayZephyr\Payment reference(string $reference)
 * @method static \KenDeNigerian\PayZephyr\Payment callback(string $url)
 * @method static \KenDeNigerian\PayZephyr\Payment metadata(array<string, mixed> $metadata)
 * @method static \KenDeNigerian\PayZephyr\Payment description(string $description)
 * @method static \KenDeNigerian\PayZephyr\Payment customer(array<string, mixed> $customer)
 * @method static \KenDeNigerian\PayZephyr\Payment with(string|array<int, string> $providers)
 * @method static \KenDeNigerian\PayZephyr\Payment using(string|array<int, string> $providers)
 * @method static ChargeResponseDTO charge()
 * @method static RedirectResponse redirect()
 * @method static VerificationResponseDTO verify(string $reference, ?string $provider = null)
 * @method static Subscription subscription(?string $code = null)
 * @method static SubscriptionQuery subscriptions()
 * @method static Refund refund(?string $transactionReference = null)
 *
 * @see \KenDeNigerian\PayZephyr\Payment
 */
final class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \KenDeNigerian\PayZephyr\Payment::class;
    }
}
