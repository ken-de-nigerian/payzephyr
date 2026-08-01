# PayZephyr Release Readiness Report

**Date:** 2026-07-31
**Scope:** Full-package release audit ahead of the v2.0.0 cut
**Auditor role:** Adversarial QA / release engineering pass: code review, static analysis, and behavioral testing against the actual API surface, not just "do the existing tests pass."

---

## 0. Environment disclosure: read this before the findings

The audit brief asked for a matrix that spans PHP/Laravel versions, MySQL, PostgreSQL,
Redis, Horizon, and live installs from Packagist. This sandbox has **none of that**:

| Requested | Available here | What was actually done |
|---|---|---|
| PHP 8.2 / 8.3 / 8.4 matrix | PHP 8.5.4 only | Reviewed CI's own matrix (`.github/workflows/tests.yml`: PHP 8.2–8.4 × Laravel 10–12, 9 combinations); config looks correct, not independently re-run here |
| MySQL / PostgreSQL | Neither installed, no daemon reachable | Reviewed all DB access for portability (no raw SQL, no driver-specific syntax); flagged one SQLite-specific locking caveat (§3, MEDIUM-3) |
| Redis / Horizon | Neither installed | Not testable. Queue code uses only the standard `ShouldQueue`/`Dispatchable` contracts; no Redis- or Horizon-specific code exists in the package to test |
| `composer require payzephyr/payzephyr` fresh install | No network install from Packagist attempted | Verified the install path via source: `InstallCommand`, `vendor:publish` tags, and migration files all exist and are wired correctly (§6) |
| Infection (mutation testing), Psalm, Deptrac, Rector | None installed | Not run: installing and running Infection over ~90 source files plus 1275 tests was judged too expensive for this pass. Flagged as a gap (LOW-3), not silently skipped |
| PHPStan, Pint, `composer audit` | All available | Run to completion (§1, §2) |

Everything below is either **(a) verified directly** (code read, test written and run,
static analysis executed) or **(b) explicitly marked as a disclosed gap**. Nothing here
is guessed.

---

## Summary

| Severity | Count | Fixed this pass |
|---|---|---|
| CRITICAL | 3 | 3 |
| HIGH | 3 | 2 (1 is a scope/product decision, not a bug) |
| MEDIUM | 4 | 0 (documented, none block release) |
| LOW | 3 | 0 (documented) |
| SUGGESTION | 3 | 0 |

**1275 tests pass, 0 risky, 0 failing.** PHPStan (level 6) clean. Pint clean on all 216
files. Full suite and static analysis re-run after every fix in this report.

The three CRITICAL findings were all **silent correctness gaps that the existing test
suite actively hid**: tests that looked like they verified the behavior in question but,
on inspection, asserted nothing about it. That pattern (a named, specific-sounding test
giving false confidence) is the single most important thing this pass found, and it's
called out explicitly per-finding below.

---

## CRITICAL

### C-1: The README's documented webhook-listening pattern does not work

**Description:** The README's "Webhooks → Setup" section instructs developers to
register an Event listener for a string-named event `'payments.webhook.paystack'` via
`EventServiceProvider::$listen`. PayZephyr never dispatches an event with that name, or
any `'payments.webhook.*'` name, anywhere in `src/`. The package only ever dispatches
`Events\WebhookReceived` (a real event class, with a different constructor signature:
`(string $provider, array $payload, ?string $reference)`).

**Root cause:** Documentation drift. At some point the package's actual webhook-event
design changed to a typed event class, but the README's example was never updated to
match.

**Reproduction steps:**
1. Follow the README exactly: register a listener under
   `'payments.webhook.paystack' => [HandlePaystackWebhook::class]`.
2. Trigger a real Paystack webhook delivery.
3. The listener never fires. `grep -rn "payments\.webhook\." src/` returns zero matches
   inside package source; the only place that string appears is inside test files that
   invented the event themselves (see below).

