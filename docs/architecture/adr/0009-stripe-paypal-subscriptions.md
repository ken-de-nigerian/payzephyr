# ADR-0009: Stripe and PayPal subscription support

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

Only `PaystackDriver` implements `SupportsSubscriptionsInterface`. ADR-0006 fixed the
interface so a non-Paystack driver *could* implement it cleanly, but nothing actually did -
the original roadmap's "Add Stripe and PayPal subscription drivers" (Task 1.3) was never
built. A package pitched as a unified subscription layer that only unifies one provider
out of nine is a real credibility gap, not a cosmetic one.

Both providers' subscription models differ from Paystack's in ways that don't map
1:1 onto the existing DTOs, and pretending otherwise would produce a broken or
misleading implementation. Each divergence below was a real design decision, not
an oversight.

## Decisions

### Stripe

- **Plans map to Stripe Price objects** (not the deprecated standalone Plan
  resource), each attached to a Product created alongside it.
  `PlanResponseDTO::planCode` is the Stripe **Price ID** - that's what
  `createSubscription()` actually references.
- **Stripe Prices are immutable for amount/currency/interval.**
  `updatePlan()` therefore branches: metadata/nickname changes update the existing
  Price in place; an amount or interval change creates a **new** Price under the same
  Product and returns *that* as the result, with the old Price left in place
  (Stripe's own recommended pattern - existing subscriptions keep billing at their
  original price unless explicitly migrated, which this package does not do
  automatically). Documented on the method, not silently different from Paystack's
  true in-place update.
- **`createSubscription()` requires `$request->authorization`** (a Stripe
  PaymentMethod ID) to be already attached to a Customer - exactly the same
  precondition Paystack's driver already has (Paystack also requires a prior
  `authorization_code` from a completed charge). No new asymmetry introduced; if
  omitted, throws a clear `SubscriptionException` rather than silently creating an
  `incomplete` subscription with no way to collect payment.
- **`enableSubscription()` on a subscription already in Stripe's terminal `canceled`
  status throws.** Stripe cannot reactivate a fully-canceled subscription (unlike
  Paystack) - only one still pending `cancel_at_period_end` can be resumed
  (`cancel_at_period_end: false`). This is a real capability difference between
  providers, not a bug to paper over.
- `cancelSubscription()` supports `$action->option('at_period_end', false)` -
  Stripe's own two cancellation modes, exposed through the same options bag
  ADR-0006 introduced for exactly this kind of provider-specific parameter.
- **Pagination**: Stripe's list APIs are cursor-based, not page-numbered.
  `listPlans()`/`listSubscriptions()` honor `$perPage` but can only serve page 1
  faithfully; a `$page > 1` request logs a warning and still returns page 1 rather
  than silently returning wrong data or throwing.

### PayPal

- **Plans require a Catalog Product first** (`/v1/catalogs/products`), created
  alongside the Plan (`/v1/billing/plans`) and referenced by `product_id` in the
  plan's metadata for later lookups.
- **`cancelSubscription()`/`enableSubscription()` map to PayPal's `/suspend` and
  `/activate` endpoints, not `/cancel`.** This is the correct semantic match:
  PayPal's `/cancel` is permanent and irreversible (no "enable" can undo it), while
  `/suspend` + `/activate` is the reversible pair - the same shape as Paystack's
  disable/enable. Permanent cancellation is still reachable via
  `$action->option('permanent', true)`, which routes to `/cancel` instead.
- **`SubscriptionRequestDTO` gains an optional `callbackUrl` field** (see the
  accompanying DTO change) - PayPal subscriptions require `return_url`/`cancel_url`
  for the customer's approval redirect, and no existing field carried one. Additive,
  defaults to `null`, does not change any existing call site.
- **A newly-created PayPal subscription is not immediately `active`** - it starts
  `APPROVAL_PENDING` until the customer completes the redirect approval flow (the
  `approve` link is returned in `metadata['approval_url']`). This mirrors the
  existing `charge()` flow's redirect pattern rather than inventing a new one.
- **Pagination**: PayPal's Plans list endpoint genuinely supports `page`/`page_size`
  query parameters - no cursor-pagination caveat needed here, unlike Stripe.

## Why

Every divergence above traces back to a real API constraint (Stripe Price immutability,
PayPal's suspend/cancel split, cursor vs. page pagination) verified against each
provider's actual API shape, not assumed. Where a constraint meant the four methods
can't behave identically to Paystack's, the driver throws a clear, specific exception
rather than approximating incorrect behavior - consistent with this package's existing
pattern of failing loudly rather than silently on unsupported operations (see
`Subscription.php`'s `SupportsSubscriptionsInterface` capability checks).

## Trade-offs

- Neither driver's `updatePlan()`/`enableSubscription()` behaves identically to
  Paystack's - callers writing provider-agnostic code must handle the documented
  exception paths for Stripe's terminal-cancel case. Accepted: hiding this would be
  worse than surfacing it, and it reflects a genuine capability difference, not an
  implementation gap.
- PayPal subscription creation returns a non-active subscription requiring
  out-of-band customer approval, unlike Paystack's `authorization`-based flow
  which activates immediately. Accepted: this is how PayPal's product actually
  works; a caller integrating PayPal subscriptions needs to redirect the customer
  regardless of what this package does internally.

## Backward Compatibility

- `SubscriptionRequestDTO`'s new `callbackUrl` parameter is optional with a
  `null` default, appended after existing parameters - no existing call site
  (positional or named) breaks.
- No changes to `SupportsSubscriptionsInterface` itself (already finalized in
  ADR-0006) - this ADR is purely additive driver implementations against that
  existing contract.
