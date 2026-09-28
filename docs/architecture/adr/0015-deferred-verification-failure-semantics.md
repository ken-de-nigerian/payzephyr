# ADR-0015: Deferred webhook verification retries what it could not verify

- **Status**: Accepted
- **Date**: 2026-09-28

## Problem

PayPal, and Mollie without a webhook secret, verify a delivery by calling the provider's API.
ADR-0007 and ADR-0008 moved that call into `ProcessWebhook`, after the HTTP endpoint has
already answered 202. From that moment the provider considers the delivery made and will not
send it again, so whatever the job decides is final. Three things made that decision wrong:

1. **An outage read as a forgery.** `PayPalDriver::validateWebhook()` and
   `MollieDriver::validateWebhookViaAPI()` each ended in a `catch (Throwable)` that returned
   `false`. A timeout or a 5xx from the verification endpoint therefore looked exactly like a
   bad signature, and `ProcessWebhook` discarded the delivery - no exception, so no retry. A
   genuine `PAYMENT.CAPTURE.COMPLETED` was lost to a network blip.
2. **The replay window measured from the wrong moment.** `validateWebhookTimestamp()`
   compared the payload's timestamp with `time()` when the *job* ran. A queue more than five
   minutes behind - a backlog, a deploy, a restarted worker - pushed every genuine PayPal
   delivery outside the window, and it was discarded the same way.
3. **Mollie's API path checked the wrong timestamp.** ADR-0001 kept a replay window on the
   fetched Payment's `createdAt`. That is when the payment was created, not when the event
   happened, so any payment paid, expired or refunded more than five minutes after creation
   was rejected - most iDEAL payments, and every refund. It also protected nothing: the body
   is only an id, and every fact acted on is fetched from Mollie's authenticated API, so a
   replayed ping can only re-read the payment's current state.

The tests did not catch (1): `paypal driver handles verification status failure` had no
assertion, and it and `handles empty verification status` answered the OAuth request with the
verification body, so token fetch failed and the verification call was never made. A third
test made a real HTTP call to PayPal's sandbox and asserted only that the result was a bool.

## Options Considered

1. **Keep returning `false`, add a retry inside the driver.** Rejected: it duplicates the
   queue's retry and backoff, and holds a worker while it waits.
2. **Throw when the provider could not be asked; return `false` only when it answered "no".**
   Chosen. The job already rethrows, releases its idempotency marker, and lets Laravel retry;
   verification runs before the marker is claimed, so a failed attempt leaves nothing behind.
3. **Throw on every failure, including a 4xx.** Rejected: a forged PayPal body with a
   malformed `cert_url` earns a 400 every time. Retrying it three times and parking it in
   `failed_jobs` hands an unauthenticated sender a way to fill that table.

## Decision

A verification call that gets no answer throws `WebhookException`; only an answer that the
delivery is not genuine returns `false`. The job records when the delivery was received and
the driver measures its replay window from then. Mollie's API path has no replay window.

## Why

- "No answer" is a timeout, a 5xx, a 408, a 429, or a 401/403 against our own credentials -
  none of them the sender's doing, and all of them worth a retry. Anything else in the 4xx
  range is PayPal or Mollie answering about the request we sent: for Mollie a 404 is exactly
  what a forged payment id produces. `HasWebhookValidation::isDefinitiveVerificationRejection()`
  holds that rule so both drivers apply it identically.
- `ProcessWebhook::$receivedAt` is set in the constructor, which runs inside the webhook
  request, and serialized with the job. `ProcessWebhook::verifyDeferredSignature()` passes it
  to the driver through `setWebhookReceivedAt()` and clears it in a `finally`, because drivers
  live for the whole worker process and the next delivery must not inherit it.
- Each behaviour is pinned by a test that fails against the previous code:
  `PayPalAsyncWebhookVerificationTest`, `PayPalDriverWebhookTest`, `MollieDriverEdgeCasesTest`.

## Trade-offs

- A persistent failure now ends in `failed_jobs` after `max_retries`, where it previously
  vanished. That is the point - it can be replayed with `queue:retry` once the cause is fixed -
  but it is a table operators should now expect to see used.
- A misconfigured PayPal client id or Mollie API key now fails every deferred delivery loudly
  instead of quietly discarding them all.
- Mollie's API path relies on deduplication, not a window, for replays - the same position
  ADR-0014 took for Razorpay, with the stronger footing that the payload carries no state.

## Backward Compatibility

- `PayPalDriver::validateWebhook()` and `MollieDriver::validateWebhook()` (API path) may now
  throw `WebhookException`. Both run only inside `ProcessWebhook` for PayZephyr's own route,
  which already handled a throw. Code calling them directly should expect it.
- `setWebhookReceivedAt()` on every driver extending `AbstractDriver`,
  `isDefinitiveVerificationRejection()`, `ProcessWebhook::$receivedAt` and
  `HttpStatusCodes::REQUEST_TIMEOUT` are additive. A job queued by an earlier version has no
  `$receivedAt` and is measured from now, exactly as before.
- Supersedes the part of ADR-0001 that kept a timestamp check on Mollie's API path.