**Why the test suite didn't catch this:** `tests/Unit/WebhookTest.php` contained five
tests with names like `'webhook dispatches provider-specific event'`. Every one of them
called `event('payments.webhook.paystack', [$payload])` **inside the test itself**, then
asserted that event fired. This tests that Laravel's own `event()` helper works: it
never touches PayZephyr code at all, and would pass identically whether or not the
package dispatches anything.

**Proposed fix / what was done:**
- Rewrote the README's webhook setup example to use `WebhookReceived::class` correctly.
- Rewrote all five tautological tests in `WebhookTest.php` to actually drive
  `Jobs\ProcessWebhook::handle()` (the real code path) and assert `WebhookReceived` is
  dispatched with the correct `provider`/`payload`/`reference`, including one test that
  registers a real Laravel listener and confirms it's invoked.

**Files affected:** `README.md`, `tests/Unit/WebhookTest.php`

**Risk level:** CRITICAL: this is the primary, most prominently documented
integration point for reacting to payments. A first-time user following the README
verbatim gets a webhook integration that silently never fires.

---

### C-2: README's own subscription example throws a fatal error if run as written

**Description:** The README's subscription quick-start example does:
```php
$plan = Payment::subscription()->planData($planDTO)->with('paystack')->createPlan();
// ...
->plan($plan['plan_code'])
```
`createPlan()` returns a `PlanResponseDTO`, a `final readonly class` that does **not**
implement `ArrayAccess` and only exposes `->planCode` as a public property. Array-index
access (`$plan['plan_code']`) on that object throws `Error: Cannot use object of type
PlanResponseDTO as array`.

**Root cause:** The example predates a DTO refactor (this class used to be an array,
apparently) and was never updated.

**Reproduction steps:** Copy-paste the README's "Subscriptions → Quick Example" block
into a controller and run it. Fatal error on the `->plan($plan['plan_code'])` line,
before the subscription is even attempted.

**Proposed fix / what was done:** Changed the README example to `$plan->planCode`.

**Files affected:** `README.md`

**Risk level:** CRITICAL: this is "every documented example must execute
successfully," failing on the very first line that uses the return value of the
previous call.

---

### C-3: Subscription metadata bypasses XSS/stored-injection sanitization entirely

**Description:** `PaymentManager::charge()` runs all developer-supplied metadata
through `Services\MetadataSanitizer` (strips `<script>` tags, `on*=` handlers,
`javascript:`/`data:text/html`/`vbscript:` URIs, HTML-entity-encodes the rest, caps
key charset/length/depth/array-size) before persisting to
`payment_transactions.metadata`. The equivalent path for subscriptions,
`Traits\LogsSubscriptionTransactions::logSubscriptionFromResponse()`, which every
subscription-capable driver (Paystack, Stripe, PayPal, Flutterwave, Square, Mollie)
calls after every create/cancel/enable/fetch, wrote `$response->metadata` straight to
`subscription_transactions.metadata` with **no sanitization at all**.

Since `Subscription::metadata(array $metadata)` is a public, documented fluent method,
and every provider echoes back whatever metadata was sent when creating the
subscription/plan, this is a directly reachable stored-XSS vector: any consuming
application that renders `subscription_transactions.metadata` in an admin panel without
independently re-escaping it would render attacker- or customer-supplied `<script>`
tags verbatim.

**Root cause:** `MetadataSanitizer` was wired into the payment-charge path but never
into the separate subscription-logging path, which was added later and evolved
independently.

**Reproduction steps (pre-fix):**
```php
Subscription::customer('test@example.com')
    ->plan('PLN_123')
    ->metadata(['xss' => '<script>alert(1)</script>'])
    ->create(); // any provider whose mocked/real API echoes metadata back

SubscriptionTransaction::where('subscription_code', 'SUB_123')->first()->metadata;
// => ['xss' => '<script>alert(1)</script>']  (raw, unsanitized)
```

