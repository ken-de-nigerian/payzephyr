# ADR-0014: Razorpay driver

- **Status**: Accepted
- **Date**: 2026-09-15

## Problem

PayZephyr had no driver for Razorpay, the dominant gateway for Indian payments. Adding one
raised five questions the existing drivers did not answer:

1. Which Razorpay product maps onto `charge()`, which must return a URL to redirect to?
2. Whether to build on Razorpay's official PHP SDK, as `StripeDriver` does with Stripe's.
3. How to express amounts, since `ChargeRequestDTO::getAmountInMinorUnits()` always
   multiplies by 100 and Razorpay supports zero- and three-decimal currencies.
4. How Razorpay's refund webhooks reach `ProcessWebhook::processRefundWebhook()`, whose
   field resolution did not know Razorpay's `payload.refund.entity` nesting.
5. Whether to wrap Razorpay's subscriptions API in the same release.

## Options Considered

1. **Standard Checkout (Orders API + `checkout.js`) for charges.** Rejected: it needs a script
   on the merchant's page and returns no redirect URL, so it cannot fill
   `ChargeResponseDTO::$authorizationUrl`.
2. **Payment Links for charges.** Chosen: `POST /v1/payment_links` returns a hosted `short_url`,
   takes a `callback_url`, and accepts a merchant `reference_id`.
3. **`razorpay/razorpay` SDK for HTTP.** Rejected, see Why.
4. **Raw Guzzle through `AbstractDriver::makeRequest()`.** Chosen, as for seven of the eight
   existing drivers.

## Decision

- `RazorpayDriver` charges by creating a Payment Link. The package reference becomes the
  link's `reference_id` (Razorpay's limit is 40 characters; a longer reference throws a
  definitive `ChargeException` before any request). The link id (`plink_...`) is the stored
  provider id; `verify()` fetches by it, or by `reference_id` filter when only the reference
  is known. That filter trails link creation by a few seconds and lists links with an empty
  `payments` array, so a match is re-fetched by id.
- No idempotency header is sent on charges: the Payment Links API has none, and Razorpay
  requires `reference_id` to be unique per link. Refunds send `X-Refund-Idempotency`.
- Currency exponents are handled inside the driver (16 zero-decimal and 6 three-decimal
  currencies, per Razorpay's currency table; three-decimal amounts end in 0 as Razorpay
  requires). The shared `getAmountInMinorUnits()` is left unchanged.
- Refunds resolve the payment behind the reference, then read the payment entity for its
  status, currency and `amount_refunded`: after a full refund the link still lists the
  payment as `captured`, and only the payment itself says `refunded`. The amount is always
  sent explicitly (the unrefunded remainder for a full refund), and a payment with nothing
  left to refund is refused before any request. The package reference is written to the
  refund's notes so `fetchRefund()` and refund webhooks report it rather than the `pay_` id,
  keeping `refund_transactions.transaction_reference` aligned with the over-refund guard.
- `ProcessWebhook::processRefundWebhook()` additionally resolves the refund object from
  `payload.refund.entity`, and the transaction reference from `notes.payzephyr_reference`
  then `payment_id`. Additive: every earlier field is still checked first.
- Webhook reference and status extraction are scoped to `payment_link.*` events, so refund
  and payment events never write `payment_transactions.status`.
- Webhooks are verified by signature only, with no timestamp window, the approach ADR-0001
  took for Mollie's signature path. Razorpay's envelope `created_at` is the creation time of
  the link or refund, not of the event: a `payment_link.paid` arrived with a `created_at`
  335 seconds old. A replayed body is byte-identical, so the `webhook_events` dedupe skips
  it; the event key is the event name, the link or refund id, and the payment id.
- Exception messages append Razorpay's `error.description`, which `makeRequest()`'s generic
  "Invalid request to payment provider" otherwise hides.
- Subscriptions are not implemented in this release.

## Why

