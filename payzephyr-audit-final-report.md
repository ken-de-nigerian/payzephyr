# PayZephyr Final Production Audit

**Date**: 2026-08-13
**Scope**: `src/`, `tests/`, `docs/`, `config/`, `database/migrations/`, `.github/workflows/`
**Method**: independent re-read of current code (prior findings treated as unverified claims), design pass on idempotency identity, test-first fixes, revert-verification of each critical regression test, full quality suite after every change.

---

## 1. Executive Verdict

# PRODUCTION READY WITH DOCUMENTED LIMITATIONS

The verdict changed from the previous pass's "YES, WITH CONDITIONS" because the one issue that pass classified as an unresolved **critical** — caller double-submission with no idempotency protection — is now fixed, tested, and revert-verified. What remains are genuine limitations of talking to remote payment systems over an unreliable network, not defects: they are documented precisely in `docs/idempotency.md` rather than papered over.

The specific limitations a deployer must accept and configure for:

1. **Exactly-once payment processing is not guaranteed, and PayZephyr does not claim it.** No library that calls a remote provider can guarantee it. What *is* guaranteed is enumerated in §5.
2. **Cross-process duplicate protection requires a shared atomic cache store** (`database`, `redis`, or `memcached`). On `array`/`file` drivers the in-flight claim is process-local and only the idempotency-key layer applies.
3. **Callers must supply a stable `reference`** for duplicate protection to be possible at all. Without one, nothing identifies two submissions as the same payment and both reach the provider — by deliberate design, since guessing would wrongly merge genuinely distinct purchases.
4. **Whether a given provider honours the idempotency key PayZephyr sends is UNVERIFIED** — it is that provider's documented behaviour and cannot be confirmed without live sandbox credentials.

---

## 2. Critical Findings

### C-1 (FIXED) — A retry of the same payment reached providers under a different idempotency key

- **Vulnerability**: `ChargeRequestDTO::fromArray()` minted a fresh random UUID as the idempotency key on **every** call, even when the caller supplied the same `reference` both times.
- **Impact**: The single most important protection against the lost-response double-charge was inert. A retry after a timeout arrived at every provider looking like a brand new request, so provider-side idempotency could never engage — in exactly the scenario it exists for.
- **Reproduction**: Build two `ChargeRequestDTO`s from identical arrays containing the same `reference`; observe two different `idempotencyKey` values.
- **Root cause**: `$data['idempotency_key'] ?? self::generateIdempotencyKey()` — the reference was never consulted.
- **Fix**: Key resolution is now explicit and ordered: explicit key → derived from `reference` → random UUID (only when neither exists). `src/DataObjects/ChargeRequestDTO.php`.
- **Regression test**: `tests/Unit/ChargeIdempotencySafetyTest.php` — "a caller-supplied reference produces a stable idempotency key across submissions".
- **Verification**: Reverted the derivation; test fails. Restored; passes.

### C-2 (FIXED) — Concurrent submissions of the same payment both reached the provider

- **Vulnerability**: Nothing serialised two in-flight charges for the same reference. Both could pass every check and both call the provider.
- **Impact**: A double-clicked pay button, or a retried HTTP request picked up by a second worker, charged the customer twice.
- **Reproduction**: `tests/Unit/ChargeIdempotencySafetyTest.php` — "a second submission arriving while the first is still in flight never reaches a provider". The second submission is issued from *inside* the first's provider call, reproducing the exact interleaving a real two-process race produces.
- **Root cause**: No claim existed between request admission and the provider call; the only unique constraint (`payment_transactions.reference`) is only consulted *after* a successful charge, and only when logging is enabled.
- **Fix**: `PaymentManager::chargeWithFallback()` atomically claims the reference via `Cache::add()` before contacting any provider. Held on success and on ambiguous outcome; released only on definitive failure.
- **Verification (decisive)**: With the claim removed, the test does not merely fail — it **recurses until memory exhaustion**, demonstrating an unbounded chain of external charge calls with nothing stopping it. Restored; passes.

### C-3 (FIXED) — An unrecognised refund status released the entire refundable balance

