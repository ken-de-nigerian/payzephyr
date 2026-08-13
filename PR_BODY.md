## Summary

Production-readiness audit across two passes. Every fix below has a regression test that was verified to fail with the fix reverted — not just added alongside it.

**Coverage: 96.6% → 99.1%** (measured with PCOV). **PHPStan: `ignoreErrors` block removed entirely.**

> [!WARNING]
> **Do not tag a release from this branch as-is.** It is behind `main` by 3 commits, one of which (`72ebde5`) removes `export-ignore` for `docs/`, `README.md`, and `CHANGELOG.md` from `.gitattributes`. Tagging before merging would ship a package with no docs, no README, and no changelog. Cut `v3.0.0` from `main` after this merges.

## Breaking change

`WebhookEventRepositoryInterface` gained a required method:

```php
public function forget(string $provider, string $eventKey): void;
```

Applications binding a custom implementation must add it. The bundled `EloquentWebhookEventRepository` already implements it, so the default binding needs no action.

This break was accepted deliberately: no correct fallback is derivable from the existing interface. Where a correct fallback *did* exist (the `DriverInterface` issue below), a `method_exists` guard was used instead and no break was introduced.

## Payment-safety fixes

- **Double-charge via fallback.** `chargeWithFallback()` ran post-charge bookkeeping inside the same `try` as the provider call, so a cache write or event listener failing *after* a successful charge was caught by the fallback loop and retried against the next provider. Post-success work is now isolated, and the invariant is enforced structurally rather than by convention.

- **Ambiguous network outcomes were failed over.** A request that was sent but whose response was lost is indistinguishable from one the provider processed. `ChargeException`/`RefundException` now classify via `DetectsAmbiguousProviderOutcome`; an ambiguous outcome surfaces a reconcile-first error instead of silently retrying elsewhere.

- **Failed webhooks could never be retried.** The "seen" marker was recorded before processing and never cleared, so the job's own `$tries`/`$backoff` saw every retry as a duplicate and skipped it — permanently losing that delivery. `ProcessWebhook` now clears the marker on failure via the new `forget()`.

- **Concurrent refunds could both reach the provider.** The existing guard only saw a `refund_transactions` row written *after* the provider call returned, so two near-simultaneous requests both passed it. `Refund::refund()` now claims an atomic cache lock first, and deliberately *holds* it on an ambiguous outcome rather than freeing a retry that might double-refund.

- **Custom drivers broke every charge, silently.** `chargeWithFallback()` called `isCurrencySupported()` and `getCachedHealthCheck()` — both on `AbstractDriver`, neither on `DriverInterface`, which `DriverFactory` and the docs treat as the contract. The resulting `Error: Call to undefined method` was swallowed by the fallback loop's `catch (Throwable)` into a generic "All payment providers failed", so a custom driver was silently skipped and payments quietly routed elsewhere. Both call sites now resolve through helpers that fall back to interface-guaranteed methods.

## Provider fixes

- `StripeDriver::charge()`/`verify()` caught only `ApiErrorException`, unlike every sibling driver; a non-SDK throwable escaped unwrapped.
- `FlutterwaveDriver` silently sent `redirect_url: null` with no callback URL configured, instead of failing fast like Stripe and PayPal.
- **PayPal and Square webhook channel extraction fabricated data.** PayPal returned a hardcoded `'paypal'` without reading the payload at all — card, Venmo and balance payments were recorded identically, and redundantly with `provider`. Square defaulted a missing `source_type` to `'card'`, recording card payments for instruments that were never cards. Both now report the real instrument, or `null` when genuinely unknown. **This changes `channel` values for these two providers.**
- `SquareDriver` and `MonnifyDriver` `healthCheck()` logged nothing on failure.

## Static analysis

`phpstan.neon`'s `ignoreErrors` block is **gone**, not updated. Two entries were already dead. The rest were hiding:

- the `DriverInterface` bug above — removing that entry immediately surfaced a **second occurrence** in `PaymentServiceProvider` the silencer had also been covering
- `auth()->check()`/`id()` called on the Auth *Factory* contract, which exposes neither
- an unsafe `new static()`, now enforced via `@phpstan-consistent-constructor`
- the fabricated PayPal/Square channel values

Notably, that block had a comment justifying every entry except the one hiding a live payment-path bug.

## Coverage

96.6% → 99.1%. The dominant gap was `refund()`/`fetchRefund()` error handling, unexercised across **every** refund-capable driver — precisely the code you depend on when reconciling after a timeout. Also covered: post-success fault injection, the lost-create-race recovery in `EloquentRefundRepository`, webhook failure handling, and the `Throwable` catches added during this audit.

CI's gate raised `--min=80` → `--min=99`, only after the number was measured.

One simplification fell out: `PaymentManager::logTransaction()` had a redundant inner `try/catch` that made the outer post-success guard — the one protecting the central invariant — permanently unreachable and untestable. Removed so the remaining guard is authoritative and exercised.

## Known-uncovered

`helpers.php:7` is autoloaded by Composer before PCOV starts recording, so it can't be attributed to a test. Unreachable by measurement, not untested. Called out rather than worked around.

## Test results

```
Pest:              1,950 passed, 4,262 assertions
Line coverage:     99.1%  (PCOV, passes --min=99)
PHPStan:           No errors, zero suppressions
Pint:              PASS (327 files)
composer validate: valid (--strict)
composer audit:    no advisories
```

## Reviewer notes

- CI runs Xdebug, not PCOV; the two can differ slightly on branch attribution. If coverage lands a hair under 99 on the first run, that's a driver difference rather than a regression.
- `v2.1.0` was never tagged (latest tag is `v2.0.2`), and its work is on this branch. Whether `v3.0.0` supersedes it or both get tagged is a call for the maintainer.
- Full findings, matrices, and remaining risks are in `payzephyr-audit-final-report.md`, with the design rationale in ADR-0012 and ADR-0013.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