**Why the test suite didn't catch this:** `tests/Unit/SubscriptionSecurityTest.php`
contained a test literally named `'subscription prevents XSS in metadata'`, with a
code comment saying "Metadata should be sanitized by the driver." Its actual assertion
was `expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class)`: it checked the
return *type*, not the sanitized *content*, and the mocked API response didn't even
include a `metadata` key, so the test would pass identically with or without any
sanitization ever running.

**Proposed fix / what was done:**
- `LogsSubscriptionTransactions::logSubscriptionFromResponse()` now runs
  `$response->metadata` through `app(MetadataSanitizer::class)->sanitize()` before
  building the persisted attributes, the same treatment `PaymentTransaction` already gets.
- Rewrote the existing test to mock a response that actually echoes the malicious
  metadata back (matching real provider behavior), then assert the **persisted
  database row** doesn't contain `<script>`/`onerror=`, proving the sanitizer actually
  ran, not just that the call didn't throw.

**Files affected:** `src/Traits/LogsSubscriptionTransactions.php`,
`tests/Unit/SubscriptionSecurityTest.php`

**Risk level:** CRITICAL: stored XSS is exploitable by any party who can influence
subscription metadata (a merchant's own customer-facing form field feeding into
`->metadata()`, or a provider echoing back attacker-set values), and the one test meant
to guard against exactly this asserted nothing.

---

## HIGH

### H-1: README falsely claims only Paystack supports subscriptions

**Description:** "Currently, only PaystackDriver supports subscriptions. Support for
other providers will be added in future releases": false as of this release.
Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie all implement
`SupportsSubscriptionsInterface`. Only Monnify and OPay genuinely lack support (neither
provider has a subscription API to wrap; see ADR-0010).

**Root cause:** Documentation not updated as subscription support was added
incrementally across several drivers in this release cycle.

**Proposed fix / what was done:** Updated the README to list all six supporting
providers and link to `docs/providers.md` / ADR-0010 for the Monnify/OPay rationale.

**Files affected:** `README.md`

**Risk level:** HIGH: actively discourages use of a real, tested feature; a developer
reading this would likely not even attempt Stripe/PayPal/Flutterwave/Square/Mollie
subscriptions.

---

### H-2: No refund or partial-refund support exists anywhere in the codebase

**Description:** `grep -rn "refund" src/` returns zero matches. There is no
`RefundDTO`, no `refund()` method on `DriverInterface`, nothing. The audit brief's
mandated refund test matrix (creation, partial refunds, refund failure handling) could
not be executed for the simple reason that the feature doesn't exist to test.

**Root cause:** Not a bug: this is a genuine feature gap, not a defect in existing
code.

**Reproduction steps:** N/A: absence, not a failure.

**Proposed fix:** This is a product/roadmap decision, not something to silently
implement inside a QA pass. Flagging it here because "production ready... like Laravel
Cashier" (the brief's own bar) implies refund support, and every one of the 8 driver
APIs PayZephyr already talks to (Paystack, Stripe, PayPal, Flutterwave, Square, OPay,
Mollie, Monnify) has a real refund endpoint that could be wrapped following the exact
same driver-trait pattern already established for subscriptions in this release
(ADR-0009/ADR-0010).

**Files affected:** None yet: this is a gap, not a fix.

**Risk level:** HIGH for anyone who needs refunds (a near-universal payment-processing
requirement); does not block *this* release if refunds were never in scope, but should
be an explicit, disclosed decision rather than a silent absence discovered by a
frustrated integrator.

---

### H-3: README didn't disclose the accumulated v2.0.0 breaking changes

**Description:** The README's changelog-summary section said "**Latest: v1.8.0**" with
only v1.8.0 highlights, while `docs/CHANGELOG.md` has an entire `[2.0.0] - Unreleased`
section documenting multiple breaking changes accumulated this release cycle
(`SubscriptionActionDTO` contract change, PayPal webhook verification becoming
asynchronous, NOWPayments removed entirely). A developer running `composer require
kendenigerian/payzephyr` and skimming only the README would have no warning any of this
happened.

