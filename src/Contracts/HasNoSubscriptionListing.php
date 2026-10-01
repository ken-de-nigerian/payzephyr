<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * A subscription provider with no API to list a customer's subscriptions
 * (PayPal): listSubscriptions() throws.
 *
 * subscriptions.prevent_duplicates cannot ask such a provider whether the
 * customer is already subscribed. It reads the candidates from the
 * subscription log this package keeps instead, and fetches each one from the
 * provider for its current status - the log's status is the one at the last
 * call that wrote it, and a subscription approved since reads as pending.
 */
interface HasNoSubscriptionListing extends DriverInterface, SupportsSubscriptionsInterface {}