- **Vulnerability**: `RefundResponseDTO::getStatus()` fell back to `FAILED` for any provider status string not in its mapping. `FAILED` is excluded from `RefundStatus::countsTowardRefundedAmount()`, which drives `sumRefundedAmount()` and therefore the over-refund guard.
- **Impact**: **Direct violation of the core refund invariant.** A provider returning a status PayZephyr does not recognise (a new status added after this release) made the payment look fully refundable again — a second refund could then over-spend the captured amount.
- **Root cause**: The original code conflated two different questions. "Did this succeed?" correctly answers *no* for an unknown status. "Did money leave the account?" must answer *assume yes* — but `FAILED` asserts *no*.
- **Fix**: Falls back to `PENDING` — still never treated as success, but counted toward the refunded total, and non-terminal so a later webhook can resolve it to the truth. `src/DataObjects/RefundResponseDTO.php`.
- **Regression test**: `tests/Unit/RefundAdversarialSafetyTest.php` — "a refund whose provider status is unrecognized still counts toward the refunded total".
- **Hardening**: `EloquentRefundRepository` now derives its counted-status list from the enum instead of hardcoding `['pending','processing','completed']`, so a future status cannot silently escape the guard. Test: "the refunded-total query is derived from the enum".

### C-4 (FIXED) — An ambiguous refund outcome released the in-flight lock

- **Vulnerability**: `Refund::refund()`'s `finally` released the lock unconditionally, including when the provider call timed out with no response.
- **Impact**: The provider may already have moved the money with no local row recording it; an immediate retry issued a second real refund.
- **Root cause**: The lock release did not distinguish definitive failure from ambiguous outcome — the same distinction ADR-0012 had already established for charges, not yet applied to refunds.
- **Fix**: The lock is now held on an ambiguous outcome and a reconcile-don't-retry `RefundException` is raised. Ambiguity detection was extracted into `DetectsAmbiguousProviderOutcome`, shared by `ChargeException` and `RefundException`.
- **Subtlety worth recording**: the shared trait **walks the full exception chain**. Refunds wrap the underlying network exception one level deeper than charges (`RefundException` → `ChargeException` → `RequestException`, because `AbstractDriver::makeRequest()` wraps every `GuzzleException` in a `ChargeException` before the refund trait re-wraps it). A single-level check — which is what the charge-only implementation used — would never have detected refund ambiguity.

### C-5 (FIXED) — Custom drivers broke every charge, and the failure was silently misreported

Found after the main audit pass, from a reviewer flagging a "potentially polymorphic call" warning on `$driver->isCurrencySupported(...)`. It was not an IDE artifact.

- **Vulnerability**: `chargeWithFallback()` called `isCurrencySupported()` and `getCachedHealthCheck()` on a `DriverInterface`-typed value. Neither method is declared on `DriverInterface` — both exist only on `AbstractDriver`.
- **Impact**: `DriverFactory::create()` and `PaymentManager::driver()` are both typed to the interface, and `docs/custom-drivers.md` documents the interface as the contract — so a pure-interface driver is legitimate. Such a driver raised `Error: Call to undefined method` mid-charge. **The fallback loop's `catch (Throwable)` then swallowed it into a generic "All payment providers failed"**, so in a multi-provider configuration the custom driver was silently skipped and payments quietly routed to a different provider with no indication why. The same call also made `/payments/health` report a healthy custom driver as unhealthy.
- **Reproduction**: `tests/Unit/CustomDriverCompatibilityTest.php` — a driver implementing `DriverInterface` and nothing more. All four tests failed before the fix.
- **Root cause**: interface/implementation drift, **actively concealed**. `phpstan.neon` carried an `ignoreErrors` entry silencing precisely this diagnostic, with no justifying comment — every other suppression in that file has one.
- **Fix**: both call sites resolve through `PaymentManager::driverIsHealthy()` / `driverSupportsCurrency()`, preferring the driver's own implementation and otherwise deriving the identical answer from `healthCheck()` / `getSupportedCurrencies()`, which *are* on the interface. No interface change required.
- **Verification**: the suppression was **removed, not updated** — which immediately surfaced a second occurrence in `PaymentServiceProvider` that the silencer had also been hiding. PHPStan is now clean with no suppression for this class of error.
- **Note on approach**: this deliberately did *not* become a second interface break. Unlike `forget()` (§8), fully correct fallbacks are derivable from existing interface methods, which is exactly the ADR-0005 condition under which `method_exists` is the right tool.