**Root cause:** README's changelog summary wasn't updated in lockstep with
`CHANGELOG.md` as breaking changes accumulated across the release cycle.

**Proposed fix / what was done:** Added an explicit "⚠️ v2.0.0 contains breaking
changes" callout pointing to the full CHANGELOG entry, plus a v2.0.0 highlights list
replacing the stale v1.8.0 one.

**Files affected:** `README.md`

**Risk level:** HIGH: breaking changes with zero README-level warning is exactly the
kind of thing that turns into a support fire the day this ships.

---

## MEDIUM

### M-1: guzzlehttp/guzzle and guzzlehttp/psr7 are locked to CVE-affected versions

**Description:** `composer audit` reports 30 advisories across 14 packages. Of those,
only `guzzlehttp/guzzle` (7 advisories) and `guzzlehttp/psr7` (4 advisories, guzzle's
own dependency) are inside the package's **production** dependency tree: every other
flagged package (`laravel/framework`, `symfony/*`, `league/commonmark`, `psy/psysh`,
`phpunit/phpunit`) is a dev-only transitive dependency of Testbench/Pest/Pint and never
ships to a consumer's production install.

The locked version is `guzzlehttp/guzzle` 7.10.0; the advisories (cookie-jar host
scoping, unbounded response cookies, IP-address cookie disclosure, proxy-authorization
leakage to origin servers, dot-only cookie domain matching, silent HTTPS-proxy
downgrade, URI-fragment-in-Referer disclosure) are fixed across 7.12.1–7.15.1, all
within the package's existing `^7.8` constraint.

**Why this is MEDIUM, not CRITICAL:** `AbstractDriver::initializeClient()` constructs
Guzzle with no `cookies` option (so no CookieJar is ever active) and no proxy
configuration: the vulnerable code paths (cookie jar handling, proxy header
forwarding) are never exercised by PayZephyr's own HTTP client usage. Separately,
**this is a library, not an application**: a library's own `composer.lock` is never
consulted by Composer when the library is installed as a dependency elsewhere, so this
specific stale lockfile does not propagate the vulnerable version to consumers; a fresh
`composer install` in a consuming app resolves `^7.8` to whatever's newest (7.15.1+ as
of this audit), sidestepping the issue naturally.

**Reproduction steps:** `composer audit --locked` in this repo.

