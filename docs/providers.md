# Multiple Providers

## Why support more than one provider at all?

The simplest reason: **different providers are strong in different regions and currencies.** Paystack, Flutterwave, Monnify, and OPay are built around African markets and Naira-denominated payments; Stripe, PayPal, and Square are strongest for US/EU cards; Mollie specializes in European payment methods. If your customers span more than one of these regions, you likely need more than one provider, and PayZephyr's whole point is that supporting a second one doesn't mean writing a second, parallel checkout implementation.

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

**One thing this doesn't protect against:** if the customer already completed payment on provider A's checkout page and something fails on *your side* afterward, falling back to provider B doesn't "undo" or "retry" that payment; fallback only applies to the *initial charge request*, before the customer has been sent anywhere.

## The eight providers

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

## Subscription and refund support at a glance

| Provider | Subscriptions | Refunds |
|---|---|---|
| Paystack | ✅ | ✅ |
| Stripe | ✅ | ✅ |
| PayPal | ✅ | ✅ |
| Flutterwave | ✅ | ✅ |
| Square | ✅ | ✅ |
| Mollie | ✅ | ✅ |
| Monnify | ❌ | ✅ |
| OPay | ❌ | ✅ |

## Next steps

- [Configuration](configuration.md#provider-credentials): the exact required keys per provider, in table form
- [Subscriptions](subscriptions.md): provider-specific subscription quirks, in depth
- [Refunds](refunds.md): provider-specific refund quirks, in depth
- [Custom Drivers](custom-drivers.md): adding a ninth provider yourself
