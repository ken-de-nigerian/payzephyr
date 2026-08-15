# Refunds

## What makes a refund different from a charge or a verify

A charge moves money toward you; a refund moves some or all of it back. That sounds like a small difference, but it changes what your code needs to guard against: a refund always targets a *specific, already-completed* charge, it can be partial (issuing several refunds against the same charge until the original amount is exhausted), and several providers don't confirm it immediately. The API call only tells you the refund was *accepted*, and a webhook tells you whether it actually *completed*. Design your refund flow assuming the initial response might say "pending", not "done".

## Which providers support this

All eight supported providers: **Paystack, Stripe, PayPal, Flutterwave, Square, Mollie, Monnify, and OPay.** Unlike subscriptions, every provider PayZephyr talks to has a real refund endpoint, so refund support shipped to every driver in the same release rather than rolling out incrementally.

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

$refund = Payment::refund('txn_ref_123')
    ->with('stripe')
    ->refund();
```

If you write a custom driver (see [Custom Drivers](custom-drivers.md)) that doesn't implement `SupportsRefundsInterface`, calling `->refund()` against it throws a `PaymentException` telling you so, the same graceful-failure pattern subscriptions uses for Monnify/OPay.

## Issuing a refund

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Full refund of the original charge
$refund = Payment::refund('txn_ref_123')
    ->with('paystack')
    ->refund();

// Partial refund, with a reason for your own records
$refund = Payment::refund('txn_ref_123')
    ->amount(25.00)
    ->reason('customer requested partial refund')
    ->with('paystack')
    ->refund();
```

`transaction()` (or the shorthand first argument to `Payment::refund()`) takes the **original charge's reference**, the same value `Payment::verify($reference)` uses, not a refund-specific ID. `amount()` is optional: omit it for a full refund, or pass a smaller amount for a partial one. You can call `refund()` more than once against the same transaction reference, as long as the total across all refunds doesn't exceed the original charge. PayZephyr validates this for you (see [Preventing over-refunds and duplicates](#preventing-over-refunds-and-duplicates) below) whenever the original charge was logged locally.

For multi-currency merchants, pass `->currency('CAD')` explicitly when it differs from your default. Square, PayPal, Mollie, and OPay all require an explicit currency in the refund request itself (they can't infer it from the original charge the way Paystack, Flutterwave, Monnify, and Stripe do). If you omit it, PayZephyr falls back to your provider config's first configured currency, which is only correct for single-currency merchants:

```php
$refund = Payment::refund('txn_ref_123')
    ->amount(25.00)
    ->currency('CAD')
    ->with('square')
    ->refund();
```

### What you get back

```php
$refund->refundReference;       // save this - it's how you reference this refund later
$refund->transactionReference;  // the original charge's reference
$refund->status;                // raw provider status string, e.g. "pending", "processed", "succeeded"
$refund->amount;
$refund->currency;
$refund->reason;

$refund->isCompleted();
$refund->isPending();
$refund->isFailed();
```

**Save `refundReference` somewhere durable.** PayZephyr also logs every refund automatically to a `refund_transactions` table for you (see [Configuration](configuration.md#refunds)), so you can look it up there too.

## Sync vs. async confirmation

This is the most important thing to understand before you build a refund flow: not every provider tells you the final outcome in the `refund()` response itself.

| Provider | Initial response | Final confirmation |
|---|---|---|
| Paystack | Queued (`pending`/`processing`) | `refund.processed` / `refund.failed` webhook |
| Stripe | Often immediate (`succeeded`) for card refunds, `pending` for some payment methods | `charge.refunded` / `refund.updated` webhook if not immediate |
| PayPal | Usually immediate (`COMPLETED`) | Webhook for edge cases |
| Square | `PENDING` initially | `refund.updated` webhook |
| Flutterwave | Immediate in most cases | - |
| Mollie | `pending`/`processing` | Payment/refund status change, checked via `fetchRefund()` or webhook |
| Monnify | `PENDING` | Refund status webhook |
| OPay | `PENDING` | Refund status webhook |

If `$refund->isPending()` after calling `refund()`, don't treat the refund as done yet. Either poll with `fetch()` or (better) listen for the webhook-driven events below.

## Fetching a refund

```php
$refund = Payment::refund()->with('paystack')->fetch($refundReference);
```

## Listening for refund events

Because several providers confirm refunds asynchronously, webhooks matter here the same way they do for [subscription renewals](subscriptions.md#listening-for-subscription-lifecycle-changes). See [Events](events.md#refund-events) for the full set (`RefundCreated`, `RefundCompleted`, `RefundFailed`) and a realistic listener example, and [Webhooks](webhooks.md) for how they reach your app in the first place.

## Preventing over-refunds and duplicates

By default (`payments.refunds.validation.enabled`, on by default) PayZephyr runs two checks before it ever calls the provider:

1. **In-flight duplicate guard** (`payments.refunds.prevent_duplicates`, on by default): rejects a second refund attempt on a transaction that already has a `pending`/`processing` refund against it. This is aimed at the accidental-double-submission case, a double-clicked "refund" button or a retried request, not at blocking legitimate follow-up refunds; once an earlier refund reaches a terminal state (`completed`, `failed`, or `cancelled`), a new sequential partial refund is allowed again.
2. **Over-refund guard**: checks that a refund's amount doesn't exceed the original transaction's amount minus whatever has already been refunded against it.

Both checks are **best-effort**: the over-refund check only runs when the original charge was logged locally (`payments.logging.enabled`, on by default) and can be found by its reference. If it can't be found, because a different process logged it or logging is disabled, that check is skipped rather than blocking the refund; providers enforce their own over-refund limits server-side regardless. The duplicate guard has no such dependency, since it only needs PayZephyr's own `refund_transactions` log.

## Next steps

- [Events](events.md): the three refund lifecycle events, in full
- [Webhooks](webhooks.md): how refund webhooks get to your app in the first place
- [API Reference](api-reference.md): every `Refund` method
