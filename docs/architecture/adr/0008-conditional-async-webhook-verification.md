# ADR-0008: Make async webhook verification conditional, not just per-driver

- **Status**: Accepted
- **Date**: 2026-07-31
- **Extends**: ADR-0007

## Problem

ADR-0007 introduced `Contracts\RequiresAsyncWebhookVerification` as a pure marker interface -
a driver either always defers verification, or never does. That's correct for PayPal
(`verifyWebhookSignatureViaAPI()` always makes an outbound call - there's no local-only
path). It's wrong for Mollie: `MollieDriver::validateWebhook()` already branches on
whether `webhook_secret` is configured
([MollieDriver.php:199-206](../../../src/Drivers/MollieDriver.php)) - when it is, verification
is a local HMAC computation (`validateWebhookSignature()`, no I/O); when it isn't, it falls
back to `validateWebhookViaAPI()`, which fetches the payment from Mollie's API - the same
synchronous-I/O-in-the-request-cycle exposure ADR-0007 fixed for PayPal, just reachable only
in one of Mollie's two configurations.

Implementing the marker interface unconditionally on `MollieDriver` would defer *every*
Mollie webhook to the queue, including the fast, local-HMAC-secured path - forfeiting
synchronous rejection feedback for the common, recommended Mollie setup for no benefit.

## Options Considered

1. **Leave Mollie as-is.** Rejected - it's the same bug class already fixed for PayPal,
   just gated behind a config condition instead of being universal.
2. **Unconditionally mark `MollieDriver` as async-deferred.** Rejected per the problem
   statement above - regresses the common case.
3. **Convert the marker interface to carry one method,
   `requiresAsyncVerification(): bool`, evaluated per-instance against that driver's
   actual config.** Chosen.

## Decision

`Contracts\RequiresAsyncWebhookVerification` gains one method:

```php
interface RequiresAsyncWebhookVerification
{
    public function requiresAsyncVerification(): bool;
}
```

- `PayPalDriver::requiresAsyncVerification()` returns `true` unconditionally - unchanged
  behavior from ADR-0007, now expressed through the method instead of bare `instanceof`.
- `MollieDriver::requiresAsyncVerification()` returns `empty($this->config['webhook_secret'])`
  - only the API-fallback configuration defers.
- `WebhookRequest::authorize()` and `ProcessWebhook::verifyDeferredSignature()` both change
  their check from `$driver instanceof RequiresAsyncWebhookVerification` to
  `$driver instanceof RequiresAsyncWebhookVerification && $driver->requiresAsyncVerification()`.

## Why

This was caught by re-examining the "fixed" list against the original audit rather than
assuming ADR-0007's shape was final - `MollieDriver::validateWebhookViaAPI()` at
[MollieDriver.php:277-334](../../../src/Drivers/MollieDriver.php) is the same
synchronous-outbound-call-in-the-request-cycle pattern PayPal had, and a pure marker
interface can't express "sometimes."

## Trade-offs

- Every driver implementing the interface now has one more method to write, even PayPal
  where it's a constant `true`. Accepted - trivial cost, and it makes the "always vs.
  conditionally" distinction explicit and reviewable at each call site rather than
  implicit in which drivers happen to implement a bare marker.

## Backward Compatibility

- Not released yet (this repo is still pre-v2.0.0 - see ADR-0006/0007), so this refines
  ADR-0007's shape before it ships rather than breaking a public contract a second time.
  If `RequiresAsyncWebhookVerification` had already shipped as a bare marker, this change
  would itself need to go through the same breaking-change process as ADR-0006.
