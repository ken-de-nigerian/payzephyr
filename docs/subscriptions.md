# Subscriptions

## What makes subscriptions different from a payment

Everything so far in this documentation has been about a single, one-time charge: customer pays once, you verify it once, done. A subscription is different in one fundamental way — **it keeps charging the customer on its own, on a schedule, with nobody clicking anything.** Your app's job shifts from "process this one payment" to "know what to do when the provider tells us a recurring charge happened, or didn't."

That's why subscriptions lean much more heavily on [webhooks](webhooks.md) and [events](events.md) than one-time payments do — a renewal isn't something your code initiates, so a webhook is the *only* way you find out about it.

## Which providers support this

**Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie** — six of the eight supported providers. Monnify and OPay don't have a provider-managed subscription product to wrap (Monnify's recurring-payment tools are merchant-triggered repeat-charge primitives, not a provider-tracked subscription entity; OPay has no subscription API in its documentation at all), so PayZephyr doesn't claim support for them. If you specifically need Monnify or OPay subscriptions, see [Custom Drivers](custom-drivers.md) — though be aware you'd be building a scheduling/retry engine yourself, not wrapping something the provider already does for you.

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Throws PaymentException: "monnify does not support subscriptions"
Payment::subscription()->customer('a@b.com')->plan('x')->with('monnify')->create();
```

## The building blocks

A subscription is built from two things: a **plan** (what's being sold — "Pro tier, $20/month") and a **subscription** (a specific customer subscribed to that plan). You create a plan once, then subscribe as many customers to it as you like.

### Creating a plan

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

$plan = Payment::subscription()
    ->planData(new SubscriptionPlanDTO(
        name: 'Pro Monthly',
        amount: 20.00,
        interval: 'monthly',   // daily | weekly | monthly | annually
        currency: 'USD',
        description: 'Full access, billed monthly',
    ))
    ->with('stripe')
    ->createPlan();

$plan->planCode; // save this - you'll reference it when subscribing customers
```

`createPlan()` returns a `PlanResponseDTO` — note that it's an **object**, not an array. `$plan->planCode`, not `$plan['plan_code']`.

### Subscribing a customer

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan($plan->planCode)
    ->idempotency()  // auto-generates a UUID; prevents double-subscribing on a retried request
    ->with('stripe')
    ->subscribe();   // create() is an identical alias
```

**Most providers require an existing payment method before you can subscribe a customer.** This makes sense if you think about what a subscription actually needs to do: charge a card automatically, next month, with nobody present to enter their card details again. Stripe, Square, and Flutterwave specifically require `->authorization(...)` — a token from a *previous* charge that authorizes future charges on that card:

```php
// Step 1: charge the customer once, saving the card for future use
$charge = Payment::amount(0.50)->email('customer@example.com')->with('stripe')->charge();
// (how you obtain the reusable authorization/payment-method token varies by
// provider's checkout flow - see each provider's own documentation for the
// exact mechanism, e.g. Stripe Elements/Checkout with `setup_future_usage`)

// Step 2: use it to create the subscription
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan($plan->planCode)
    ->authorization($savedCardToken)
    ->with('stripe')
    ->subscribe();
```

Paystack and PayPal don't need this step in the same way — Paystack's own hosted checkout captures the authorization as part of subscribing, and PayPal's subscription flow sends the customer through PayPal's own approval page. If you get a `SubscriptionException` complaining about a missing authorization, that's the provider telling you a payment method needs to exist first — see [Error Handling](error-handling.md).

### What you get back

```php
$subscription->subscriptionCode;   // save this - it's how you reference this subscription later
$subscription->status;             // "active", "cancelled", "non-renewing", etc.
$subscription->customer;
$subscription->plan;
$subscription->amount;
$subscription->nextPaymentDate;
$subscription->emailToken;         // Paystack-specific, see below

