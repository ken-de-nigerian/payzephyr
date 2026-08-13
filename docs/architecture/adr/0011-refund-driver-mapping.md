# ADR-0011: v2.1 - Refund support across all 8 providers

- **Status**: Accepted
- **Date**: 2026-08-12

## Problem

`grep -rn "refund" src/` returned nothing prior to this release: no `RefundDTO`, no
`refund()` method, no refund tracking anywhere in the package. This was flagged as finding
H-2 in the pre-v2.0.0 release audit ([RELEASE_AUDIT_2026-07-31.md](../../RELEASE_AUDIT_2026-07-31.md))
and stated outright in the FAQ as a known, on-the-radar gap. Unlike subscriptions
(ADR-0009/ADR-0010), where two of eight providers genuinely have no provider-managed
subscription product to wrap, every one of the eight providers PayZephyr talks to has a
real refund endpoint - refunding a captured/settled charge is a close-to-universal
payment-gateway primitive. That made "ship to all 8 in one release" the reasonable default
rather than the incremental, ADR-per-batch rollout subscriptions used.

## Decision

- New opt-in `SupportsRefundsInterface` (`refund()`, `fetchRefund()`), following the exact
  shape `SupportsSubscriptionsInterface` established - not added to the base
  `DriverInterface`, checked via `instanceof` at the fluent-API call site.
- All 8 drivers (`PaystackDriver`, `StripeDriver`, `PayPalDriver`, `FlutterwaveDriver`,
  `SquareDriver`, `MollieDriver`, `MonnifyDriver`, `OPayDriver`) implement it via a
  per-driver `*RefundMethods` trait, mirroring the `*SubscriptionMethods` trait pattern.
- `RefundRequestDTO`/`RefundResponseDTO` support partial refunds via an optional
  `$amount` (`null` = full refund of the original charge). `$currency` is also optional
  and explicit rather than inferred, since Square, PayPal, Mollie, and OPay's refund
  endpoints require a currency in the request body and can't derive it from the original
  charge the way Paystack/Flutterwave/Monnify/Stripe do; when omitted, drivers fall back to
  the provider config's first configured currency, which is only correct for
  single-currency merchants.
- Refunds are persisted to a new `refund_transactions` table via `LogsRefundTransactions`
  (mirroring `LogsSubscriptionTransactions`), with metadata run through
  `MetadataSanitizer` **before** persisting from the first commit - the July 31 audit's
  finding C-3 was specifically that subscription metadata sanitization was wired into the
  payment-charge path but forgotten on the separately-evolved subscription-logging path;
  refunds don't get a chance to repeat that gap because there's no earlier
  refund-without-sanitization version to have drifted from.
- `RefundValidator` checks a refund's amount against the original transaction's remaining
  refundable balance (`original amount - sum of prior non-failed/non-cancelled refunds`),
  but only when the original `PaymentTransaction` row can be found locally by reference -
  best-effort, not a hard requirement, since providers enforce their own over-refund
  limits server-side regardless of whether this package logged the original charge.
- `RefundValidator` also rejects a new refund while an earlier refund on the same
  transaction is still `pending`/`processing` (`payments.refunds.prevent_duplicates`, on by
  default) - an in-flight duplicate guard aimed at accidental double-submission (a
  double-clicked "refund" button, a retried request before the first attempt's webhook
  confirms), independent of the amount check above since the first attempt's final amount
  isn't known yet. It does not block a genuinely new refund once an earlier one reaches a
  terminal state, so sequential partial refunds remain fully supported.
- `RefundCreated`/`RefundCompleted`/`RefundFailed` events dispatch from a new
  `ProcessWebhook::processRefundWebhook()`, for the providers that confirm refunds
  asynchronously rather than in the initial `refund()` response.

## Per-provider mapping notes and confidence level

Unlike ADR-0001 (webhook payload shapes) and ADR-0010 (subscription API existence), the
endpoint/payload shapes below were **not independently verified against each provider's
live API or sandbox during this pass** - they're built from each provider's publicly
documented refund API shape as of this writing, applying the same request/response
conventions already established and tested for that provider's `charge()`/`verify()`
implementation (same base URL, same auth headers, same JSON body/array response
structure). This is disclosed explicitly, the same way ADR-0010 flagged Flutterwave's
single-subscription-fetch endpoint as needing sandbox confirmation, rather than presented
with false confidence. **Before relying on any driver's refund support in production,
confirm the exact endpoint path, request fields, and response shape against that
provider's current API reference or a sandbox test call** - all test coverage in this
release mocks the HTTP layer, so it proves PayZephyr's own mapping/DTO/error-handling
logic is internally consistent, not that the mocked shape matches the real API byte for
byte.

| Provider | Refund reference used as `$transactionReference` | Confirmation |
|---|---|---|
| **Paystack** | Original transaction reference (`POST /refund`, `{transaction, amount?}`) | Async - `refund.processed`/`refund.failed` webhook |
| **Stripe** | PaymentIntent ID (`$stripe->refunds->create(['payment_intent' => ..., 'amount' => ...])`) | Often immediate for card refunds; async for some payment methods |
| **PayPal** | Capture ID, not the order ID (`POST /v2/payments/captures/{id}/refund`) | Usually immediate |
| **Flutterwave** | Flutterwave's numeric transaction id, not the merchant `tx_ref` (`POST /transactions/{id}/refund`) | Usually immediate |
| **Square** | Square's `payment_id` (`POST /v2/refunds`) | Async - starts `PENDING`, confirms via webhook |
| **Mollie** | Mollie's payment id (`POST /v2/payments/{paymentId}/refunds`); refund reference is composite `"{paymentId}:{refundId}"`, the same reasoning ADR-0010 used for Mollie's subscription codes, since fetching a refund needs both IDs | Async |
| **Monnify** | Monnify's `transactionReference` (`POST /api/v1/refunds/initiate-refund`); unlike other providers, Monnify requires the *caller* to generate the refund reference up front rather than returning a server-generated one, so it's derived from the request's idempotency key | Async |
| **OPay** | OPay's order reference (`POST /api/v1/international/refund/create`), authenticated the same HMAC-SHA512-over-body way as OPay's existing status endpoint | Async |

## Why

Given every provider has *some* refund endpoint, the risk here isn't "does this feature
exist" (ADR-0010's question for subscriptions) - it's "did we guess the exact shape
right." Disclosing that distinction honestly, rather than presenting unverified endpoint
guesses with the same confidence as ADR-0001's cross-checked webhook timestamps, is the
more important decision in this ADR than any individual mapping choice.

## Trade-offs

- Shipping to all 8 providers in one release, without per-provider sandbox verification,
  trades completeness-on-paper for a real chance that one or more driver's exact
  field names are wrong against the live API. Accepted or this release: the alternative
  (verifying all 8 against live sandboxes before merging) was judged to cost more than the
  value of catching it now versus via the first real integration test against each
  sandbox - but this is exactly the gap a follow-up pass should close, per-provider, before
  any driver's refund path is trusted with real money.
- Mollie's composite refund reference is a leaky abstraction, same trade-off ADR-0010
  accepted for Mollie's subscription codes, for the same reason (extending
  `RefundResponseDTO` with a second optional ID field for Mollie's benefit alone would leak
  further into the shared DTO shape).

## Backward Compatibility

Purely additive: no existing method signature changes. A third-party custom driver that
doesn't implement `SupportsRefundsInterface` continues to work exactly as before; calling
`Payment::refund()->with('that_driver')->refund()` against it throws `PaymentException`
("does not support refunds"), the same graceful-failure shape `SupportsSubscriptionsInterface`
already established.
