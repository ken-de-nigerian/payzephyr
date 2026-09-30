# ADR-0003: Adopt ADRs and phase in PR quality gates

- **Status**: Accepted; target end-state reached 2026-09-30 (see [Outcome](#outcome))
- **Date**: 2026-07-31

## Problem

Prior audit work on this codebase found real defects that shipped despite tests passing:
notably the webhook timestamp check (ADR-0001), which was "covered" by a test that
fabricated a payload shape no real provider sends. Code review and test coverage alone did
not catch this. We need (a) a durable record of *why* structural decisions were made, so
future contributors don't reflexively "clean up" something that was deliberate, and
(b) automated gates that catch what review missed.

## Options Considered

1. **Adopt the full target gate list immediately**: PHPStan level 10, Rector with zero
   suggestions, Pint, 100% mutation score (Infection) on payment/webhook logic, mandatory
   perf benchmark and security review per PR. Rejected as a same-PR requirement: the repo
   is currently at PHPStan **level 6** with no Rector or Infection installed
   (`composer.json`/`phpstan.neon` as of this ADR). Jumping straight to level 10 across the
   existing 9-driver, ~90-test-file codebase in one pass would surface a large, undifferentiated
   error list that buries the signal from *this* change's review and cannot be honestly
   claimed as passing without actually running it.
2. **Ignore the proposal, keep review-only quality control.** Rejected: this is the exact
   gap that let the timestamp bug ship.
3. **Adopt ADRs immediately (zero tooling cost); phase in gates against a tracked ratchet.**
   Chosen.

## Decision

- **ADRs**: required from this point forward for any change that alters a public contract,
  a security-relevant default, or a cross-driver architectural pattern. Template at
  `docs/architecture/adr/0000-template.md`. Not required for local, single-file bug fixes
  with no behavioral contract change.
- **Gates, phased**:
  - *Effective now, every PR*: Pint clean, PHPStan clean at the repo's *current* configured
    level (currently 6), full Pest suite green, new/changed logic covered by a new test.
  - *Phase 2 (tracked separately, not blocking today)*: raise `phpstan.neon` level in
    discrete steps (6 → 8 → 10), each step its own PR so failures are attributable to the
    level change, not mixed with feature work.
  - *Phase 3*: introduce Rector (dry-run/reporting mode first, enforced once the diff is
    reviewed and intentional) and Infection, scoped initially to `src/Traits/HasWebhookValidation.php`,
    `src/Jobs/ProcessWebhook.php`, and driver `validateWebhook()`/`verify()` methods
    (the highest-consequence surface) before any claim of "critical logic" mutation
    coverage is made codebase-wide.
  - BC-preservation and "no public API change without docs" are gates *effective now*,
    not phased; they cost nothing to enforce immediately and were already the operating
    norm (see the v2.0 decision for the subscription contract redesign).

## Why

A gate list that can't honestly be claimed as passing is worse than no gate list: it
trains reviewers to trust a green checkmark that isn't real. Phasing preserves the
credibility of "gates are green" as a signal.

## Trade-offs

- Full target state (level 10, 100% mutation on critical paths) is not reached in this PR.
  Tracked as follow-up work, not silently dropped.
- ADR overhead on every structural PR is real writing cost. Accepted: it's the same cost
  that would otherwise be paid later, repeatedly, by contributors re-deriving *why*.

## Backward Compatibility

N/A: process change, no code contract affected.

## Outcome

Every gate this ADR phased in is enforced in CI as of 2026-09-30:

- **PHPStan level 10**, reached through levels 7, 8 and 9 each in its own commit, with no
  baseline and no ignore comments. Reads of data PayZephyr did not write go through
  `Support\Payload`, which is what made the upper levels reachable without casts. The level 7
  and 8 commits were checked with a filter that missed errors reported inside traits; those
  were found and cleared before level 9, whose check reads PHPStan's full output.
- **Rector**, enforced as a dry run over `src`. `rector.php` lists the rules that are skipped
  and why: each changes behaviour rather than style.
- **Mutation testing at 100%** over the webhook trust boundary and the drivers:
  `HasWebhookValidation`, `ProcessWebhook`, `WebhookRequest`, `WebhookController`,
  `AbstractDriver` and all ten drivers - 2,754 mutations, every one caught. The scope is whole
  classes rather than the `validateWebhook()`/`verify()` methods named above, because the tool
  mutates classes; that is a superset.
- **100% line coverage**, already in place before this outcome.

One departure from the decision: mutation testing uses **Pest's `--mutate`**, not Infection.
Infection 0.35 has no Pest adapter and needs PHP 8.3, while the package supports 8.2. Pest's
plugin mutates by class, reads coverage from the same run, and gates with `--min`. Its one
limitation is Windows, where it builds paths and a test filter the platform rejects; CI runs
on Linux.

Reaching 100% surfaced one class of gap coverage cannot see: class constants. A removed
currency in a zero-decimal list, or a changed page size, is a mutation no coverage report maps
to a test, because a constant's declaration never executes. Those values now live in the code
that uses them, and tests pin every entry.