- The SDK sends requests through `rmccue/requests`, bypassing `makeRequest()`. That loses the
  configured `timeout`, the `setClient()` seam every driver test uses, and, most importantly,
  the Guzzle exception chain `DetectsAmbiguousProviderOutcome` inspects: a read timeout would
  look like a definitive failure and allow fallback to another provider after Razorpay may
  already have charged the customer. The SDK also keeps credentials and headers in static
  state (`Api::$key`, `Request::$headers`). Its `Utility` class was used to cross-check the
  webhook and Payment Link signature formats.
- Changing `getAmountInMinorUnits()` would alter amounts for every driver that relies on it;
  that gap is flagged here rather than fixed in a provider PR.
- Subscriptions follow the incremental pattern of ADR-0009 and ADR-0010: Razorpay does have a
  subscriptions API (plans, an authorization `short_url`, cancel/pause/resume), and wrapping it
  is a separate, reviewable change.

## Trade-offs

- No timestamp-based replay window. A replay is stopped by the `webhook_events` dedupe, which
  has no pruning yet (ADR-0005); if pruning is added, a replay older than the retention period
  would be processed again. Retried deliveries, which a window would drop, are accepted.
- Razorpay's own event id (`x-razorpay-event-id`) is a header, which `extractWebhookEventId()`
  cannot see; the key is built from payload ids instead.
- An instant refund can arrive on `refund.created` already `processed`, so subscribing to both
  `refund.created` and `refund.processed` dispatches `RefundCompleted` twice. The provider docs
  recommend `refund.processed` and `refund.failed` only.
- Only scalar metadata (up to 15 pairs, 256 characters each) reaches Razorpay notes. The full
  metadata is still logged locally.

## Verified against Razorpay test mode (2026-09-15)

The driver was exercised against a Razorpay test account, with webhooks delivered to a local
endpoint through a tunnel:

- A Payment Link was created with `reference_id`, customer, notes and a `callback_url`, stored
  with `?reference=` intact. `options.checkout.method` was honoured: the hosted page offered
  only the requested methods, although a fetched link reports `options` as null.
- A duplicate `reference_id` returns HTTP 400 `BAD_REQUEST_ERROR` ("payment link with given
  reference_id ... already exists"). A 40-character reference is accepted.
- The `reference_id` list filter found a new link after 0.8 to 2.6 seconds, and returned
  `payments: []` for a paid link.
- JPY amounts are sent without a minor unit. KWD was not enabled on the account, so
  three-decimal handling is covered by unit tests only.
- `verify()` of paid links (netbanking and UPI) returned success, the method and the paid time.
- The callback carried `reference` alongside the `razorpay_payment_link_*` parameters, and
  `razorpay_signature` matched HMAC-SHA256 of `link_id|reference_id|status|payment_id` with
  the key secret.
- On a UPI payment of 5.00, a 1.00 partial refund and the 4.00 remainder were both
  `processed` immediately, `fetchRefund()` returned the package reference from notes, and a
  further refund was refused locally. Reusing an `X-Refund-Idempotency` key with a different
  body is rejected ("Different request with the same idempotency key has already been
  processed"). Refunds under INR 1.00 are rejected.
- Signatures verified on real `payment_link.cancelled`, `payment_link.paid`, `refund.created`
  and `refund.processed` deliveries, and `ProcessWebhook` updated the transaction and
  dispatched the refund events with the package reference.
- Test accounts are limited to 30 Payment Links.

Not verified:

- Refunding a netbanking test payment failed with "invalid request sent" for every request
  shape tried, down to a bare `amount`, while the same refunds succeeded on a UPI payment.
  Treated as a test-mode or account-side limitation; not confirmed with Razorpay.
- Razorpay's webhook retry schedule, and refund settlement timing in live mode.

## Backward Compatibility

Additive. New driver, config block, status mapping and channel mappings. The
`processRefundWebhook()` change only adds fallbacks after the existing fields, so payloads
from other providers resolve exactly as before.
