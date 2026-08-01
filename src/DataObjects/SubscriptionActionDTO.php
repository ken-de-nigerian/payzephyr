<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use InvalidArgumentException;

/**
 * Carries a subscription code plus an open bag of provider-specific
 * parameters for cancel/enable-style actions - see ADR-0006.
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
}
