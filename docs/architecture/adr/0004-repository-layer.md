# ADR-0004: Repository layer for transaction persistence, and the subscription race fix it enables

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

`PaymentManager` and `Jobs\ProcessWebhook` call `PaymentTransaction::where(...)`,
`::create(...)`, and `DB::transaction(fn () => ... ->lockForUpdate() ...)` directly as
static Eloquent calls
([PaymentManager.php:159](../../../src/PaymentManager.php),
[PaymentManager.php:308](../../../src/PaymentManager.php),
[PaymentManager.php:380-399](../../../src/PaymentManager.php),
[ProcessWebhook.php:96-132](../../../src/Jobs/ProcessWebhook.php)). Two consequences:

1. **DIP violation / untestable in isolation.** Neither class can be unit-tested against
   the persistence layer without a real (or in-memory sqlite) database — there is no seam
   to substitute a fake.
2. **The correct concurrency pattern exists in exactly one place and wasn't reused.**
   `PaymentTransaction` updates are correctly guarded (`DB::transaction` +
   `lockForUpdate()` + `isSuccessful()` terminal-state check before writing). The
   structurally identical `SubscriptionTransaction` write path
   (`PaystackSubscriptionMethods::logSubscriptionFromResponse()`,
   [PaystackSubscriptionMethods.php:516](../../../src/Traits/PaystackSubscriptionMethods.php))
   uses `SubscriptionTransaction::updateOrCreate()` directly with **no lock and no
   terminal-state guard**. Two concrete bugs result:
   - **Lost update on concurrent create**: two `ProcessWebhook` jobs racing to create the
     *same* new `subscription_code` can both pass `updateOrCreate`'s internal
     not-found check before either commits. The second `INSERT` hits the table's unique
     index on `subscription_code` and throws a `QueryException`, which the surrounding
     `catch (Throwable $e)` swallows as a logged error — that event's data is silently
     dropped, no retry.
   - **Unordered concurrent updates**: with no lock, two events for the same subscription
     (e.g. a `renewed` event that started earlier but finishes later, racing a `cancelled`
     event) can interleave with no ordering guarantee.

The lack of a repository abstraction is *why* the second bug exists: there was nowhere
the correct pattern lived to be reused from.

## Options Considered

1. **Copy the `PaymentTransaction` lock+guard pattern inline into
   `PaystackSubscriptionMethods`.** Rejected — duplicates the exact logic a repository
   should own, and does nothing for testability of `PaymentManager`/`ProcessWebhook`.
2. **Full persistence-ignorant DTO layer (repository returns value objects, not Eloquent
   models).** Rejected for this pass — bigger surface, and the calling code already treats
   the model fairly generically via `getAttribute()`. The DIP problem being fixed here is
   "business logic is hard-wired to static Eloquent calls," not "Eloquent must never be
   visible anywhere." Revisit only if a second persistence backend is ever actually needed
   (e.g. PayZephyr Cloud's hosted mode).
3. **Interface-bound repositories per model, injected via constructor
   (`PaymentManager`) or method injection (`ProcessWebhook::handle()`), bound to Eloquent
   implementations in the container.** Chosen.

## Decision

- `Contracts\TransactionRepositoryInterface`: `create()`, `findByReference()`,
  `updateIfNotSuccessful()` (encapsulates the existing lock+guard pattern verbatim - no
  behavior change for `PaymentTransaction`).
- `Contracts\SubscriptionRepositoryInterface`: `updateOrCreateAtomic()` — applies the same
  lock+guard shape to `SubscriptionTransaction`, plus a catch-and-retry around the unique
  constraint race on create (lock a nonexistent row locks nothing; the fix is to attempt
  the insert, and on a caught unique-violation, re-select-and-lock-and-update instead of
  losing the write).
- Both bound as singletons to Eloquent implementations in `PaymentServiceProvider`.
- `PaymentManager` takes `TransactionRepositoryInterface` via constructor (same optional
  + `app()`-fallback pattern already used for its other three collaborators).
  `ProcessWebhook` takes both repositories via `handle()` method injection — Laravel's
  documented pattern for queued jobs, since job instances are serialized and a
  constructor-injected service wouldn't survive that; method injection resolves fresh from
  the container when the job actually runs.
- Drivers (not container-resolved - constructed via `DriverFactory::create($name,
  $config)`) get `SubscriptionRepositoryInterface` through the same lazy
  `app()`-resolution + setter pattern `AbstractDriver` already uses for
  `StatusNormalizer`/`ChannelMapper` (`getSubscriptionRepository()` /
  `setSubscriptionRepository()`), rather than inventing a new DI mechanism.

## Why

Reusing the already-correct `PaymentTransaction` concurrency pattern was the direct
motivation - this is a "second instance of the same bug class" situation, and a repository
is the right place to make the pattern non-duplicable going forward. Method injection over
constructor injection for `ProcessWebhook` follows Laravel's own convention for queued
jobs rather than inventing a package-specific one (see the operating principles: "Laravel
Native... follow Laravel conventions over custom conventions").

## Trade-offs

- One more interface + implementation pair per model. Accepted: this is the textbook cost
  of DIP, and the alternative (inline duplication, as the subscription bug demonstrates)
  is worse.
- `updateOrCreateAtomic()`'s catch-and-retry adds real branching complexity beyond a plain
  `updateOrCreate()` call. Accepted - it's the standard, portable way to handle a
  create-race in any RDBMS-backed idempotent upsert, and the alternative is the exact data
  loss bug this ADR exists to fix.
- Does **not** solve true chronological event ordering (e.g. guaranteeing a `cancelled`
  event can never be overwritten by a slower-finishing, earlier-started `renewed` event).
  The lock+guard fixes the *race* (no more lost writes, no more corrupt concurrent state)
  but "last write wins" is still applied in delivery-completion order, not logical-event
  order, since neither table stores a per-event sequence number today. Flagged as a
  follow-up, not silently ignored: would need an `event_recorded_at` comparison, most
  naturally added alongside the `webhook_events` idempotency table from ADR-0005.

## Backward Compatibility

- No public API changes. `PaymentManager`'s new constructor parameter is optional with an
  `app()` fallback, matching its existing three collaborators - any code doing
  `new PaymentManager()` or `new PaymentManager($a, $b)` keeps working unchanged.
- `ProcessWebhook::handle()` gains two new method parameters, resolved automatically by
  Laravel's queue worker - not a breaking change for any code that dispatches the job
  (nothing calls `handle()` directly).
- No migration required for this ADR (schema unchanged - `SubscriptionTransaction`'s
  existing unique index on `subscription_code` is what the retry path relies on).
