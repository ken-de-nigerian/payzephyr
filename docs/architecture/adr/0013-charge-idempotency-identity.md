# ADR-0013: Use the caller-supplied reference as the logical payment identity

- **Status**: Accepted
- **Date**: 2026-08-13

## Problem

A previous audit pass established that PayZephyr never re-charges after a *success* and
never falls back after an *ambiguous* outcome (ADR-0012). Neither protects against the
caller submitting the same payment twice:

```
Request A  ->  provider charges the customer  ->  response lost
Request B  ->  (a retry, or a double-clicked button)  ->  ???
```

Two concrete defects made this worse than "unprotected":

1. `ChargeRequestDTO::fromArray()` minted a **fresh random UUID** idempotency key on every
   call, even when the caller supplied the same `reference` both times. A retry therefore
   reached every provider under a *different* key, defeating provider-side idempotency in
   precisely the case it exists to protect.
2. Nothing serialised concurrent submissions. Two requests for the same payment could both
   pass every check and both call the provider - verified empirically: a test reproducing
   the interleaving recursed until memory exhaustion, i.e. an unbounded chain of external
   charges, because no layer stopped it.

## Options Considered

1. **Always auto-generate an idempotency key.** Rejected outright - this is what the code
   already did, and it is the bug. A key that differs per call provides no deduplication.
2. **Persist a `payment_transactions` row before the provider call, using its unique
   `reference` index as the arbiter** (the ADR-0005 pattern). Rejected: `payment_transactions`
   is a *record of payments*, and writing rows for charges that never happened changes the
   meaning of that table for every existing consumer and report. It is also unavailable when
   `payments.logging.enabled` is false, so it cannot be relied on universally.
3. **A new dedicated `charge_claims` table.** Rejected for this pass: it forces a migration
   on every installation for a guarantee that an atomic cache primitive already provides, and
   the durability advantage is smaller than it looks (the claim is short-lived by design;
   long-window protection comes from the idempotency key, not the claim).
4. **Derive the idempotency key from the caller's reference, and claim the reference
   atomically via `Cache::add()` before contacting any provider.** Chosen.

## Decision

When the caller supplies a `reference`, that reference is the logical payment identity: it is
used verbatim as the provider idempotency key (unless an explicit key is given, which always
wins), and it is atomically claimed before any provider is contacted; when the caller supplies
no reference, PayZephyr provides no cross-call deduplication and says so explicitly rather
than guessing.

## Why

**Reference and idempotency key are only equivalent here because the architecture makes them
equivalent, and that was checked rather than assumed.** `payment_transactions.reference`
carries a unique index (`2024_01_01_000000_create_payment_transactions_table.php:16`), so a
reference names at most one payment. There is therefore no valid scenario in which the same
reference should produce two different charges - which is exactly the condition required for
it to be a sound idempotency key. Where a caller genuinely needs them to differ, the explicit
`->idempotency()` key takes precedence, so the two concepts remain separable in the API even
though the default derivation collapses them.

The claim's release semantics follow directly from ADR-0012's failure classification:

| Outcome | Claim | Reasoning |
|---|---|---|
| Success | held | A repeat submission must not charge again |
| Ambiguous | held | The charge may have succeeded; a retry could double-charge |
| Definitive failure | released | No charge exists; a legitimate retry must be able to proceed |

Verified by `tests/Unit/ChargeIdempotencySafetyTest.php`, including a revert check: with the
claim removed, the concurrent-submission test recurses to memory exhaustion instead of
rejecting the second submission.

## Trade-offs

- **Callers who supply no reference get no protection.** This is a deliberate refusal to
  guess: inferring identity from (amount, currency, email) would wrongly merge two genuine
  purchases of the same item by the same customer, which is a worse failure than the one it
  prevents. Documented prominently in `docs/idempotency.md` instead.
- **The claim's cross-process guarantee depends on the configured cache store** being shared
  and atomic (database/redis/memcached). `array` and `file` are process-local. Documented.
- **The claim is short-lived (5 minutes).** It exists to absorb double-submits and concurrent
  retries, not to be a durable ledger. Longer-window protection is the idempotency key reaching
  the provider - which is why fixing defect (1) above mattered at least as much as adding the
  claim.
- **PayZephyr cannot verify that a given provider honours the key it sends.** That is the
  provider's documented behaviour, and is marked UNVERIFIED per-provider rather than claimed.

## Backward Compatibility

- **Behavioural, not source-breaking.** No signature changed. Two behaviours differ:
  - A charge with an explicit `reference` now sends that reference as the idempotency key
    rather than a random UUID. For a single charge this is indistinguishable; it only differs
    on a repeat submission, where the old behaviour was the defect.
  - A second concurrent/rapid submission of the same reference now throws `ProviderException`
    instead of silently charging again. Callers already had to handle `ProviderException` from
    this method, so no new exception type is introduced.
- Callers who never supplied a reference see no change whatsoever.
