# Idempotency, retries and duplicate submissions

This chapter is about one question: **if the same payment is submitted twice, will the
customer be charged twice?**

PayZephyr takes this seriously enough to be precise about what it can and cannot promise.
Read the [What PayZephyr guarantees](#what-payzephyr-guarantees) section before relying on
any of it.

---

## The three identities

These are related but genuinely different things, and collapsing them causes bugs:

| Identity | What it names | Who sets it |
|---|---|---|
| **Reference** | One logical payment - "order #1234's payment attempt" | You (or PayZephyr generates one) |
| **Idempotency key** | One request to create that payment, as the provider sees it | Derived from the reference, or set by you |
| **Provider transaction ID** | The provider's own record of the charge | The provider, in its response |

A retry of a lost request is *the same logical payment* and *the same idempotency key*, but
will only ever have one provider transaction ID if the deduplication worked.

## The problem

```
Request A  ->  provider charges the customer  ->  response is lost in transit
                                                          |
Your app sees a timeout. Did it work? It cannot tell.      |
                                                          v
Request B  ->  ??? 
```

If request B reaches the provider as a brand new request, the customer pays twice. The only
thing that prevents this is B carrying an identity the provider recognises as "I already did
this one".

## What you should do

**Supply a stable `reference` for every charge**, derived from something in your own domain
that doesn't change between retries — an order ID, a checkout session ID, an invoice number:

```php
Payment::amount(50000)
    ->currency('NGN')
    ->email($user->email)
    ->reference('order_'.$order->id)   // <- stable across retries
    ->callback(route('payment.callback'))
    ->create();
```

Do **not** use something that changes per request (a random UUID generated in the controller,
a timestamp, a session token that rotates). If the reference changes on retry, no layer of
protection below can help you.

If you need the reference and the idempotency key to differ, set the key explicitly — it
always wins over the derived one:

```php
Payment::amount(50000)->reference('order_123')->idempotency('checkout_attempt_7')->create();
```

## The three layers of protection

### 1. Stable idempotency key sent to the provider

When you supply a `reference` and no explicit key, PayZephyr uses the reference as the
idempotency key sent to the provider. A retry therefore arrives carrying the *same* key,
and providers that honour idempotency keys return the original charge instead of creating
a second one.

This is the layer that protects the lost-response case, because it works even after your
process has died and restarted.

Whether a given provider actually honours the key is that provider's behaviour, not
PayZephyr's — see [Per-provider support](#per-provider-support).

### 2. In-flight claim (concurrent / rapid double submission)

Before contacting any provider, PayZephyr atomically claims the reference. A second
submission of the same reference arriving while the first is still in flight is rejected
with a `ProviderException` and **never reaches a provider**.

The claim is:

- **held** when the charge succeeds, so an immediate repeat submission is refused
- **held** when the outcome is ambiguous (see below), because the charge may have succeeded
- **released** when the charge definitively failed, so a legitimate retry can proceed

It expires on its own after a few minutes, so a crashed process cannot leave a payment
permanently unchargeable. That expiry is also why layer 1 matters: after the claim expires,
the idempotency key is what still protects you.

> **This layer requires a shared, atomic cache store** — `database`, `redis`, or `memcached`.
> The `array` and `file` drivers give no protection across processes, so a multi-server or
> multi-worker deployment on those drivers effectively has only layer 1.

### 3. Ambiguous-outcome detection (never fall back after a maybe-success)

PayZephyr distinguishes three outcomes rather than two:

| Outcome | Meaning | Fallback to another provider? |
|---|---|---|
| **Success** | The provider created the charge | Never - it already worked |
| **Definitive failure** | The provider responded, rejecting it (declined card, validation error, auth failure) | Yes, permitted |
| **Ambiguous** | The request was transmitted but no response came back (read timeout, connection reset mid-flight) | **Never** |

An ambiguous outcome throws immediately rather than trying the next provider, because the
first provider may already have charged the customer. Reconcile with
`Payment::verify($reference)` before doing anything else.

A connection that was never established is a *definitive* failure, not an ambiguous one —
nothing was transmitted, so nothing could have been processed.

## What PayZephyr guarantees

**It does not guarantee exactly-once payment processing.** No library that talks to a remote
system over an unreliable network can, and any that claims otherwise is wrong.

What it does guarantee:

1. A charge that has succeeded at one provider will **never** be retried against another
   provider because of a *local* failure (cache down, database down, a listener throwing).
   This is enforced structurally, not by convention.
2. An ambiguous outcome will **never** silently trigger another charge attempt.
3. When you supply a stable reference and use a shared atomic cache store, two concurrent
   submissions of the same payment will result in **at most one** provider call.
4. When you supply a stable reference, a retry carries the same idempotency key to the
   provider, so providers that honour it will not create a second charge.
5. Total successful refunds against a payment will never exceed the captured amount, subject
   to the same caveats about local state below.

What it explicitly **cannot** guarantee:

1. **If you supply no reference and no idempotency key, there is no protection at all.**
   Two submissions are indistinguishable from two genuinely different payments, and
   PayZephyr will not guess. Both will reach the provider.
2. **Providers that do not honour idempotency keys** can still create duplicates on a retry
   after the in-flight claim has expired.
3. **A process that dies between the provider confirming and PayZephyr recording it** leaves
   no local trace. The in-flight claim covers this for its TTL; after that, reconciliation is
   the only remedy. Always verify before re-charging.

## Refunds

Refunds have the same in-flight claim and ambiguous-outcome handling as charges, plus an
over-refund guard: the total of all refunds counted against a payment can never exceed the
captured amount.

The important difference: **a refund's identity cannot be derived automatically.** Multiple
sequential partial refunds against one transaction are legitimate, so the transaction
reference alone does not identify a single refund. If you need a refund to be retry-safe,
supply the key yourself:

```php
Payment::refund($transactionReference)
    ->amount(2500)
    ->idempotency('refund_for_return_'.$returnRequest->id)
    ->refund();
```

A refund status PayZephyr does not recognise is treated as *pending*, not failed — an unknown
outcome counts toward the refunded total so it cannot free up refundable balance that may
already have been spent.

## Per-provider support

Whether a provider honours an idempotency key on charge creation is that provider's
documented behaviour. PayZephyr sends the key using each provider's own mechanism (an HTTP
header for most, a request-body field for Square, the SDK's option for Stripe), but
**PayZephyr cannot verify the provider's side of that contract** — consult your provider's
API documentation, and test against their sandbox, before relying on it for a specific
provider.

## See also

- [ADR-0012](architecture/adr/0012-post-success-failure-isolation.md) - why post-success
  bookkeeping is isolated from the operation it follows
- [ADR-0013](architecture/adr/0013-charge-idempotency-identity.md) - how the logical payment
  identity was chosen
- [Refunds](refunds.md)
