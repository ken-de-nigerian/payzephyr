# Multiple Providers

## Why support more than one provider at all?

The simplest reason: **different providers are strong in different regions and currencies.** Paystack, Flutterwave, Monnify, and OPay are built around African markets and Naira-denominated payments; Stripe, PayPal, and Square are strongest for US/EU cards; Mollie specializes in European payment methods; Razorpay is built around Indian payments (INR, UPI, netbanking). If your customers span more than one of these regions, you likely need more than one provider, and PayZephyr's whole point is that supporting a second one doesn't mean writing a second, parallel checkout implementation.

The second reason is resilience: if your primary provider has an outage, [automatic fallback](#automatic-fallback) means your checkout keeps working through a backup provider instead of going down with it.

## Choosing a provider for a call

Every example so far in this documentation has used `->with('paystack')` explicitly, or relied on `PAYMENTS_DEFAULT_PROVIDER`. You have three levels of control:

```php
// Use whatever config('payments.default') is set to
Payment::amount(100)->email('a@b.com')->redirect();

// Use one specific provider for this call
Payment::amount(100)->email('a@b.com')->with('stripe')->redirect();

// Try a list in order - the first one that succeeds wins
Payment::amount(100)->email('a@b.com')->with(['paystack', 'stripe'])->redirect();
```

`->with()` and `->using()` are identical: two names for the same method, so you can use whichever reads better in context.

## Automatic fallback

```php
Payment::amount(100.00)
    ->email('customer@example.com')
    ->with(['paystack', 'stripe']) // try paystack first, fall back to stripe
    ->redirect();
```

If the first provider's request fails (a network error, the provider's API returning an error), PayZephyr automatically tries the next one in the list, without you writing any retry logic. This also happens implicitly using `PAYMENTS_FALLBACK_PROVIDER` from [Configuration](configuration.md#default-and-fallback-providers) when you don't specify a list explicitly.

**Fallback is not unconditional, and the exception matters.** If a provider's outcome is
*ambiguous* - the request was transmitted but no usable response came back, so it may already
have charged the customer - PayZephyr throws immediately and does **not** try the next provider.
Falling back there could charge the customer twice. You get a `ProviderException` naming the
provider to reconcile with; verify with `Payment::verify($reference)` before charging again. A
connection that was never established is a definitive failure, not an ambiguous one, and does
fall back normally. See [Idempotency](idempotency.md#3-ambiguous-outcome-detection-never-fall-back-after-a-maybe-success).

A provider can also be passed over before it is ever contacted: if it does not support the
request's currency, or if it fails its health check. Both are recorded on the payment's timeline
with the reason, when [tracing](tracing.md) is on.

**One thing this doesn't protect against:** if the customer already completed payment on provider A's checkout page and something fails on *your side* afterward, falling back to provider B doesn't "undo" or "retry" that payment; fallback only applies to the *initial charge request*, before the customer has been sent anywhere.

## The bundled providers

Every provider needs `enabled` set to `true` in `.env` before PayZephyr will route traffic to it; see [Configuration](configuration.md#provider-credentials) for the required keys per provider. What's below is what's genuinely different about each one.

### Paystack

```env
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true
```

- **Currencies:** NGN, GHS, ZAR, USD
- **Channels:** card, bank transfer, USSD, mobile money, QR
- **Subscriptions:** ✅ full support: see [Subscriptions](subscriptions.md); this is the one provider whose cancel/enable operations need an extra `emailToken`
- **Refunds:** ✅ full support, processed asynchronously (initial response is `pending`; final status arrives via the `refund.processed`/`refund.failed` webhook)
- The default provider out of the box, and generally the easiest to get a test payment working with quickly if you're starting from zero

### Stripe

```env
STRIPE_SECRET_KEY=sk_test_xxxxx
STRIPE_PUBLIC_KEY=pk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx
STRIPE_ENABLED=true
```

- **Currencies:** USD, EUR, GBP, CAD, AUD
- **Subscriptions:** ✅ full support; subscribing a customer requires a saved payment method (`->authorization(...)`); see [Subscriptions](subscriptions.md#the-building-blocks)
- **Refunds:** ✅ full support via Stripe's native refunds resource; card refunds are usually immediate, some payment methods confirm via webhook
- `STRIPE_WEBHOOK_SECRET` isn't optional in practice: Stripe's webhook signature verification needs it to function at all

### PayPal

```env
PAYPAL_CLIENT_ID=xxxxx
PAYPAL_CLIENT_SECRET=xxxxx
PAYPAL_WEBHOOK_ID=xxxxx
PAYPAL_MODE=sandbox
PAYPAL_ENABLED=true
```

- **Currencies:** USD, EUR, GBP, CAD, AUD
- **Subscriptions:** ✅ full support, via PayPal's own subscription-approval checkout flow (the customer approves the subscription on PayPal's page, similar to how a one-time charge works); needs `->callbackUrl(...)` set, see [Subscriptions](subscriptions.md)
- **Refunds:** ✅ full support, issued against the capture id (not the order id) - usually immediate
- `PAYPAL_MODE` controls sandbox vs. live: switch this alongside your credentials when going to production, not just the keys themselves
- PayPal webhook verification calls back to PayPal's own verification API rather than checking a local signature, which is why it's one of the providers whose webhook processing specifically depends on [queues](queues.md#what-gets-queued-and-why) working correctly

### Flutterwave

```env
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST_xxxxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST_xxxxx
FLUTTERWAVE_ENCRYPTION_KEY=xxxxx
FLUTTERWAVE_ENABLED=true
```

- **Currencies:** NGN, USD, EUR, GBP, KES, UGX, TZS
- **Channels:** card, bank transfer, USSD, mobile money
- **Subscriptions:** ✅ supported: subscribing a customer is a side effect of a tokenized charge (`->authorization(...)` required), not a standalone API call; see [Subscriptions](subscriptions.md)
- **Refunds:** ✅ full support, usually immediate
- Flutterwave's webhook signature check uses `FLUTTERWAVE_ENCRYPTION_KEY` (mapped internally to the webhook secret), not a separate dedicated webhook-secret field

### Square

```env
SQUARE_ACCESS_TOKEN=xxxxx
SQUARE_LOCATION_ID=xxxxx
SQUARE_WEBHOOK_SIGNATURE_KEY=xxxxx
SQUARE_ENABLED=true
```

- **Currencies:** USD, CAD, GBP, AUD
- **Subscriptions:** ✅ supported; requires a Square card-on-file ID via `->authorization(...)`; cancelling pauses rather than permanently ending the subscription (see [Subscriptions](subscriptions.md#cancelling-and-re-enabling))
- **Refunds:** ✅ full support, starts `PENDING` and confirms via webhook
- Needs a `location_id` in addition to the usual access token: Square's API is organized around physical/logical business locations, and every charge and subscription needs to know which one it belongs to

### Monnify

```env
MONNIFY_API_KEY=MK_TEST_xxxxx
MONNIFY_SECRET_KEY=xxxxx
MONNIFY_CONTRACT_CODE=xxxxx
MONNIFY_ENABLED=true
```

- **Currencies:** NGN only
- **Subscriptions:** ❌ not supported: Monnify's recurring-payment tools are merchant-triggered repeat charges, not a provider-managed subscription entity PayZephyr can wrap. See [Subscriptions](subscriptions.md#which-providers-support-this).
- **Refunds:** ✅ full support; the caller-generated refund reference and status webhook follow the same shape as the charge/verify flow
- Needs a `contract_code` in addition to API credentials, specific to how Monnify structures merchant accounts

### OPay

```env
OPAY_MERCHANT_ID=xxxxx
OPAY_PUBLIC_KEY=xxxxx
OPAY_SECRET_KEY=xxxxx
OPAY_ENABLED=true
```

- **Currencies:** NGN only
- **Subscriptions:** ❌ not supported: no subscription API exists in OPay's own documentation
- **Refunds:** ✅ full support; authenticated the same HMAC-SHA512-signed way as the status API
- `OPAY_SECRET_KEY` is specifically required for webhook signature validation, separate from the public key used for charges

### Paddle

```env
PADDLE_API_KEY=pdl_sdbx_apikey_xxxxx
PADDLE_WEBHOOK_SECRET=pdl_ntfset_xxxxx
PADDLE_BASE_URL=https://sandbox-api.paddle.com
PADDLE_ENABLED=true
```

- **Currencies:** USD, EUR, GBP, CAD, AUD, JPY, CHF, SGD, SEK (Paddle supports more; extend the `currencies` list in `config/payments.php` if you bill in one of them)
- **Subscriptions:** ❌ not supported: Paddle subscriptions can't be created through the API at all — they're created by Paddle when a recurring-price checkout completes, and only then can be updated. That's not the same shape as `Payment::subscription()->create()`, so `PaddleDriver` deliberately doesn't claim subscription support rather than pretending to. Use a recurring catalog price plus the `subscription.*` webhooks instead.
- **Refunds:** ✅ supported, but Paddle has no refunds resource: a refund is an **adjustment** with `action: refund`, and for live accounts most start as `pending_approval` until Paddle reviews them. So a Paddle refund response is normally `pending` even when nothing went wrong; the terminal status arrives via the `adjustment.updated` webhook. Sandbox accounts auto-approve roughly every ten minutes.
- **This is Paddle Billing, not Paddle Classic.** The two are separate products with different APIs; Classic credentials will not work here.
- **`PADDLE_BASE_URL` is the environment switch.** Sandbox is `https://sandbox-api.paddle.com`, live is `https://api.paddle.com`, and the default is sandbox so an unconfigured install can't accidentally charge real cards.
- **A charge needs an approved default payment link.** PayZephyr creates a transaction with a single non-catalog item and returns Paddle's `checkout.url`; Paddle only populates that URL once you've set and had approved a default payment link under **Paddle > Checkout > Checkout settings**. Without it the charge fails with a clear error rather than silently returning nothing.
- **Partial refunds only work on single-item transactions.** Paddle requires a partial adjustment to name the transaction item it applies to. Charges PayZephyr creates always have exactly one item, so this is transparent — but a transaction created elsewhere (a subscription renewal, a multi-item checkout) will be rejected with an explanatory error instead of PayZephyr guessing which item you meant. The refund amount is converted to minor units using the *transaction's* currency, read from that same lookup, so `->currency()` is not required on a Paddle partial refund and cannot make it wrong.
- **Paddle has no idempotency key.** Unlike every other bundled provider, Paddle Billing accepts no client-supplied idempotency key, so PayZephyr sends none. The in-flight claim and ambiguous-outcome protections still apply; see [Idempotency](idempotency.md#per-provider-support).
- **Only `transaction.*` webhooks set a payment status.** Paddle sends transaction, subscription and adjustment events to one endpoint and copies `custom_data` between transactions and subscriptions, so PayZephyr scopes payment-status extraction to transaction events rather than letting a subscription's `active` land on a payment row.
- **`PADDLE_WEBHOOK_SECRET` is per notification destination, not per account.** Each destination you create in **Developer tools > Notifications** gets its own secret key; the one you configure must belong to the destination that's actually sending to your endpoint.

### Mollie

```env
MOLLIE_API_KEY=test_xxxxx
MOLLIE_WEBHOOK_SECRET=xxxxx
MOLLIE_ENABLED=true
```

- **Currencies:** EUR, USD, GBP, CHF, SEK, NOK, DKK, PLN, CZK, HUF (the widest currency list of any supported provider)
- **Subscriptions:** ✅ supported, with two structural quirks worth reading about before you use them: composite subscription codes, and no server-side plan storage; both covered in [Subscriptions](subscriptions.md#mollies-subscription-codes-look-different-heres-why)
- **Refunds:** ✅ full support; refund references are also composite (`"{paymentId}:{refundId}"`), the same reasoning as Mollie's subscription codes
- If `MOLLIE_WEBHOOK_SECRET` isn't set, PayZephyr falls back to verifying webhooks by calling Mollie's API directly instead of checking a local signature; functionally fine, but slower per webhook, and specifically why Mollie is one of the providers whose webhook handling depends on a correctly running [queue worker](queues.md)

### Razorpay

```env
RAZORPAY_KEY_ID=rzp_test_xxxxx
RAZORPAY_KEY_SECRET=xxxxx
RAZORPAY_WEBHOOK_SECRET=xxxxx
RAZORPAY_ENABLED=true
```

- **Currencies:** INR by default. Razorpay accepts many more once international payments are enabled on your account; add them to the `currencies` list in `config/payments.php`. Amounts are sent with each currency's own exponent, so zero-decimal currencies (JPY, KRW, ...) and three-decimal ones (KWD, BHD, OMR, ...) are converted correctly.
- **Channels:** card, netbanking, UPI, and wallet. `bank_transfer`/`bank_account` map to netbanking, `mobile_money`/`qr_code` to UPI, and `digital_wallet` to wallet; USSD and PayPal have no Razorpay equivalent.
- **Subscriptions:** ❌ not supported yet. Razorpay has a subscriptions API (plans, an authorization link, cancel/pause/resume); the driver doesn't wrap it in this release.
- **Refunds:** ✅ supported, and usually asynchronous: the initial response is normally `pending` (an instant refund can already be `processed`), and the final status arrives on the `refund.processed`/`refund.failed` webhook. Pass the charge's reference as usual; PayZephyr finds the payment behind it, takes the currency from Razorpay, and refuses a payment with nothing left to refund before sending anything. Razorpay refunds at least INR 1.00.
- **A charge is a Payment Link.** PayZephyr creates a [Payment Link](https://razorpay.com/docs/payments/payment-links/) and redirects the customer to its `short_url`. Your `reference` becomes the link's `reference_id`, which Razorpay requires to be unique and at most **40 characters**; a longer reference fails before any request is sent. Payment Links are available to Razorpay accounts in India, Malaysia, Singapore, and the US.
- **Test or live is decided by the keys**, not the URL: `rzp_test_` keys use test mode on the same `https://api.razorpay.com` host.
- **`RAZORPAY_WEBHOOK_SECRET` is the secret you enter when creating the webhook in the Razorpay Dashboard**, not your API key secret. Without it every webhook is rejected. Subscribe to `payment_link.paid`, `payment_link.expired`, `payment_link.cancelled`, `refund.processed`, and `refund.failed`. Leave `refund.created` off: an instant refund arrives on it already processed, so your `RefundCompleted` listeners would run twice.
- **No timestamp window on webhooks.** Razorpay's webhook `created_at` is when the link or refund was created, not when the event happened, so a link paid ten minutes after it was created would fail a five-minute replay window. Webhooks are verified by signature, and a replayed delivery is skipped by [duplicate-delivery deduplication](webhooks.md#duplicate-deliveries), the same approach as Mollie's signature path.
- **A lookup by reference can briefly miss a brand-new link.** Razorpay's reference search trails link creation by a few seconds. `Payment::verify()` normally uses the stored link id and is unaffected.

## Subscription and refund support at a glance

| Provider | Subscriptions | Refunds |
|---|---|---|
| Paystack | ✅ | ✅ |
| Stripe | ✅ | ✅ |
| PayPal | ✅ | ✅ |
| Flutterwave | ✅ | ✅ |
| Square | ✅ | ✅ |
| Mollie | ✅ | ✅ |
| Paddle | ❌ | ✅ |
| Monnify | ❌ | ✅ |
| OPay | ❌ | ✅ |
| Razorpay | ❌ | ✅ |

## Next steps

- [Configuration](configuration.md#provider-credentials): the exact required keys per provider, in table form
- [Subscriptions](subscriptions.md): provider-specific subscription quirks, in depth
- [Refunds](refunds.md): provider-specific refund quirks, in depth
- [Custom Drivers](custom-drivers.md): adding a tenth provider yourself
