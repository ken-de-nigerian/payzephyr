# ADR-0012: Isolate post-success bookkeeping from the operation it follows

- **Status**: Accepted
- **Date**: 2026-08-12

## Problem

A production-readiness audit found the same root-cause shape recurring in three places: code
that runs *after* a provider operation has already succeeded (or *after* a delivery has
already been recorded as seen) was sharing a single `try`/`catch` with the operation itself,
so a failure in that follow-up code was indistinguishable from the operation failing.

Three concrete instances:

1. **`PaymentManager::chargeWithFallback()`** ([PaymentManager.php](../../../src/PaymentManager.php)):
   `cacheSessionData()`/`logTransaction()`/`PaymentInitiated::dispatch()` ran inside the same
   try block as `$driver->charge($request)`. A cache backend outage or a listener throwing
   *after* a successful charge was caught by the fallback loop's own `catch (Throwable)` and
   retried against the next configured provider - charging the customer twice for a charge
   that had already succeeded.
2. **Ambiguous network outcomes**, same method: a charge request that times out or loses its
   response before confirming success is not distinguishable, from PayZephyr's side, from one
   the provider actually processed. The pre-existing fallback loop treated every exception
   from `$driver->charge()` identically, including this one - retrying an ambiguous outcome
   against a different provider is exactly the double-charge risk the fallback loop exists to
   avoid for confirmed failures.
3. **`ProcessWebhook::handle()`** ([ProcessWebhook.php](../../../src/Jobs/ProcessWebhook.php)):
   `WebhookEventRepositoryInterface::recordIfNew()` (ADR-0005) marks a delivery "seen" *before*
   processing it, so a genuinely concurrent duplicate delivery is rejected immediately. But a
   failure *after* that point (a `WebhookReceived` listener throwing, a transient DB error)
   left the marker in place forever, since nothing ever cleared it - so the job's own
   configured retry (`$tries`/`$backoff`) would see `recordIfNew()` return `false` on every
   subsequent attempt and silently skip reprocessing, permanently losing that delivery despite
   Laravel's queue retry mechanism appearing to be configured correctly.

A fourth, related but distinct problem in the same audit pass: **`Refund::refund()`**'s
duplicate guard (`RefundValidator::hasInFlightRefund()`) is a `SELECT` against
`refund_transactions`, and that row is only written *after* the provider call returns
(`LogsRefundTransactions::logRefund()` logs from the response) - so two refund requests for
the same transaction submitted close enough together could both pass the check before either
had written anything, and both proceed to call the provider.

## Options Considered

1. **Leave post-success bookkeeping and pre-call validation inside the operation's own
   try/catch, document the risk.** Rejected - this is the bug, not a mitigation; "document
   the risk" does not stop a double charge from actually happening.
2. **Make bookkeeping/event-dispatch failures fatal (let the whole request fail) so they
   can't be silently retried elsewhere.** Rejected - this converts a local, recoverable
   problem (a cache write failing) into a customer-facing failure for a charge that in fact
   succeeded, which is a worse outcome than logging and moving on.
3. **Isolate the follow-up code in its own try/catch that only logs, never re-throws into
   the retry/fallback path; and close the refund check-then-act race with an atomic lock
   acquired before the provider call.** Chosen for both problem shapes.

## Decision

Once a provider operation has succeeded (charge succeeded, delivery recorded as seen), no
code that runs afterward is allowed to influence whether PayZephyr retries that operation
against a different provider or treats an already-processed delivery as unprocessed;
`chargeWithFallback()`/`verify()` isolate all post-success bookkeeping in their own
try/catch, `ChargeException::isAmbiguousProviderOutcome()` stops the fallback loop outright
for network-ambiguous outcomes instead of retrying, `ProcessWebhook::handle()` clears its
own idempotency marker on failure via the new `WebhookEventRepositoryInterface::forget()`
method, and `Refund::refund()` claims an atomic `Cache::add()` lock before calling the
provider so a concurrent duplicate is rejected instead of also reaching the provider.

## Why

Verified test-first for each instance, with a git-stash-style revert confirming the test
fails without the fix and passes with it:

- `tests/Unit/PaymentManagerDuplicateChargeSafetyTest.php` - cache-write failure and
  listener-exception scenarios after a successful charge/verify.
- `tests/Unit/PaymentManagerAmbiguousChargeOutcomeTest.php` - `ConnectException`/`RequestException`
  with no response, and Stripe's `ApiConnectionException`, all confirmed to stop the fallback
  loop rather than retry.
- `tests/Unit/WebhookEventIdempotencyTest.php` ("a delivery whose processing fails after
  being recorded can still be retried") - a listener exception mid-processing, followed by a
  clean retry of the same delivery, confirms the marker is cleared and reprocessing happens.
- `tests/Unit/RefundConcurrencySafetyTest.php` - a fake driver's `refund()` call triggers a
  second, nested `Refund::refund()` for the same transaction *before* the first call returns,
  reproducing the exact race window a real two-process race would hit; the nested call is
  rejected with `RefundException`, and the outer call's driver is invoked exactly once.

## Trade-offs

- The refund lock's cross-process guarantee depends on the configured cache store being
  shared and atomic (database, redis, memcached). The `array`/`file` drivers don't provide
  real cross-process protection - single-process/local-only deployments get no additional
  benefit from this fix beyond what `RefundValidator`'s existing DB check already gave them.
- The refund lock has a fixed 60-second TTL as a safety net against a crashed process never
  reaching its `finally` release - chosen to comfortably exceed any realistic provider API
  call without blocking a legitimate retry indefinitely if the process holding it dies.

## Backward Compatibility

- **Breaking**: `WebhookEventRepositoryInterface` gained a new required method, `forget()`.
  Any application binding a custom implementation of this interface must add it before
  upgrading - see the changelog's `[3.0.0]` entry. `EloquentWebhookEventRepository` (the
  bundled default) already implements it.
- Everything else (fallback-loop ambiguous-outcome handling, refund in-flight lock) is
  additive: existing callers of `chargeWithFallback()`/`verify()`/`Refund::refund()` see no
  signature change, only a narrower set of circumstances under which each can succeed or the
  specific exception thrown.
