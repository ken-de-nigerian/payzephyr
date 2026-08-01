# ADR-0007: v2.0 - move PayPal's API-based webhook verification off the synchronous request path

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

`PayPalDriver::validateWebhook()` calls `verifyWebhookSignatureViaAPI()`
([PayPalDriver.php:365-410](../../../src/Drivers/PayPalDriver.php)), which makes **two**
real outbound HTTP calls to PayPal (an OAuth token fetch, then the
`/v1/notifications/verify-webhook-signature` call) - and this runs inside
`WebhookRequest::authorize()`, which Laravel invokes *before* the controller method runs,
i.e. synchronously in the request/response cycle, before `ProcessWebhook` is ever queued
(confirmed in the ADR-0001 audit pass; `throttle:120,1` bounds but does not eliminate the
exposure - an attacker can still force up to 120 outbound PayPal API calls/min per source
IP, holding a web worker on each one).

Every other driver's `validateWebhook()` is a local HMAC computation - no I/O, no
meaningful cost to running synchronously. PayPal is the one driver where "verify before
queuing" is actively the wrong trade-off.

## Options Considered

1. **Make every provider's webhook ingestion async-verified, unconditionally.** Rejected -
   punishes the 8 drivers where synchronous verification is free and simple, to fix a
   problem only one driver has.
2. **Hardcode a provider check** (`if ($provider === 'paypal')`) in `WebhookRequest`.
   Rejected outright - directly contradicts the package's own extension principle (adding
   or removing this behavior for a given provider must not require editing shared
   framework code).
3. **A marker interface, `Contracts\RequiresAsyncWebhookVerification`, that a driver
   implements to opt into deferred verification.** Chosen - `WebhookRequest::authorize()`
   and `ProcessWebhook` branch on `instanceof`, not on provider name; adding a tenth
   provider whose verification is also I/O-bound requires only implementing the marker on
   that driver, nothing else changes.

## Decision

- New empty marker interface `Contracts\RequiresAsyncWebhookVerification`.
  `PayPalDriver` implements it; no other driver does.
- `WebhookRequest::authorize()`: the payload-size cap still runs synchronously for every
  provider (cheap, in-memory, no reason to defer). For a driver implementing the marker,
  signature verification itself is skipped here and `authorize()` returns `true`
  unconditionally - so `WebhookController` always queues a `ProcessWebhook` job for such a
  driver, even for a forged request. That's intentional: the job discards it immediately
  (see below) with a single outbound call happening on a queue worker instead of a web
  worker, which is the entire point.
- `ProcessWebhook::handle()` gains a first step: if the resolved driver implements the
  marker, it calls `validateWebhook()` itself before doing anything else (event-key
  resolution, transaction update, event dispatch) and returns immediately, without
  throwing, if it fails - a discarded forged delivery is not a job failure to retry, it's
  correctly-functioning rejection.
- `ProcessWebhook` gains a `public readonly array $headers = []` property (default empty,
  so every existing direct `new ProcessWebhook($provider, $payload)` call - used
  throughout the test suite - keeps working unchanged). `WebhookController::handle()`
  passes `$request->headers->all()` through when dispatching.
- The re-verification call reconstructs the body as `json_encode($this->payload)` rather
  than storing a second copy of the raw request body on the job. This is safe
  specifically because PayPal's verification model sends the decoded JSON to PayPal's own
  cert-based verification endpoint (`'webhook_event' => json_decode($body, true)` -
  [PayPalDriver.php:384](../../../src/Drivers/PayPalDriver.php)) rather than computing a
  local HMAC over the exact original bytes. **This approach would not be safe** for a
  future async-deferred driver that verifies via local HMAC (byte-for-byte reserialization
  can differ from the original raw body) - such a driver would need the job to carry the
  true raw body, not a reconstruction. Noted here rather than built speculatively now,
  since no such driver exists yet.

## Why

The marker-interface approach was chosen specifically because it keeps `WebhookRequest`
and `ProcessWebhook` provider-agnostic, matching the same "no per-provider conditionals in
shared code" standard the rest of the package already holds itself to (see
`DriverFactory`'s OCP-compliance changelog entry) - this ADR extends that standard to the
webhook validation path rather than making an exception for it.

## Trade-offs

- A forged PayPal webhook now costs one queue job dispatch (cheap: a DB write to
  `webhook_events`... actually no - verification happens *before* the event-key/dedup
  step, so a rejected forgery costs no `webhook_events` write, just the job's own
  execution and the one outbound call to PayPal) instead of a `403` before any
  queuing happens at all. Accepted: queue capacity scales independently of web workers,
  which was the entire motivation.
- PayPal-forged-webhook rejection is no longer visible to whoever sent the request (they
  always get `202`, same as a legitimate one) - only observable via the `payments` log
  channel. Accepted: PayPal does not use our HTTP response code to decide whether to
  retry a webhook it considers already-delivered, so nothing about PayPal's own delivery
  guarantees changes.

## Backward Compatibility

- `ProcessWebhook`'s new `$headers` constructor parameter defaults to `[]` - not a
  breaking change for any code constructing the job directly (extensively used throughout
  the test suite).
- `DriverInterface`/`SupportsSubscriptionsInterface` are unaffected - this ADR touches
  webhook validation dispatch only, not the subscription contract (see ADR-0006, shipping
  in the same v2.0.0 release for the same reason - not because they're related in
  substance).
- Behavioral change only for PayPal: a request with an invalid signature now receives
  `202` instead of a rejection status, per the trade-off above. Documented in
  `docs/webhooks.md` and the v2.0.0 changelog entry as a PayPal-specific behavior change.