**Proposed fix:** Refresh this repo's own dev lockfile (`composer update
guzzlehttp/guzzle guzzlehttp/psr7`) for hygiene, and add a `composer audit` step to CI
so this class of drift is caught automatically rather than found by an ad-hoc audit.
Not applied in this pass: a lockfile bump plus a CI workflow change felt like it
deserved an explicit maintainer decision rather than a silent side-effect fix.

**Files affected:** `composer.lock` (stale), `.github/workflows/tests.yml`
(recommended addition)

**Risk level:** MEDIUM: real CVEs, real fixed versions available, but not
exploitable through this package's own usage pattern and doesn't propagate to
consumers via the mechanism one would normally worry about.

---

### M-2: Inconsistent `catch (Exception)` vs `catch (Throwable)` in webhook-signature code

**Description:** Every driver's methods use `catch (Throwable $e)`, except
`StripeDriver::validateWebhook()`, `PayPalDriver::validateWebhook()`, and two more
sites inside `PayPalDriver`/`MonnifyDriver`, which narrow to `catch (Exception $e)`.
Webhook signature verification is arguably the single most security-sensitive code
path in the package (it processes untrusted external input), which makes it a strange
place for the one inconsistency in an otherwise uniform pattern.

**Why this is MEDIUM, not HIGH:** Traced both call sites that reach these methods:
`Http\Requests\WebhookRequest::authorize()` (sync path) and
`Jobs\ProcessWebhook::handle()` (async path, via `verifyDeferredSignature()`), and
both already wrap the driver call in an outer `catch (Throwable $e)`. A `TypeError` or
other `\Error` thrown inside Stripe's/PayPal's inner `catch (Exception)` gap still gets
caught one level up: the sync path fails closed (`authorize()` returns `false` → HTTP
403), and the async path logs, re-throws, and lets Laravel's queue retry/backoff/`failed()`
machinery handle it normally. So there's no current security hole, but it is a latent
trap: if either outer guard is ever refactored away, this becomes a real uncaught-Error
path with no test currently exercising that specific gap.

**Proposed fix:** Change the four `catch (Exception)` sites to `catch (Throwable)` for
consistency. Not applied in this pass: zero current exploitability made this lower
priority than the fixes that were actually live.

**Files affected:** `src/Drivers/StripeDriver.php:299`,
`src/Drivers/PayPalDriver.php:369,444`, `src/Drivers/MonnifyDriver.php:287`

**Risk level:** MEDIUM: not currently exploitable, but a real inconsistency in the
highest-stakes code path in the package.

---

### M-3: `lockForUpdate()` is a no-op on SQLite

**Description:** Both `EloquentTransactionRepository::updateIfNotSuccessful()` and
`EloquentSubscriptionRepository::updateOrCreateAtomic()` correctly wrap
`->lockForUpdate()` inside `DB::transaction()`, the right pattern for MySQL/PostgreSQL
row-level locking (verified correct in this audit; no lost-update path found). On
SQLite, Laravel's grammar compiles `lockForUpdate()` to an empty string, so this relies
entirely on SQLite's own single-writer-at-a-time serialization instead. That still
prevents silent data corruption (SQLite will not let two write transactions commit
concurrently), but without a configured `busy_timeout` pragma, a genuinely concurrent
SQLite writer gets a "database is locked" exception rather than blocking and retrying
gracefully, a real operational difference an integrator using SQLite in production
should know about.

**Proposed fix:** Document (in `docs/DOCUMENTATION.md` or a production checklist) that
SQLite is fine for development/testing but MySQL/PostgreSQL are the recommended
production choice for genuinely concurrent webhook delivery, and that `busy_timeout`
should be set if SQLite is used anyway. Not applied in this pass (documentation-only
recommendation, no code defect).

**Files affected:** `src/Repositories/EloquentTransactionRepository.php`,
`src/Repositories/EloquentSubscriptionRepository.php` (no change needed, informational)

**Risk level:** MEDIUM for teams running SQLite in production (unusual for a payments
system, but the package doesn't warn against it anywhere).

---

### M-4: `matchTimestampField()` matches on the bare word `"time"`

**Description:** `Traits\HasWebhookValidation::matchTimestampField()` checks an
attacker-unreachable but overly generic list of field names for a replay-protection
timestamp, including the single word `"time"`. Checked all 8 supported providers'
actual webhook payload shapes (via each driver's `extractWebhookTimestamp()` override
and the existing test fixtures): none currently has an unrelated field literally named
`time` ahead of a real timestamp field in the match order, so this is not a live bug
today. But the field name is broad enough that a future provider payload change adding
an unrelated numeric `time`-named field (a duration, a counter, anything not a Unix
timestamp) would be misinterpreted, likely producing a false-positive rejection of a
legitimate webhook (since a small non-timestamp number would appear "outside tolerance
window" by billions of seconds).

**Proposed fix:** Consider requiring a minimum plausible-Unix-timestamp magnitude check
(e.g. reject values that don't fall in a sane calendar-year range) before accepting a
matched field as a real timestamp, or narrow the generic field list. Not applied in
this pass: no current provider payload triggers it, so there's no concrete
regression test to write against real behavior, only a hypothetical one.

**Files affected:** `src/Traits/HasWebhookValidation.php`

**Risk level:** MEDIUM: design fragility, not a demonstrated live defect.

---

## LOW

### L-1: `PaymentConstants::MAX_STRING_LENGTH_FOR_TOKEN_CHECK` is now dead code

After fixing the log-sanitization length-gate bug (see fixes above; the gate used to
skip token-pattern matching for strings *shorter* than 20 characters, meaning short
real tokens like `"Bearer test123"` slipped through un-redacted; fixed by removing the
gate, since the regex is anchored and cheap regardless of string length), this public
constant is no longer referenced anywhere. Left in place rather than removed, since
it's a public constant on a class outside this audit's blast radius and removing public
API surface deserves its own deliberate decision. **Recommend removing in a follow-up
cleanup PR.**

**Files affected:** `src/Constants/PaymentConstants.php:35`

### L-2: No CI coverage across database engines or queue drivers

CI (`tests.yml`) exercises PHP 8.2–8.4 × Laravel 10–12 (9 combinations) but always
against Testbench's SQLite default with `QUEUE_CONNECTION=sync`. Nothing in the test
suite is currently run against MySQL, PostgreSQL, Redis queues, or database queues.
Given the package has no driver-specific SQL and no queue-driver-specific code, this is
lower risk than it sounds, but it's still an honest gap between what the audit brief
asked to verify and what CI actually verifies.

### L-3: No mutation testing, Psalm, Deptrac, or Rector in the quality gate

None of these are installed or configured. PHPStan (level 6) and Pint are the only
static-analysis gates currently enforced. Not run in this pass: installing and running
Infection specifically over ~90 source files and 1275 tests was judged too expensive
for this audit's time budget, and is flagged here rather than silently skipped.

---

## SUGGESTION

- **S-1:** `stripe/stripe-php` is constrained to `^13.0`; the current stable release is
  21.x, 8 majors behind. Not a security issue (zero CVEs found against it), but worth
  a deliberate decision about whether newer Stripe API features/webhook event types are
  wanted.
- **S-2:** Add `composer audit` as a CI step (see M-1) so dependency-CVE drift is caught
  automatically rather than by an ad-hoc pass like this one.
- **S-3:** Document the SQLite `busy_timeout` recommendation (see M-3) in
  `docs/DOCUMENTATION.md`'s production checklist, if one exists, or create one.

---

## What changed in this pass

| File | Change |
|---|---|
| `README.md` | Fixed webhook-listening example (real `WebhookReceived` event), fixed fatal-error subscription example (`$plan->planCode` not `$plan['plan_code']`), corrected subscription-provider-support claim, added v2.0.0 breaking-changes disclosure |
| `src/Traits/LogsSubscriptionTransactions.php` | Subscription metadata now sanitized via `MetadataSanitizer` before persisting (C-3) |
| `src/Traits/HasLogSanitization.php` | Added `authorization`/`signature` to redacted key list; removed the backwards length-gate that let short real tokens through un-redacted |
| `tests/Unit/WebhookTest.php` | Replaced 5 tautological tests (which fired their own invented event and asserted it fired) with tests that drive the real `ProcessWebhook` job and assert `WebhookReceived` dispatch |
| `tests/Unit/SubscriptionSecurityTest.php` | Rewrote the "prevents XSS in metadata" test to actually assert on the persisted database row, using a mock that echoes metadata back the way real providers do |
| `tests/Unit/HasLogSanitizationTest.php` | New file: 6 tests covering the log-sanitization fix |

**Verification after all fixes:** `vendor/bin/pest` → 1275 passed, 0 risky, 0 failed.
`vendor/bin/pint --test` → clean, 216 files. `vendor/bin/phpstan analyse src
--memory-limit=1G` → no errors.

---

## Release verdict

**Not blocked from release by anything CRITICAL**: all three CRITICAL findings are
fixed and regression-tested in this pass. The HIGH findings are either fixed (H-1, H-3)
or are an explicit scope disclosure rather than a defect (H-2, refunds). The MEDIUM/LOW
findings are real but none are exploitable in the package's current usage pattern, and
each has a clear, cheap follow-up.

The most important thing this pass surfaced isn't any single bug: it's that **three
separate tests, each with a name specifically describing the security property they
were supposed to guard, asserted nothing about that property.** That's a test-quality
pattern worth watching for in future PRs on this package: a passing test suite proved
nothing about the exact behaviors this audit was asked to verify existed for real.
