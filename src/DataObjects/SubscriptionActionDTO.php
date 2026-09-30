<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Support\Payload;

/**
 * Carries a subscription code plus an open bag of provider-specific
 * parameters for cancel/enable-style actions.
 *
 * Different providers require different things to authorize these actions
 * (Paystack: an email confirmation token; PayPal: an optional cancellation
 * reason; Stripe/Mollie: nothing beyond the subscription id). Rather than
 * bake one provider's requirement into the method signature every driver
 * must implement, each driver reads what it actually needs via option().
 */
final readonly class SubscriptionActionDTO
{
    /**
     * @param  array<string, mixed>  $options  Provider-specific parameters,
     *                                         e.g. ['token' => '...'] for Paystack.
     */
    public function __construct(
        public string $subscriptionCode,
        public array $options = [],
    ) {
        if (empty($this->subscriptionCode)) {
            throw new InvalidArgumentException('Subscription code is required');
        }
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * An option read as text: a string or a number, else the default.
     */
    public function stringOption(string $key, string $default): string
    {
        return Payload::of($this->options)->string($key) ?? $default;
    }

    /**
     * An option read as a switch.
     *
     * A cast would read the string "false" as true, and these switches choose
     * between, say, pausing a subscription and cancelling it for good.
     * "false", "off", "no" and "0" are off; a value that is not a switch is
     * the default.
     */
    public function flagOption(string $key, bool $default): bool
    {
        return Payload::of($this->options)->flag($default, $key);
    }
}