### C-6 (FIXED, structural) — The post-success invariant was enforced by convention, not structure

- **Vulnerability**: Each post-charge side effect was individually wrapped in its own try/catch. Correct as written, but any future edit adding an unwrapped line between the provider call and the `return` silently reintroduces the double-charge bug the previous audit fixed.
- **Fix**: All post-success work moved into `PaymentManager::completeSuccessfulCharge()`, which cannot throw, with the invariant stated at the call site. Adding new post-success work now lands inside the protected method by construction.
- **Regression test**: existing `tests/Unit/PaymentManagerDuplicateChargeSafetyTest.php` continues to pass unchanged.

---

## 3. Payment Safety Matrix

| Scenario | Result | Evidence |
|---|---|---|
| Successful charge | PASS | `PaymentManagerDuplicateChargeSafetyTest.php`; `completeSuccessfulCharge()` cannot throw |
| Definitive provider failure | PASS | `ChargeIdempotencySafetyTest.php` — "a definitive failure releases the claim so a legitimate retry can proceed" |
| Ambiguous timeout | PASS | `PaymentManagerAmbiguousChargeOutcomeTest.php`; `DetectsAmbiguousProviderOutcome` distinguishes never-connected from response-lost |
| Response lost | PASS | Same as above — treated as ambiguous, no fallback, claim retained |
| Post-success local failure | PASS | `PaymentManagerDuplicateChargeSafetyTest.php` (cache failure, listener exception) — fallback provider `chargeCalls === 0` |
| Duplicate charge (stable reference) | PASS | `ChargeIdempotencySafetyTest.php` — "a repeat submission after a successful charge does not charge again" |
| Duplicate charge (no reference supplied) | **UNPROTECTED — by design** | `ChargeIdempotencySafetyTest.php` — "with no reference and no explicit key, each submission gets a distinct key" asserts the limitation explicitly. Documented in `docs/idempotency.md` |
| Concurrent charge | PASS (single-process re-entrancy proof — see §7) | `ChargeIdempotencySafetyTest.php` — nested-call interleaving; revert causes unbounded recursion |
| Duplicate refund | PASS | `RefundConcurrencySafetyTest.php`; `RefundAdversarialSafetyTest.php` in-flight guard tests |
| Concurrent refund | PASS (single-process re-entrancy proof) | `RefundConcurrencySafetyTest.php` — revert causes unbounded recursion |
| Partial refund | PASS | `RefundAdversarialSafetyTest.php` — sequential partials allowed to exactly the captured amount |
| Refund over-spend | PASS | `RefundAdversarialSafetyTest.php` — over-captured, over-remaining, fully-refunded, and unknown-status cases all rejected |
| Duplicate webhook | PASS | `WebhookEventIdempotencyTest.php` — one row, one `WebhookReceived` |
| Failed webhook retry | PASS | `WebhookEventIdempotencyTest.php` — marker cleared on failure, delivery reprocesses on retry |
| Webhook replay | PASS | `WebhookSignatureTest.php` — timestamp tolerance per ADR-0001, all providers |
| Money precision | PASS | `MoneyPrecisionTest.php` — 29 tests incl. 0.01/0.10/1.99/19.99/999.99/12345.67, round-trips, partial-refund chains, 64-bit ceiling |

---

## 4. Provider Matrix

