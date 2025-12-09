# Payment Providers

Complete guide to all supported payment providers.

## Paystack

### Configuration

```env
PAYSTACK_SECRET_KEY=sk_live_xxx
PAYSTACK_PUBLIC_KEY=pk_live_xxx
PAYSTACK_ENABLED=true
```

### Supported Currencies
- NGN (Nigerian Naira)
- GHS (Ghanaian Cedi)
- ZAR (South African Rand)
- USD (US Dollar)

### Features
- Redirect-based checkout
- Card payments
- Bank transfer
- USSD
- Mobile money
- Recurring payments

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->metadata(['order_id' => 123])
    ->with('paystack') // or ->using('paystack')
    ->redirect();
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/paystack`

Paystack sends webhooks for:
- charge.success
- charge.failed
- transfer.success
- transfer.failed

### Testing

Test cards:
- Success: 4084084084084081
- Decline: 4084084084084081 (PIN: 0000)

---

## Flutterwave

### Configuration

```env
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST-xxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST-xxx
FLUTTERWAVE_ENCRYPTION_KEY=FLWSECK_TESTxxx
FLUTTERWAVE_ENABLED=true
```

### Supported Currencies
- NGN, USD, EUR, GBP
- KES, UGX, TZS
- GHS, ZAR, XAF, XOF

### Features
- Card payments
- Mobile money (M-Pesa, MTN, etc.)
- Bank transfer
- USSD
- Multiple African currencies

### Usage Example

```php
// Builder methods can be chained in any order
Payment::amount(10000)
    ->currency('KES')
    ->email('customer@example.com')
    ->with('flutterwave') // or ->using('flutterwave')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/flutterwave`

Use the `verif-hash` from your dashboard.

---

## Monnify

### Configuration

```env
MONNIFY_API_KEY=MK_TEST_xxx
MONNIFY_SECRET_KEY=xxx
MONNIFY_CONTRACT_CODE=xxx
MONNIFY_ENABLED=true
```

### Supported Currencies
- NGN only

### Features
- Card payments
- Bank transfer
- Dynamic account generation
- Split payments

### Usage Example

```php
// Builder methods can be chained in any order
Payment::amount(25000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->customer(['name' => 'John Doe'])
    ->with('monnify') // or ->using('monnify')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/monnify`

---

## Stripe

### Configuration

```env
STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_PUBLIC_KEY=pk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_ENABLED=true
```

### Supported Currencies
- USD, EUR, GBP, CAD, AUD
- 135+ currencies

### Features
- Card payments
- Apple Pay, Google Pay
- Bank transfers (ACH, SEPA)
- Local payment methods
- Strong Customer Authentication (SCA)
- Payment Intents API

### Usage Example

```php
// Get client secret for frontend
// Use charge() instead of redirect() to get response object
$response = Payment::amount(10000)
    ->currency('USD')
    ->email('customer@example.com')
    ->with('stripe') // or ->using('stripe')
    ->charge(); // Must be called last

return response()->json([
    'client_secret' => $response->metadata['client_secret'],
]);
```

Frontend (JavaScript):
```javascript
const stripe = Stripe('pk_test_xxx');
const {error} = await stripe.confirmCardPayment(clientSecret, {
    payment_method: {
        card: cardElement,
    }
});
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/stripe`

Important: Set your webhook secret in `.env`.

---

## PayPal

### Configuration

```env
PAYPAL_CLIENT_ID=xxx
PAYPAL_CLIENT_SECRET=xxx
PAYPAL_MODE=sandbox
PAYPAL_ENABLED=true
```

### Supported Currencies
- USD, EUR, GBP, CAD, AUD
- 25+ currencies

### Features
- PayPal account payments
- Card payments (via PayPal)
- PayPal Credit
- Venmo

### Usage Example

```php
// Builder methods can be chained in any order
Payment::amount(100.00)
    ->currency('USD')
    ->email('customer@example.com')
    ->with('paypal') // or ->using('paypal')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/paypal`

Configure in PayPal Dashboard under Webhooks.

---

## Fallback Strategy

### Automatic Fallback

```php
// Try Paystack first, then Stripe if it fails
// with() or using() can be called anywhere in the chain
Payment::amount(10000)
    ->with(['paystack', 'stripe']) // or ->using(['paystack', 'stripe'])
    ->email('customer@example.com')
    ->redirect(); // Must be called last
```

### Global Fallback

Set in config:
```php
'default' => 'paystack',
'fallback' => 'stripe',
```

Then use:
```php
// Uses default and fallback from config
Payment::amount(10000)
    ->email('customer@example.com')
    ->redirect(); // Tries paystack, falls back to stripe
```

---

## Currency Support Matrix

| Provider    | NGN | USD | EUR | GBP | KES |       Other        |
|-------------|:---:|:---:|:---:|:---:|:---:|:------------------:|
| Paystack    |  ✅  |  ✅  |  ❌  |  ❌  |  ❌  |      GHS, ZAR      |
| Flutterwave |  ✅  |  ✅  |  ✅  |  ✅  |  ✅  | UGX, TZS, GHS, ZAR |
| Monnify     |  ✅  |  ❌  |  ❌  |  ❌  |  ❌  |         -          |
| Stripe      |  ✅  |  ✅  |  ✅  |  ✅  |  ❌  |        135+        |
| PayPal      |  ❌  |  ✅  |  ✅  |  ✅  |  ❌  |        25+         |
| Square      |  ❌  |  ✅  |  ❌  |  ✅  |  ❌  |      CAD, AUD       |

---

## Testing Credentials

### Paystack
- Secret: `sk_test_xxx` (from dashboard)
- Cards: See Paystack docs

### Flutterwave  
- Secret: `FLWSECK_TEST-xxx` (from dashboard)
- Cards: See Flutterwave docs

### Monnify
- Use sandbox credentials from dashboard

### Stripe
- Secret: `sk_test_xxx`
- Cards: `4242424242424242` (any CVV, future date)

### PayPal
- Use sandbox accounts
- Test buyer/seller from PayPal Developer Dashboard

### Square
- Access Token: Get from Square Dashboard → Applications → Your App
- Location ID: Get from Square Dashboard → Locations
- Use Square Sandbox for testing

---

## Square

### Configuration

```env
SQUARE_ACCESS_TOKEN=EAAAxxx
SQUARE_LOCATION_ID=location_xxx
SQUARE_WEBHOOK_SIGNATURE_KEY=xxx
SQUARE_ENABLED=true
```

### Supported Currencies
- USD (US Dollar)
- CAD (Canadian Dollar)
- GBP (British Pound)
- AUD (Australian Dollar)

### Features
- Online Checkout Payment Links
- Card payments
- Secure redirect-based checkout
- Payment link generation
- Order management integration

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
Payment::amount(100.00)
    ->currency('USD')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->with('square') // or ->using('square')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/square`

**Important:** Set your webhook signature key in `.env`:
```env
SQUARE_WEBHOOK_SIGNATURE_KEY=your_signature_key_here
```

Get the signature key from: Square Dashboard → Developers → Webhooks → Select endpoint → Signature Key

### Testing

- Use Square Sandbox credentials from your Square Developer Dashboard
- Test cards: See Square's testing documentation
- Access Token: Get from Square Dashboard → Applications → Your App → Credentials