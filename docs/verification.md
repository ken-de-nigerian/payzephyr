# Payment Verification

This chapter is a closer look at `Payment::verify()` — what it actually checks, what it returns, and the mistakes people commonly make around it. If you haven't read [Understanding Payment Flow](payment-flow.md) yet, read that first; it explains *why* verification exists at all.

## What verification actually does

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

$verification = Payment::verify($reference);
```

This makes a real, authenticated API call to whichever provider the payment was made through, asking it directly: "what is the current status of the payment with this reference?" The provider's answer — not anything in your own database, not anything in a URL — is what PayZephyr treats as truth.

A subtlety worth knowing: PayZephyr remembers which provider a reference belongs to, so you don't have to specify it — but if you're verifying a reference from a payment you know was made through a specific provider (say, you stored it), you can be explicit:

```php
Payment::verify($reference, 'stripe');
```

## What you get back

`verify()` returns a `VerificationResponseDTO` with everything you'd realistically need to reconcile the payment:

```php
$verification->reference;         // string   - the payment reference
$verification->status;            // string   - normalized status (see below)
$verification->amount;            // float    - the amount actually charged
$verification->currency;          // string   - e.g. "NGN", "USD"
$verification->paidAt;            // ?string  - when it was paid, if it was
$verification->channel;           // ?string  - "card", "bank_transfer", etc.
$verification->cardType;          // ?string  - e.g. "visa", if paid by card
$verification->bank;              // ?string  - issuing bank, if available
$verification->customer;          // ?array   - customer details from the provider
$verification->provider;          // ?string  - which provider processed this
$verification->metadata;          // array    - whatever metadata you attached at charge time

$verification->isSuccessful();    // bool
$verification->isFailed();        // bool
$verification->isPending();       // bool
```

**Always check the amount, not just success.** This is the single most important habit to build around verification:

```php
if ($verification->isSuccessful() && $verification->amount == $order->amount) {
    $order->update(['status' => 'paid']);
}
```

Why check the amount at all, if the provider already confirmed success? Because `isSuccessful()` only tells you *a* payment succeeded for that reference — checking the amount against what you expected to charge protects you against a class of bugs (not attacks — a forged amount wouldn't get past provider-side verification) where, say, a reference gets reused or a race condition lets an order's price change between charge and verify. It costs one comparison and removes an entire category of "why did this order get marked paid for the wrong amount" bug reports.

## Why status is a normalized string, not each provider's raw value

Every provider uses different words for the same thing — Paystack says `"success"`, Stripe's payment intents report `"succeeded"`, and so on. If PayZephyr handed you each provider's raw status string, your code would need a big `match` statement per provider just to answer "did this work or not," defeating the entire point of a unified API.

Instead, `$verification->status` is always one of four normalized values, backed by the `PaymentStatus` enum:

| Status | Meaning |
|---|---|
| `success` | Payment completed successfully |
| `failed` | Payment did not complete |
| `pending` | Still processing (common for bank transfers, USSD) |
| `cancelled` | Customer abandoned or explicitly cancelled |

Use the boolean helpers rather than string-comparing `$verification->status` yourself where you can — `isSuccessful()`, `isFailed()`, `isPending()` — since they're less error-prone than typing the string literal `'success'` correctly every time:

```php
match (true) {
    $verification->isSuccessful() => $order->markPaid(),
    $verification->isPending() => $order->markProcessing(), // e.g. bank transfer still clearing
    default => $order->markFailed(),
};
```

That `pending` case is worth calling out specifically: some payment channels (bank transfers and USSD payments, especially) don't complete instantly even when the customer has done everything right on their end — the provider is still waiting on the actual bank transfer to clear. Don't treat "not successful" as "failed" without checking for `pending` first, or you'll incorrectly fail orders that are actually still in progress.

## What happens when verification itself fails

`verify()` throws a `VerificationException` if the API call to the provider fails outright — a network timeout, the provider's API being down, an invalid reference that doesn't exist at all. This is different from the payment itself having failed (which comes back as a normal, successful `verify()` call with `$verification->isFailed() === true`).

```php
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

try {
    $verification = Payment::verify($reference);
} catch (VerificationException $e) {
    // The verification *request itself* failed - we genuinely don't know
    // the payment's status yet. Don't assume success or failure here.
    Log::warning('Could not verify payment', ['reference' => $reference, 'error' => $e->getMessage()]);
    return back()->with('error', 'We could not confirm your payment. Please contact support with reference: '.$reference);
}
```

The distinction matters for how you handle it: a normal `failed` status means the customer's payment genuinely didn't go through and you can tell them that directly. An exception means you *don't know* — telling the customer "your payment failed" when it might have actually succeeded (and you just couldn't reach the provider to confirm it) is worse than telling them you're still checking. See [Error Handling](error-handling.md) for the full exception hierarchy.

## Common mistakes

**Trusting a `status` query parameter instead of calling `verify()`.** Covered in [Understanding Payment Flow](payment-flow.md#why-verify-instead-of-trusting-the-redirect) — worth repeating here because it's the most common shortcut people are tempted to take, and it's a real vulnerability if you skip verification and act on the URL directly.

**Verifying only in the callback route, never from the webhook.** The callback only runs if the customer's browser makes it back to your app. If you only ever mark orders paid from the callback, orders whose customers closed the tab early stay `pending` forever, even though they paid. See [Webhooks](webhooks.md).

**Re-processing a payment you've already marked paid.** If your callback and your webhook handler can both mark the same order paid, make sure doing so twice is harmless (an idempotent "set status to paid" update, not something like "increment a paid counter"). PayZephyr's own webhook processing already deduplicates at the delivery level (see [Webhooks](webhooks.md#duplicate-deliveries)), but your own business logic reacting to it should still be safe to run more than once.

## Next steps

- [Webhooks](webhooks.md) — the piece that makes verification reliable even when a customer never returns to your callback
- [Error Handling](error-handling.md) — the full exception hierarchy, not just `VerificationException`