| Provider | Charge | Verify | Refund | Webhook | Idempotency | Timeout Safety | Exception Safety | Status |
|---|---|---|---|---|---|---|---|---|
| Stripe | PASS | PASS | PASS | PASS | UNVERIFIED | PASS | PASS | Ready |
| Paystack | PASS | PASS | PASS | PASS | PASS (header verified on the wire) | PASS | PASS | Ready |
| PayPal | PASS | PASS¹ | PASS | PASS | UNVERIFIED | PASS | PASS | Ready |
| Flutterwave | PASS | PASS | PASS | PASS | UNVERIFIED | PASS | PASS | Ready |
| Monnify | PASS | PASS² | PASS | PASS | UNVERIFIED | PASS | PASS | Ready |
| OPay | PASS | PASS | PASS | PASS | UNVERIFIED | PASS | PASS | Ready³ |
| Square | PASS⁴ | PASS | PASS | PASS | PASS (body field, Square-native) | PASS | PASS | Ready |
| Mollie | PASS⁵ | PASS | PASS | PASS | PASS (header, matches Mollie's docs) | PASS | PASS | Ready |

**Timeout Safety** = the code can distinguish "definitely failed, safe to fall back" from "ambiguous, may have succeeded". PASS for all providers because `DetectsAmbiguousProviderOutcome` operates on the shared Guzzle/Stripe-SDK exception hierarchy every driver funnels through (`AbstractDriver::makeRequest()`), not on per-driver logic.

**Idempotency UNVERIFIED** does *not* mean the key isn't sent — the code demonstrably sends it via each provider's mechanism. It means **PayZephyr cannot verify the provider honours it** without live sandbox credentials. Marked UNVERIFIED rather than PASS deliberately.

¹ PayPal's `verify()` has a fund-capture side effect (calls `captureOrder()` when an order is APPROVED but uncaptured) — correct for PayPal's flow, but it is not a pure read.
² Monnify's `verify()` reads `amountPaid` with a `(float)` cast that yields `0.0` if the field is absent from a malformed 200 response (see M-1).
³ OPay hardcodes `country: 'NG'` — a latent bug for non-Nigeria merchants (see M-3).
⁴ Square does not forward `$request->metadata` to its Payment Links API (see M-2).
⁵ Mollie never transmits the customer email despite the DTO requiring it (see H-1).

---

## 5. Idempotency Model

### What identifies what

- **Logical payment** → the caller-supplied `reference`. Sound as an identity because `payment_transactions.reference` carries a unique index, so a reference names at most one payment.
- **Provider request** → the idempotency key. Defaults to the reference; an explicit `->idempotency($key)` always overrides, so the two remain separable in the API even though the default derivation collapses them. This equivalence was checked against the schema, not assumed — see ADR-0013.
- **Internal transaction identity** → `payment_transactions.reference`, written only after a successful charge.

### How retries work

A retry carrying the same reference carries the same idempotency key, so providers honouring keys return the original charge. Within the claim TTL the retry is refused locally before any provider is contacted.

### How concurrency is handled

`Cache::add()` — atomic — is the arbiter. First caller wins; the loser gets `ProviderException` with `duplicate_submission: true` and never reaches a provider.

### Claim lifecycle

| Outcome | Claim | Why |
|---|---|---|
| Success | held | A repeat submission must not charge again |
| Ambiguous | held | May have succeeded; a retry could double-charge |
| Definitive failure | released | No charge exists; legitimate retry must proceed |

TTL is 5 minutes: long enough to absorb double-submits and concurrent retries, short enough that a crashed process cannot leave a payment permanently unchargeable.

### Without an explicit key or reference

**No protection.** Both submissions reach the provider. PayZephyr refuses to infer identity from `(amount, currency, email)` because that would wrongly merge two genuine purchases of the same item by the same customer — a worse failure than the one it would prevent.

### What PayZephyr guarantees

1. A charge that succeeded at one provider is **never** retried against another because of a local failure. Structurally enforced.
2. An ambiguous outcome **never** silently triggers another charge attempt.
3. With a stable reference and a shared atomic cache store, concurrent submissions produce **at most one** provider call.
4. With a stable reference, a retry carries the same idempotency key to the provider.
5. Total refunds counted against a payment **never** exceed the captured amount.

### What it cannot guarantee

1. Exactly-once external payment semantics. Not achievable; not claimed.
2. Deduplication when the caller supplies neither reference nor key.
3. Provider-side behaviour for providers that ignore idempotency keys.
4. Protection after a process dies between provider confirmation and local recording, once the claim TTL expires. Reconciliation is the only remedy.

### Refund identity

Cannot be derived automatically — sequential partial refunds against one transaction are legitimate, so the transaction reference does not identify a single refund. Callers needing retry-safe refunds must pass `->idempotency($key)` explicitly. Documented in `docs/idempotency.md`.

---

## 6. Failure Classification

| Class | Examples | Fallback permitted? |
|---|---|---|
| **Success** | Provider created/accepted the charge | **Never.** Structurally enforced by `completeSuccessfulCharge()` |
| **Definitive failure** | Explicit rejection, validation error, auth failure, definitive 4xx/5xx, `ConnectException` (never connected — nothing transmitted) | Yes, per existing fallback policy |
| **Ambiguous** | Read timeout after transmission, connection reset mid-flight, `RequestException` with `getResponse() === null`, Stripe `ApiConnectionException` | **Never.** Throws immediately with reconcile guidance |

`ConnectException` is classified **definitive**, not ambiguous — the connection was never established, so the provider could not have processed anything. It extends `RequestException`, so the trait checks it first; ordering matters and is commented as such.

---

## 7. Concurrency Model

**Protected:** concurrent charge submissions sharing a reference; concurrent refunds against one transaction; concurrent webhook deliveries of one event (DB unique constraint, ADR-0005); transaction status updates (`lockForUpdate()` + `isSuccessful()` guard); refund row create-or-update (`updateOrCreateAtomic` with lock + create-race retry).

**Primitives:** `Cache::add()` (atomic set-if-not-exists) for charge/refund claims; database unique constraints for webhook events and transaction references; `SELECT ... FOR UPDATE` for status transitions.

### Test honesty — this matters

The concurrency tests in this audit are **single-process re-entrancy tests, not real distributed concurrency tests.** They work by issuing the second operation from *inside* the first operation's provider call, which reproduces the precise interleaving — second request arrives after the first has passed its checks but before it has persisted anything — that a genuine two-process race produces.

What that does and does not establish:

- **Does establish**: the vulnerable window exists and is now closed at the application logic level. The revert-verification is unusually strong evidence here: without the fix, both the charge and refund tests recurse to memory exhaustion rather than merely returning a wrong value, demonstrating there was no bound of any kind on duplicate external calls.
- **Does not establish**: correct behaviour under true OS-level parallelism. That depends on the atomicity of the configured cache store, which is a property of Redis/the database, not of this code.

**UNVERIFIED**: real multi-process/multi-worker concurrency. Verifying it requires a load test against a real deployment with a real shared cache backend and cannot be done inside a single PHPUnit process.

---

## 8. Webhook Model

**Deduplication**: `(provider, event_key)` unique index; the DB constraint is the concurrency arbiter (ADR-0005). Event key = provider-native event ID where available, else `sha256(provider|payload)`.

**Processing**: `recordIfNew()` → process → dispatch `WebhookReceived`.

**Retry**: on failure the marker is cleared via `forget()` so the job's own `$tries`/`$backoff` can genuinely re-attempt. Without this the retry saw "already processed" and silently skipped forever.

**Replay protection**: timestamp tolerance per provider (ADR-0001), tested for all providers.

### State machine question — evaluated, deliberately not adopted

A `RECEIVED → PROCESSING → PROCESSED/FAILED → RETRY` state machine was assessed against the current `record / process / forget-on-failure` design. **Not adopted**: the current design already produces correct behaviour for every scenario tested (duplicate delivery, listener failure, DB failure, queue retry, concurrent delivery), and the user's own instruction was not to rewrite architecture for theoretical elegance. A state machine would add a schema migration and more failure modes without closing a demonstrated correctness gap.

### Should `forget()` remain on the public interface? — Yes, and the major bump is warranted

ADR-0005 set a precedent for avoiding interface breaks via `method_exists()` (used for `extractWebhookEventId()`). That precedent was considered and **rejected here** for a specific reason: ADR-0005's fallback (content-hash key) was *fully correct*, merely less optimal. There is no correct fallback for a missing `forget()` — the marker simply stays and retries break silently.

A custom implementation lacking `forget()` should fail loudly at deploy with a clear contract error, not silently reintroduce a payment-reliability bug its author would never observe. For a payment-safety contract, that trade favours the interface. **3.0.0 is genuinely required.**

---

## 9. Security Review

| Area | Status | Evidence |
|---|---|---|
| SSRF | PASS | All provider `base_url`s are developer-controlled config; no URL construction from request/webhook-controlled input |
| Mass assignment | PASS | `MassAssignmentSafetyTest.php`; `$fillable` allowlists on all models; call-site key allowlisting in `updateTransactionFromVerification()` |
| Webhook authentication | PASS | Every provider: HMAC-SHA512 (Paystack), HMAC-SHA256 (OPay/Square/Mollie), Stripe SDK, PayPal verification API, Flutterwave static secret-hash per its documented scheme. All use `hash_equals` |
| Replay protection | PASS | ADR-0001 timestamp tolerance, tested per provider |
| Secret handling | PASS | No secrets in source (scanned); metadata and refund reasons sanitized before persisting |
| HTTP security | PASS | Guzzle `verify: true` hardcoded, not overridable by config (ADR-0002, test-enforced) |
| Dependency security | PASS | `composer audit` — no advisories |
| CI security | PASS | All third-party actions SHA-pinned; `permissions: contents: read`; PHPStan + Pint + `composer audit` all run in CI |
| Debug code / secrets in diff | PASS | Scanned — no `dd`/`dump`/`var_dump`/`die`, no live keys, no generated artifacts |
| Static-analysis suppressions | PASS (one removed) | `phpstan.neon`'s `ignoreErrors` reviewed entry by entry. The `DriverInterface::(getCachedHealthCheck\|isCurrencySupported)` entry was hiding a real runtime bug (C-5) and was **removed**, not updated. The remaining entries each carry a justifying comment and were confirmed to describe genuine signature-consistency trade-offs, not concealed defects. |

---

## 10. Compatibility

**MAJOR (3.0.0) — required:**
- `WebhookEventRepositoryInterface::forget()` — new required interface method. Custom implementations must add it. Justified in §8.

**Behavioural changes (no signature change), shipped under the same major:**
- Idempotency key now derived from `reference` instead of random. Indistinguishable for a single charge; differs only on repeat submission, where the old behaviour was the defect.
- Concurrent/rapid duplicate submission now throws `ProviderException` instead of charging again. No new exception type — callers already handled `ProviderException` from this method.
- Unrecognised refund status now maps to `PENDING` instead of `FAILED`. `isFailed()` returns `false` where it previously returned `true` for unknown statuses. Two existing tests asserted the old behaviour and were updated with the reasoning recorded in-test.
- Ambiguous refund outcome now raises a reconcile error instead of releasing the lock.
- Flutterwave `charge()` throws `InvalidConfigurationException` when no callback URL is set (previously sent `redirect_url: null` silently).
- Stripe `charge()`/`verify()` now wrap non-SDK throwables as `ChargeException`/`VerificationException` rather than letting them propagate raw.

**MINOR/PATCH (additive, non-breaking):** `ChargeException::isAmbiguousProviderOutcome()` (now via shared trait), `RefundException::isAmbiguousProviderOutcome()`, `DetectsAmbiguousProviderOutcome` trait, `docs/idempotency.md`, ADR-0012, ADR-0013, healthCheck logging improvements, doc wording changes.

**Unchanged:** PHP `^8.2`, Laravel `^12.0|^13.0`, consistent with the CI matrix.

---

## 11. Test Results

```
Pest:              1,950 passed, 0 failed, 4,262 assertions
Line coverage:     99.1%  (measured with PCOV; passes --min=99)
PHPStan:           No errors (level 6) - phpstan.neon now has NO
                   ignoreErrors block at all
Pint:              PASS (327 files)
composer validate: valid (--strict)
composer audit:    no advisories
```

Baseline at the start of this pass was 1,832 tests / coverage unmeasured. Net +118 tests.

**Coverage was genuinely unmeasurable earlier in this audit** — no Xdebug/PCOV was installed, so the previous revision of this report deliberately declined to quote a figure. Once PCOV was installed the real baseline was **96.6%**, closed to **99.1%** by covering, in order of size: `fetchRefund()` and `refund()` error paths across every refund-capable driver (both were entirely unexercised), post-success fault-injection paths in `PaymentManager`, the lost-create-race recovery in `EloquentRefundRepository`, webhook failure-handling in `ProcessWebhook`, and the `Throwable` catches added to `StripeDriver` during this audit.

CI's coverage gate was raised `--min=80` → `--min=99` only *after* the number was measured.

One deliberate simplification came out of this: `PaymentManager::logTransaction()` had its own `try/catch` **and** was called inside the post-success guard. The inner catch made the outer guard — the one protecting the package's central invariant — permanently unreachable and therefore untestable. The redundant inner catch was removed so the single remaining guard is both authoritative and exercised.

### Known-uncovered lines (deliberate, not oversights)

`helpers.php:7` — the `function_exists('payment')` guard. The file is autoloaded by Composer before PCOV begins recording, so the guard can never be attributed to a test. Genuinely unreachable by measurement rather than untested.

The rest of the residual ~0.9% is defensive `catch` arms on console commands and per-driver health checks whose failure modes are logged-and-continue. They are listed per-file in the `pest --coverage` output rather than being individually enumerated here.

**Revert-verified** (fix removed → test fails → fix restored → test passes):

| Fix | Revert outcome |
|---|---|
| Charge in-flight claim | **Unbounded recursion → OOM** |
| Reference-derived idempotency key | Assertion failure (two different keys) |
| Refund in-flight lock (prior pass) | **Unbounded recursion → OOM** |
| Webhook `forget()` (prior pass) | Assertion failure (marker persists) |
| Post-success isolation (prior pass) | Fallback provider charged |

---

## 12. Remaining Risks

**Accepted limitations (documented, not defects):**

1. **Exactly-once is not guaranteed.** Inherent. Documented in `docs/idempotency.md`.
2. **No reference supplied → no duplicate protection.** Deliberate; guessing identity is more dangerous.
3. **Cache-store dependency** for cross-process protection. `array`/`file` give none.
4. **Crash between provider confirmation and local recording**, after the claim TTL expires, needs reconciliation.

**UNVERIFIED (cannot be established in this environment):**

5. **Provider-side idempotency-key honouring** — 6 of 8 providers. Requires live sandbox credentials.
6. **Real multi-process concurrency** — requires a load test against a real deployment with a shared cache backend. See §7 for exactly what the current tests do and do not prove.
7. **Mollie's async webhook-verification queue deferral** — the contract is asserted in a class docblock; the controller/queue wiring was not re-traced end-to-end this pass.

**Known open defects (identified, not fixed, low blast radius):**

8. **H-1 — Mollie never transmits the customer email** despite `ChargeRequestDTO` requiring it. Not fixed: correcting it means guessing Mollie's field name without API doc access, and guessing wrong on a live payment call is worse than the current gap.
9. **M-1 — fail-open amount parsing** in `verify()` for Monnify/OPay/Square/Mollie: a malformed 200 response missing the amount field yields `0.0` rather than throwing. Narrow (requires a malformed success response from the provider).
10. **M-2 — Square does not forward `metadata`** to its Payment Links API.
11. **M-3 — OPay hardcodes `country: 'NG'`** — latent bug for non-Nigeria merchants.
12. **M-4 — request timeout is not env-configurable**; all providers share the hardcoded 30s default and no test asserts it is applied.

None of items 8–12 can cause a duplicate charge, a duplicate refund, or an over-refund — the failure modes this audit was centred on.

---

## Final Recommendation

**Ship as 3.0.0.** The interface break is genuine and justified; the changelog, two ADRs, and `docs/idempotency.md` document it and the reasoning behind every behavioural change.

Deployers must (a) use a shared atomic cache store, and (b) supply stable references for charges. Both are stated in `docs/idempotency.md`, which is the one document to read before relying on any duplicate-protection claim.
