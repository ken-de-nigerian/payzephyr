# ADR-0005: Event-level webhook idempotency (`webhook_events` table)

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

`WebhookController::handle()` dispatches a `ProcessWebhook` job for every request that
passes signature verification, with no dedup at the delivery level
([WebhookController.php:24](../../../src/Http/Controllers/WebhookController.php)). Payment
gateways routinely redeliver the same logical event (retry on timeout, retry on non-2xx,
at-least-once delivery guarantees) - this is expected and documented behavior for Paystack,
Stripe, Flutterwave, and others, not an edge case.

Today, two *different* things happen for a duplicate delivery:

- **`PaymentTransaction` status updates are correctly idempotent** - `lockForUpdate()` +
  the `isSuccessful()` guard mean a duplicate delivery is a safe no-op (ADR-0004 extends
  the same correctness to `SubscriptionTransaction`).
- **Laravel events dispatched from `ProcessWebhook::processSubscriptionWebhook()` are
  not** ([ProcessWebhook.php:210-264](../../../src/Jobs/ProcessWebhook.php)).
  `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCancelled`, and
  `SubscriptionPaymentFailed` all get dispatched once per *delivery*, not once per
  *logical event*. If a host application's listener sends a welcome email, grants credit,
  or triggers a downstream side effect on `SubscriptionRenewed`, a duplicate delivery
  duplicates that side effect - the database-level idempotency doesn't protect anything
  downstream of the event dispatch.

This is the gap Stripe's own webhook documentation explicitly calls out: dedup on
`event.id` before processing, because delivery-level idempotency (2xx/retry semantics)
and event-level idempotency (business-logic side effects) are different problems.

## Options Considered

1. **Dedup only on the DB row state, as today.** Rejected - doesn't address the event
   dispatch duplication, which is the actual reported gap.
2. **In-memory / cache-based dedup (e.g. a Redis `SETNX` on the event key).** Rejected as
   the primary mechanism - a cache is not durable (eviction, restart, no persistent audit
   trail of what was processed), and this package has no hard Redis dependency to lean on
   (`Cache::add()` already used in ADR-0002's health-check warning is fine for a
   throwaway rate-limit flag; it is not fine as the system of record for "was this payment
   event already processed"). Could layer a cache fast-path *in front of* the DB check
   later purely as a read-load optimization - not needed at current scale, not added
   speculatively.
3. **A `webhook_events` table with a unique `(provider, event_key)` index, checked via an
   atomic insert-or-detect-duplicate at the top of `ProcessWebhook::handle()`.** Chosen.

## Decision

- New table `webhook_events`: `id`, `provider` (indexed), `event_key`, timestamps; unique
  composite index on `(provider, event_key)`.
- `Contracts\WebhookEventRepositoryInterface::recordIfNew(string $provider, string
  $eventKey): bool` - attempts an insert; returns `true` if this is the first time the key
  has been seen (caller should process), `false` if the insert hit the unique constraint
  (caller should skip). The **database's unique constraint is the concurrency arbiter**,
  not an application-level check-then-act - this is correct under concurrent duplicate
  delivery, which a `SELECT` then `INSERT` pair would not be.
- `ProcessWebhook::handle()` computes an event key and calls `recordIfNew()` before doing
  anything else; a duplicate short-circuits the whole job (no transaction update, no event
  dispatch, one log line).
- Event key resolution, in priority order:
  1. A provider-native event identifier, via a new `AbstractDriver::extractWebhookEventId()`
     method (default: checks top-level `id` / `event_id` / `payment_id`; overridden per
     driver where the real field is nested - Paystack/Flutterwave: `data.id`, Monnify:
     `eventData.transactionReference`, OPay: `payload.transactionId` - the same nested-field
     pattern established in ADR-0001, and against the same verified payload shapes).
  2. Fallback: `sha256(provider . '|' . raw_payload_json)` - used when no driver-specific ID
     is available, including for any third-party driver that only implements the bare
     `DriverInterface` (see Backward Compatibility below). A byte-identical redelivery
     (the normal retry case) hashes identically; a coincidentally-identical-but-genuinely-new
     event is an accepted, low-probability edge case rather than a reason to block on
     researching every provider's exact ID field before shipping this.
- `extractWebhookEventId()` is added as a concrete method on `AbstractDriver`, **not** to
  `DriverInterface` - see Backward Compatibility.

## Why

The hash fallback means this ships fully working for every driver today, including custom
ones, without gating correctness on researching a native ID field per provider. Where a
native ID *is* known (verified during ADR-0001's research pass, which already surfaced
`id`/`data.id`/`eventData.*` field presence for every provider while confirming timestamp
fields), using it is strictly better and cheap to add.

## Trade-offs

- The `webhook_events` table grows unboundedly with no pruning mechanism in this pass.
  Flagged, not solved: needs a scheduled cleanup (e.g. prune rows older than N days) before
  this matters at production webhook volume. Tracked as follow-up, not silently dropped -
  the `created_at` index this migration adds is specifically to make that pruning query
  cheap when it's implemented.
- Content-hash fallback means two *genuinely different* events that happen to serialize to
  byte-identical JSON (same provider, same reference, same status, no distinguishing
  timestamp field) would be wrongly deduped. Judged acceptable: this requires a provider
  that (a) has no usable native ID field *and* (b) sends identical bytes for two distinct
  real-world events, which is a materially narrower failure mode than the bug being fixed.

## Backward Compatibility

- `extractWebhookEventId()` is deliberately **not** added to `DriverInterface`. Any
  third-party driver implementing `DriverInterface` directly (rather than extending
  `AbstractDriver`) is not required to define it. `ProcessWebhook` checks
  `method_exists($driver, 'extractWebhookEventId')` before calling it and falls back to
  the content-hash key otherwise - a driver written against today's interface keeps
  working unchanged after this ships.
- New table via a new migration; existing tables and models untouched by this ADR.
- No public API changes.
