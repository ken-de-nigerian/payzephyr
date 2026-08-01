# ADR-0010: v2.0 - Flutterwave, Square, and Mollie subscriptions; NOWPayments removal; Monnify and OPay stay unsupported

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

After ADR-0009 (Stripe, PayPal), 6 of 8 remaining providers still didn't implement
`SupportsSubscriptionsInterface`: Flutterwave, Monnify, Square, OPay, Mollie, and
NOWPayments. Rather than assume which of these have a real provider-managed
subscription/recurring-billing primitive to wrap, each was researched against current
official documentation before writing any code - the same discipline ADR-0001 established
for webhook payload shapes, applied here to whether a subscription API exists at all.

Separately: NOWPayments is being removed from the package entirely per product decision -
crypto payment support is no longer in scope.

## Research findings, per provider

| Provider | Has a provider-managed subscription API? | Evidence |
|---|---|---|
| **Flutterwave** | Yes | `POST /v3/payment-plans` creates a plan; a customer is subscribed by including `payment_plan` in a charge; `PUT /v3/subscriptions/{id}/cancel` and `.../activate` confirmed via [Flutterwave's API reference](https://developer.flutterwave.com/v3.0/reference/create-payment-plan-1); `GET /v3/subscriptions` (list, with pagination) confirmed via [Flutterwave's subscriptions reference](https://developer.flutterwave.com/v3.0/reference/get-all-subscriptions). |
| **Square** | Yes | Square's [Subscriptions API](https://developer.squareup.com/docs/subscriptions-api/overview) - `SUBSCRIPTION_PLAN` + `SUBSCRIPTION_PLAN_VARIATION` Catalog objects, `POST /v2/subscriptions` confirmed with exact field names via [Square's create-subscription reference](https://developer.squareup.com/reference/square/subscriptions-api/create-subscription). |
| **Mollie** | Yes, with a real structural difference | Subscriptions are customer- and mandate-scoped: `POST /v2/customers/{customerId}/subscriptions`, confirmed both `customerId` and `subscriptionId` are required to fetch or cancel a subscription (not the subscription ID alone) via [Mollie's subscriptions reference](https://docs.mollie.com/reference/create-subscription). Status vocabulary (`pending`/`active`/`canceled`/`suspended`/`completed`) confirmed - `suspended` is system-driven (invalid mandate), not a merchant-triggered action, so there is no activate/resume endpoint. |
| **Monnify** | **No** | Monnify's "Recurring Payments" product ([developers.monnify.com/docs/collections/recurring-payments](https://developers.monnify.com/docs/collections/recurring-payments)) is three merchant-triggered repeat-charge primitives - reserved/static virtual accounts (push-based, customer pays in), direct debit mandates (merchant debits on its own schedule), and card tokenization (merchant charges the token whenever it chooses). None of these give Monnify a provider-side plan/subscription entity with a billing cycle Monnify itself tracks - there is no subscription ID to fetch, no plan Monnify manages. Implementing `SupportsSubscriptionsInterface` here would mean building a new cron-based billing engine inside this package to repeatedly charge a stored token on our own schedule - a materially different feature, not a driver wrapping an existing provider capability. |
| **OPay** | **No** | No subscription, recurring billing, or scheduled payment endpoint found anywhere in OPay's official API documentation ([documentation.opaycheckout.com/server-apis-overview](https://documentation.opaycheckout.com/server-apis-overview)) - the documented Server APIs are exclusively one-time payment methods (card, bank transfer, USSD, bank account, POS) plus status/refund/OTP operations. |

## Decision

- `FlutterwaveDriver`, `SquareDriver`, and `MollieDriver` now implement
  `SupportsSubscriptionsInterface`.
- `MonnifyDriver` and `OPayDriver` do **not**, and will not until either provider ships a
  real subscription product - implementing it against what exists today would mean this
  package silently building and owning a recurring-billing engine neither provider
  actually provides, which is a scope decision far bigger than "add a driver" and not
  one to make implicitly by force-fitting an interface.
- `NowPaymentsDriver` is deleted entirely (class, config block, provider-mapping
  registration, all tests, all docs references) - not deprecated, not soft-disabled.
  This package no longer accepts NOWPayments as a provider name at all.

### Provider-specific mapping notes

- **Flutterwave**: `createSubscription()` requires `$request->authorization` (a saved
  card token) - Flutterwave subscribes a customer via a tokenized charge carrying
  `payment_plan`, not a standalone "create subscription" call, mirroring the
  `authorization`-required pattern already used for Paystack and Stripe.
  `cancelSubscription()`/`enableSubscription()` map directly to Flutterwave's own
  `/cancel` and `/activate` endpoints - no reinterpretation needed, unlike PayPal.
  `updatePlan()` and single-subscription `fetchSubscription()` follow Flutterwave's
  confirmed REST conventions (`PUT`/`GET` on the resource path) by pattern-matching
  against the independently-confirmed `/cancel` and `/activate` siblings, since a
  dedicated single-fetch endpoint page wasn't independently reachable during research -
  **flagged for sandbox verification before production use**, same treatment as
  ADR-0009's PayPal `listPlans()` uncertainty.
- **Square**: Plans map to a `SUBSCRIPTION_PLAN` + `SUBSCRIPTION_PLAN_VARIATION` Catalog
  object pair, created together via `POST /v2/catalog/batch-upsert` (Square's own
  recommended pattern for atomically creating related catalog objects). Mirrors Stripe's
  Product+Price split conceptually. `createSubscription()` requires
  `$request->authorization` as a Square Card-on-file ID, and finds-or-creates a Square
  Customer by email (same pattern as Stripe). `cancelSubscription()`/`enableSubscription()`
  map to Square's `/pause` and `/resume` endpoints (reversible, matching Paystack's
  disable/enable semantics) rather than `/cancel` (permanent) - same reasoning as
  PayPal's suspend/activate mapping in ADR-0009.
- **Mollie**: Because both `customerId` and `subscriptionId` are required for every
  operation but the interface only carries one `$subscriptionCode` string,
  `subscriptionCode` is encoded as `"{customerId}:{subscriptionId}"` and split apart
  wherever needed. `createSubscription()` requires `$request->authorization` as an
  existing Mollie mandate ID. `enableSubscription()` throws unconditionally - Mollie has
  no merchant-triggered resume; a `canceled` subscription is terminal, matching the same
  honest-failure pattern already used for Stripe's terminal-cancel case in ADR-0009.
  `listSubscriptions()` requires `$customer` (Mollie's list endpoint is
  customer-scoped, not global) - throws a clear exception if omitted, a narrower version
  of PayPal's "no list at all" gap from ADR-0009.
  A further structural gap surfaced during implementation: Mollie has **no plan
  resource at all** - a subscription carries its own amount/interval/description
  directly, with nothing analogous to Stripe's Price or Square's
  SUBSCRIPTION_PLAN_VARIATION to create or fetch server-side. `createPlan()` therefore
  encodes the plan's fields into an opaque, self-describing `planCode` string
  client-side (base64 JSON) rather than calling Mollie at all; `fetchPlan()` decodes it
  back; `updatePlan()` decodes, merges, and re-encodes into a *new* planCode (same
  "immutable, update produces a new identifier" shape as Stripe's Price update);
  `listPlans()` throws - there is nothing server-side to enumerate, and approximating a
  list from previously-seen plan codes would require state this package doesn't keep.
  `createSubscription()` decodes `$request->plan` to build the API payload, and
  `fetchSubscription()`/`listSubscriptions()` re-derive an equivalent plan code from the
  subscription's own returned amount/interval/description so callers reading
  `SubscriptionResponseDTO::$plan` get a consistently-shaped value either way.

## Why

Researching before implementing caught the same category of risk ADR-0001's webhook
research caught: an unverified assumption here would have meant either building a
fake "subscription" wrapper around Monnify/OPay that silently does the wrong thing (fires
no renewal events, can't report real status, because there's nothing on the provider side
to query), or shipping endpoint guesses for Flutterwave/Square/Mollie that would fail
silently in production. Where independent confirmation wasn't reachable (Flutterwave's
single-subscription fetch/update endpoints), that's disclosed explicitly rather than
presented with the same confidence as the verified endpoints.

## Trade-offs

- Two providers remain permanently without subscription support unless their own APIs
  change - a real capability gap for anyone specifically wanting Monnify or OPay
  subscriptions, not a "later" item on a roadmap. Accepted: shipping a fake wrapper would
  be worse than an honest gap.
- Mollie's composite `subscriptionCode` encoding is a leaky abstraction relative to the
  other providers' plain ID strings - a caller inspecting the string directly sees
  provider-specific structure. Accepted: the alternative (extending
  `SubscriptionActionDTO`/`SubscriptionResponseDTO` with a second optional ID field for
  Mollie's benefit alone) would leak Mollie's model into the shared DTO shape instead,
  which is the worse trade.

## Backward Compatibility

- Additive for Flutterwave, Square, Mollie - no existing method signatures change.
- **Breaking**: `NowPaymentsDriver` and `payments.providers.nowpayments` no longer exist.
  Any application with `PAYMENTS_DEFAULT_PROVIDER=nowpayments` or
  `PAYMENTS_FALLBACK_PROVIDER=nowpayments`, or code explicitly calling
  `Payment::with('nowpayments')`, breaks on upgrade. Documented in the v2.0.0 changelog
  entry with no migration path offered beyond "remove NOWPayments usage" - this is an
  intentional product decision to drop crypto support, not a technical deprecation with
  an equivalent replacement.
