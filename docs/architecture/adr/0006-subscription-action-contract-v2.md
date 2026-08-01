# ADR-0006: v2.0 - generic SubscriptionActionDTO replaces the token-shaped interface

- **Status**: Accepted
- **Date**: 2026-07-31
- **Supersedes**: the `cancelSubscription`/`enableSubscription` signatures introduced pre-1.8.0

## Problem

`Contracts\SupportsSubscriptionsInterface`:

```php
public function cancelSubscription(string $subscriptionCode, string $token): SubscriptionResponseDTO;
public function enableSubscription(string $subscriptionCode, string $token): SubscriptionResponseDTO;
```

`$token` is Paystack's email-confirmation token - a real requirement of Paystack's API,
but meaningless for Stripe (`DELETE /subscriptions/{id}`, no token), PayPal (cancel by ID
plus an optional `reason` string, not a token), or Mollie. Any driver implementing this
interface for those providers is forced to accept a parameter it cannot use - a Liskov
violation baked into the contract itself.

The violation runs deeper than the interface. `Subscription::cancel(?string $token =
null)` ([Subscription.php:230-266](../../../src/Subscription.php), pre-ADR) **unconditionally
throws** `PaymentException('Email token is required...')` when no token is supplied,
regardless of which provider is active. That's the public fluent API every consumer
actually calls (`Payment::subscription()->code($code)->cancel()`) - so the Paystack
assumption was never just an interface-level leak, it was already blocking Stripe/PayPal
subscription cancellation entirely, independent of whether those drivers exist yet.
`SubscriptionValidator::validateCancellation(string $subscriptionCode, string $token, ...)`
([SubscriptionValidator.php:76-100](../../../src/Services/SubscriptionValidator.php))
has the identical problem: it unconditionally validates token *format* (`strlen($token) <
10`) for every provider.

## Options Considered

1. **Leave the interface as-is; make `$token` a nullable/optional param.** Rejected -
   doesn't fix the actual leak, just hides it. A Stripe driver would still receive a
   `$token` argument it has no use for, and callers would have no way to pass Stripe's
   equivalent (a cancellation `reason`) through the same call.
2. **Provider-specific interfaces** (`PaystackSubscriptionActions`, `StripeSubscriptionActions`,
   ...), with `Subscription.php` branching on driver type. Rejected - reintroduces the
   "if Stripe... if Paystack..." conditional structure the package's own extension
   principle explicitly forbids ("no if Stripe, no if Paystack" - adding a provider must
   require only a driver, not a `Subscription.php` edit).
3. **A generic `SubscriptionActionDTO` carrying `subscriptionCode` plus an open
   `array $options` bag, keyed by provider-specific parameter name.** Chosen - each driver
   reads what it actually needs (`$action->option('token')` for Paystack,
   `$action->option('reason')` for a future PayPal implementation) and is free to ignore
   the rest.

## Decision

- New `DataObjects\SubscriptionActionDTO { subscriptionCode: string, options: array }`
  with an `option(string $key, mixed $default = null)` accessor.
- `SupportsSubscriptionsInterface::cancelSubscription()` /
  `::enableSubscription()` now take a single `SubscriptionActionDTO $action` parameter.
- `PaystackSubscriptionMethods` reads `$action->option('token')` and throws a clear,
  Paystack-specific `SubscriptionException` if it's missing or too short - the validation
  that used to live generically in `SubscriptionValidator` moves to where it actually
  belongs (the driver that requires it), rather than being asserted for every provider.
- `SubscriptionValidator::validateCancellation()` drops the `string $token` parameter and
  the token-format check entirely; it keeps the genuinely provider-agnostic check (the
  terminal-state guard - you can't cancel an already-cancelled/expired/completed
  subscription, regardless of provider).
- **`Subscription::cancel(?string $token = null): SubscriptionResponseDTO` and
  `enable()` keep their exact existing public signatures.** Internally, they now build a
  `SubscriptionActionDTO` from an `$actionOptions` bag (populated by the existing
  `->token()` fluent setter, plus a new generic `->option(string $key, mixed $value):
  self` for forward compatibility) merged with the inline `$token` argument if given, and
  no longer hard-throw when no token is present - that decision now belongs to the driver,
  which is the only thing that actually knows whether it needs one.

## Why

Reading `Subscription.php` before designing the DTO changed the shape of this ADR: the
interface signature was not, in fact, the primary DX problem - the fluent API's
unconditional token requirement was. Fixing only the interface (as originally scoped in
the Tier 1 audit) would have shipped a v2.0 that still couldn't cancel a Stripe
subscription through the documented public API. Both layers had to move together.

## Trade-offs

- `$action->option('token')` trades compile-time parameter safety for runtime flexibility.
  Accepted - the alternative (a fixed parameter per possible provider need) doesn't scale
  past two providers without either bloating the interface or branching by type, both of
  which the extension principle rules out.
- Token/parameter validation is now the responsibility of each driver instead of one
  central validator. Accepted - it was never actually generic; centralizing it just hid
  that a Paystack-specific rule was masquerading as a universal one.

## Backward Compatibility - what breaks and what doesn't

**Does not break** (source-compatible, same public signatures):
- `Subscription::cancel(?string $token = null)`, `Subscription::enable(?string $token =
  null)`, `Subscription::token(string $token)` - the fluent API every documented usage
  example calls. Existing Paystack integrations calling `->cancel($token)` continue to
  compile and behave identically.

**Breaks** (requires a code change on upgrade):
- Any class implementing `Contracts\SupportsSubscriptionsInterface` directly (custom
  drivers) must update `cancelSubscription()`/`enableSubscription()` to the new
  `SubscriptionActionDTO $action` signature.
- Any code calling `$driver->cancelSubscription($code, $token)` or
  `$driver->enableSubscription($code, $token)` directly (bypassing the `Subscription`
  fluent API) must switch to
  `$driver->cancelSubscription(new SubscriptionActionDTO($code, ['token' => $token]))`.
- Any code calling `SubscriptionValidator::validateCancellation($code, $token, $driver)`
  directly must drop the `$token` argument.

**Migration for custom driver authors:**

```diff
- public function cancelSubscription(string $subscriptionCode, string $token): SubscriptionResponseDTO
+ public function cancelSubscription(SubscriptionActionDTO $action): SubscriptionResponseDTO
  {
-     // used $subscriptionCode, $token directly
+     $subscriptionCode = $action->subscriptionCode;
+     $token = $action->option('token'); // or whatever your provider's equivalent is
```

Ships as `v2.0.0` per the prior decision to break now rather than run a deprecation
window - adoption is still small enough that this is the lower total-cost path.