$subscription->isActive();
$subscription->isCancelled();
$subscription->canBeCancelled();
$subscription->canBeResumed();
```

**Save `subscriptionCode` somewhere durable** — your own `subscriptions` table, most likely. It's the only handle you have on this subscription for every future operation (cancel, fetch, list). PayZephyr also logs it automatically to a `subscription_transactions` table for you (see [Configuration](configuration.md#subscriptions)), so you can always look it up there too, but keeping your own reference (e.g., a `subscription_code` column on your `User` or `Team` model) is worth doing so you don't have to query PayZephyr's log every time you need to check a user's plan.

## Cancelling and re-enabling

```php
Payment::subscription($subscription->subscriptionCode)->with('stripe')->cancel();
Payment::subscription($subscription->subscriptionCode)->with('stripe')->enable();
```

**Not every provider treats "cancel" the same way**, and this is worth understanding before you build a "cancel subscription" button:

| Provider | What `cancel()` actually does | Can `enable()` bring it back? |
|---|---|---|
| Paystack | Disables (reversible) | Yes |
| Stripe | Immediate, by default | No — create a new subscription instead |
| PayPal | Suspends (reversible) | Yes |
| Flutterwave | Cancels via provider's `/cancel` endpoint | Yes, via `/activate` |
| Square | Pauses (reversible) | Yes |
| Mollie | Terminal — Mollie has no resume concept | No — `enable()` always throws |

Stripe specifically also supports "cancel at the end of the current billing period" instead of immediately, via an option:

```php
Payment::subscription($subscription->subscriptionCode)
    ->option('at_period_end', true)
    ->with('stripe')
    ->cancel();
```

**Paystack requires an email confirmation token to cancel or enable** — a security measure on their end so a subscription can't be cancelled by anyone who merely knows the subscription code. That token comes back as `$subscription->emailToken` when you create the subscription:

```php
Payment::subscription($subscription->subscriptionCode)
    ->token($subscription->emailToken)
    ->with('paystack')
    ->cancel();
```

If you're building a generic "cancel" button meant to work across providers, check `$subscription->canBeCancelled()` before showing it, and be ready to catch a `SubscriptionException` for the providers (Mollie, most notably) where "re-enable" simply isn't possible.

## Mollie's subscription codes look different — here's why

Every other provider identifies a subscription with one ID. Mollie's API requires *two* — a customer ID and a subscription ID — for every operation. Rather than bolt a second parameter onto every subscription method just for Mollie's benefit, PayZephyr encodes both into a single string: `"{customerId}:{subscriptionId}"`. You don't need to construct this yourself — `$subscription->subscriptionCode` already comes back in this format when you create a Mollie subscription, and you just pass it back the same way for every later operation. It's just worth knowing if you ever inspect the raw string and wonder why it has a colon in it.

## Listening for subscription lifecycle changes

This is where webhooks stop being optional for subscriptions — a renewal, a cancellation initiated from the provider's side, or a failed renewal payment are all things that happen *without your app doing anything*, and a webhook is the only way to find out. See [Events](events.md#subscription-events) for the full set of events (`SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCancelled`, `SubscriptionPaymentFailed`) and a realistic example listener.

## Finding subscriptions

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

$activeSubscriptions = Payment::subscriptions()
    ->forCustomer('customer@example.com')
    ->active()
    ->from('stripe')
    ->get(); // array<SubscriptionResponseDTO>

$count = Payment::subscriptions()->forPlan($plan->planCode)->count();
$exists = Payment::subscriptions()->forCustomer('customer@example.com')->exists();
```

Available filters: `forCustomer()`, `forPlan()`, `whereStatus()`, `active()`, `cancelled()`, `createdAfter()`, `createdBefore()`, plus `take()`/`page()` for pagination and `from()` to scope the query to one provider. Terminal methods: `get()`, `first()`, `count()`, `exists()`.

## Managing plans

```php
Payment::subscription()->code($plan->planCode)->with('stripe')->fetchPlan();
Payment::subscription()->code($plan->planCode)->planUpdates(['name' => 'Pro Monthly (New Price)'])->with('stripe')->updatePlan();
Payment::subscription()->with('stripe')->listPlans();
```

**Mollie has no server-side plan concept at all** — every other provider stores your plan on their servers and gives you back an ID that references it, but Mollie subscriptions carry their amount/interval/description directly. PayZephyr works around this by encoding the plan into a self-describing string client-side rather than calling Mollie's API for `createPlan()`. The practical effect: `listPlans()` isn't meaningful for Mollie (there's nothing server-side to list) and throws a clear exception explaining why, rather than silently returning an empty array.

## Next steps

- [Events](events.md) — the four subscription lifecycle events, in full
- [Webhooks](webhooks.md) — how subscription webhooks get to your app in the first place
- [API Reference](api-reference.md) — every `Subscription` and `SubscriptionQuery` method
