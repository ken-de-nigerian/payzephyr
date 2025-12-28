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
- **Subscriptions** - Full subscription management (plans, subscriptions, lifecycle events)

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
Payment::amount(500.00)
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
- subscription.create
- subscription.disable
- subscription.enable
- subscription.not_renew
- invoice.payment_failed
- invoice.payment_success

### Subscription Support

PaystackDriver provides full subscription management:
- Create, update, and manage subscription plans
- Create and manage subscriptions
- Subscription lifecycle events (created, renewed, cancelled, payment failed)
- Automatic transaction logging
- Idempotency support
- Query builder for advanced filtering

See [Subscriptions Guide](SUBSCRIPTIONS.md) for complete documentation.

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
- Note: Flutterwave supports additional currencies - see [Flutterwave documentation](https://developer.flutterwave.com/docs/currencies) for complete list

### Features
- Card payments
- Mobile money (M-Pesa, MTN, etc.)
- Bank transfer
- USSD
- Multiple African currencies

### Usage Example

```php
// Builder methods can be chained in any order
Payment::amount(100.00)
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
- USD, EUR, GBP, CAD, AUD (commonly used)
- Stripe supports 135+ currencies total - see [Stripe's currency list](https://stripe.com/docs/currencies) for complete list

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
$response = Payment::amount(100.00)
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
- USD, EUR, GBP, CAD, AUD (commonly used)
- PayPal supports 25+ currencies total - see [PayPal's currency list](https://developer.paypal.com/docs/api/reference/currency-codes/) for complete list

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
Payment::amount(100.00)
    ->with(['paystack', 'stripe']) // or ->using(['paystack', 'stripe'])
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
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
Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect(); // Tries paystack, falls back to stripe
```

---

## Currency Support Matrix

| Provider    | NGN | USD | EUR | GBP | KES | Other Currencies                                                       |
|-------------|:---:|:---:|:---:|:---:|:---:|------------------------------------------------------------------------|
| Paystack    |  ✅  |  ✅  |  ❌  |  ❌  |  ❌  | GHS, ZAR                                                               |
| Flutterwave |  ✅  |  ✅  |  ✅  |  ✅  |  ✅  | UGX, TZS, GHS, ZAR                                                     |
| Monnify     |  ✅  |  ❌  |  ❌  |  ❌  |  ❌  | -                                                                      |
| Stripe      |  ❌  |  ✅  |  ✅  |  ✅  |  ❌  | 135+ currencies                                                        |
| PayPal      |  ❌  |  ✅  |  ✅  |  ✅  |  ❌  | CAD, AUD                                                               |
| Square      |  ❌  |  ✅  |  ❌  |  ✅  |  ❌  | CAD, AUD                                                               |
| OPay        |  ✅  |  ❌  |  ❌  |  ❌  |  ❌  | -                                                                      |
| Mollie      |  ❌  |  ✅  |  ✅  |  ✅  |  ❌  | CHF, SEK, NOK, DKK, PLN, CZK, HUF, 30+                                 |
| NOWPayments |  ✅  |  ✅  |  ✅  |  ✅  |  ❌  | BTC, ETH, USDT, USDC, BNB, ADA, DOT, MATIC, SOL, 100+ cryptocurrencies |

---

## Feature Comparison Matrix

| Provider    | Charge | Verify | Webhooks | Idempotency | Subscriptions | Channels | Currencies |
|-------------|:------:|:------:|:--------:|:-----------:|:-------------:|:--------:|:----------:|
| Paystack    |   ✅    |   ✅    |    ✅     |      ✅      |       ✅       |    5     |     4      |
| Flutterwave |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |   10+    |     7+     |
| Monnify     |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |    4     |     1      |
| Stripe      |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |    6+    |    135+    |
| PayPal      |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |    1     |     5+     |
| Square      |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |    4     |     4      |
| OPay        |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |    5     |     1      |
| Mollie      |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |   10+    |    30+     |
| NOWPayments |   ✅    |   ✅    |    ✅     |      ✅      |       ❌       |   100+   |    100+    |

**Legend:**
- ✅ = Fully supported
- ❌ = Not supported
- **Subscriptions**: Plan management, subscription creation, lifecycle management
- **Channels**: Number of payment methods (card, bank transfer, USSD, etc.)
- **Currencies**: Number of supported currencies

**Subscription Support Notes:**
- **Paystack**: Full subscription support including plans, subscriptions, lifecycle events, and webhooks
- **Other Providers**: Subscription support coming in future releases

---

## Subscription Support Details

### Paystack Subscription Features

PaystackDriver provides comprehensive subscription management:

#### Plan Management
- ✅ Create subscription plans
- ✅ Update plans
- ✅ Fetch plan details
- ✅ List all plans
- ✅ Plan validation

#### Subscription Management
- ✅ Create subscriptions
- ✅ Fetch subscription details
- ✅ Cancel subscriptions (requires email token)
- ✅ Enable cancelled subscriptions (requires email token)
- ✅ List subscriptions
- ✅ Filter by customer

#### Subscription Features
- ✅ Trial periods
- ✅ Custom start dates
- ✅ Quantity support (multi-seat subscriptions)
- ✅ Authorization code support (immediate activation)
- ✅ Metadata support
- ✅ Idempotency support

#### Subscription Webhooks
- ✅ `subscription.create` - New subscription created
- ✅ `subscription.disable` - Subscription cancelled
- ✅ `subscription.enable` - Subscription reactivated
- ✅ `subscription.not_renew` - Set to non-renewing
- ✅ `invoice.payment_failed` - Payment failed
- ✅ `invoice.payment_success` - Payment succeeded (renewal)

#### Paystack-Specific Requirements

**Email Token for Cancel/Enable:**
- Paystack requires an email token for cancel and enable operations
- Token is provided in the subscription creation response (`emailToken`)
- **Important**: Always save the `emailToken` immediately after subscription creation
- Token format: Minimum 10 characters

**Plan Code Format:**
- Paystack plan codes start with `PLN_` prefix
- Example: `PLN_abc123xyz`

**Subscription Intervals:**
- Supported intervals: `daily`, `weekly`, `monthly`, `annually`
- Must match Paystack's interval format exactly

**Plan Creation Parameters:**
- Required: `name`, `interval`, `amount`
- Optional: `currency`, `description`, `invoice_limit`, `send_invoices`, `send_sms`
- **Not Supported**: `metadata` (Paystack doesn't accept metadata in plan creation)

See [Subscriptions Guide](SUBSCRIPTIONS.md) for complete documentation.

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

### OPay
- Merchant ID, Public Key, Private Key (Secret Key): Get from OPay Business Dashboard → API Credentials
- **Note:** Secret Key is required for Status API authentication (HMAC-SHA512) and webhook validation
- Use OPay production environment for testing (sandbox may vary)

---

## OPay

### Configuration

```env
OPAY_MERCHANT_ID=your_merchant_id
OPAY_PUBLIC_KEY=your_public_key
OPAY_SECRET_KEY=your_secret_key
OPAY_BASE_URL=https://liveapi.opaycheckout.com
OPAY_ENABLED=true
```

**Note:**
- Test: `https://testapi.opaycheckout.com`
- Live: `https://liveapi.opaycheckout.com`

### Supported Currencies
- NGN (Nigerian Naira) only

### Features
- **Create Payment API**: Bearer token authentication using Public Key
- **Status API**: HMAC-SHA512 signature authentication using Private Key (Secret Key) and Merchant ID
- Card payments
- Bank transfer
- USSD payments
- Mobile money
- Secure redirect-based checkout
- Cashier URL generation

### Authentication

OPay uses different authentication methods for different endpoints:

**Create Payment API** (`/api/v1/international/cashier/create`):
- Header: `Authorization: Bearer {public_key}`
- Header: `MerchantId: {merchant_id}`

**Status API** (`/api/v1/international/cashier/status`):
- Header: `Authorization: Bearer {signature}` (HMAC-SHA512 signature)
- Header: `MerchantId: {merchant_id}`
- The signature is generated by signing the JSON payload using HMAC-SHA512 with your private key (secret_key) concatenated with your merchant ID

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
Payment::amount(100.00)
    ->currency('NGN')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->with('opay') // or ->using('opay')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/opay`

OPay uses HMAC SHA-256 for webhook signature validation. The signature is sent in the `x-opay-signature` header.

**Important:** Set your secret key in `.env` for webhook validation and status API authentication:
```env
OPAY_SECRET_KEY=your_secret_key
```

**Note:** The secret key is required for:
- Status API authentication (HMAC-SHA512 signature generation)
- Webhook signature validation (HMAC-SHA256)

### Testing

- Use OPay credentials from OPay Business Dashboard
- Get credentials from: OPay Business Dashboard → API Credentials
- Test cards: See OPay's testing documentation

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

---

## Mollie

### Configuration

```env
MOLLIE_API_KEY=test_xxx
MOLLIE_WEBHOOK_SECRET=4Js3DqVSKFMUvkbGzcvjuA5GcHG3MVBM
MOLLIE_ENABLED=true
```

**Note:** `MOLLIE_WEBHOOK_SECRET` is optional but recommended. If not provided, webhook validation falls back to API verification.

### Supported Currencies

- EUR (Euro)
- USD (US Dollar)
- GBP (British Pound)
- CHF (Swiss Franc)
- SEK (Swedish Krona)
- NOK (Norwegian Krone)
- DKK (Danish Krone)
- PLN (Polish Zloty)
- CZK (Czech Koruna)
- HUF (Hungarian Forint)
- And 30+ other currencies supported by Mollie

### Features

- Redirect-based checkout with hosted payment page
- Multiple payment methods (iDEAL, Credit Card, Bank Transfer, etc.)
- Recurring payments support
- Refunds support
- Multi-currency support
- Webhook signature validation (HMAC SHA-256)

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
Payment::amount(10.00)
    ->currency('EUR')
    ->email('customer@example.com')
    ->description('Order #123')
    ->callback(route('payment.callback'))
    ->with('mollie') // or ->using('mollie')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/mollie`

**Recommended:** Configure webhook secret for signature-based validation:
1. Go to Mollie Dashboard → Developers → Webhooks
2. Create a webhook and copy the webhook secret
3. Add webhook URL: `https://yourdomain.com/payments/webhook/mollie`
4. Set `MOLLIE_WEBHOOK_SECRET` in your `.env` file

**Fallback:** If `MOLLIE_WEBHOOK_SECRET` is not configured, PayZephyr automatically fetches payment details from the Mollie API to verify webhooks.

### Webhook Validation

Mollie supports two validation methods:

1. **Signature-based validation (recommended):** When `MOLLIE_WEBHOOK_SECRET` is configured, PayZephyr validates the `X-Mollie-Signature` header using HMAC SHA-256. This is the preferred method.

2. **API verification (fallback):** If webhook secret is not configured, PayZephyr fetches payment details from the Mollie API to verify the webhook is legitimate.

**Note:** `hook.ping` test events are automatically accepted after signature validation (if configured) or without API verification (if using fallback).

### Testing

- Use Mollie test API keys (starts with `test_`)
- Test payment methods are available in the Mollie test environment
- API Key: Get from Mollie Dashboard → Developers → API Keys
- Webhook Secret: Get from Mollie Dashboard → Developers → Webhooks → Your webhook → Secret

---

## NOWPayments

### Configuration

```env
NOWPAYMENTS_API_KEY=xxxxx
NOWPAYMENTS_IPN_SECRET=xxxxx
NOWPAYMENTS_BASE_URL=https://api.nowpayments.io
NOWPAYMENTS_ENABLED=true
```

### Supported Currencies

NOWPayments supports 100+ cryptocurrencies and major fiat currencies:

**Fiat Currencies:**
- USD (US Dollar)
- NGN (Nigerian Naira)
- EUR (Euro)
- GBP (British Pound)
- And more...

**Cryptocurrencies:**
- BTC (Bitcoin)
- ETH (Ethereum)
- USDT (Tether)
- USDC (USD Coin)
- BNB (Binance Coin)
- ADA (Cardano)
- DOT (Polkadot)
- MATIC (Polygon)
- SOL (Solana)
- And 100+ more cryptocurrencies

See [NOWPayments documentation](https://nowpayments.io/help/api) for the complete list of supported cryptocurrencies.

### Features

- Cryptocurrency payments support
- Instant Payment Notifications (IPN)
- Automatic currency conversion
- Redirect-based checkout (uses `/v1/invoice` endpoint - customer redirects to payment page)
- Multiple payment statuses (waiting, confirming, sending, finished, failed, etc.)
- Support for both fiat and cryptocurrency payments
- Payment verification via `/v1/payment/{payment_id}` endpoint

### Usage Example

```php
// Builder methods can be chained in any order
// redirect() must be called last to execute
// NOWPayments uses invoice endpoint for redirect-based payments
// Customer will be redirected to NOWPayments payment page to complete payment
Payment::amount(100.00)
    ->currency('USD')
    ->email('customer@example.com')
    ->description('Order #123')
    ->callback(route('payment.callback'))
    ->with('nowpayments') // or ->using('nowpayments')
    ->redirect(); // Must be called last
```

**Note:** NOWPayments uses the `/v1/invoice` endpoint for creating payments. This generates an invoice URL that customers follow to complete payment. After payment, customers are redirected back to your callback URL for verification.

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/nowpayments`

**Setup Instructions:**
1. Go to NOWPayments Dashboard → Settings → Payments → Instant payment notifications
2. Generate and save your IPN Secret key
3. Add webhook URL: `https://yourdomain.com/payments/webhook/nowpayments`
4. Set `NOWPAYMENTS_IPN_SECRET` in your `.env` file

### Webhook Validation

NOWPayments uses HMAC SHA-512 signature validation:
- Webhook signature header: `x-nowpayments-sig`
- Signature is computed using your IPN secret key and the request body
- PayZephyr automatically validates the signature for security

### Payment Statuses

NOWPayments payment statuses are automatically normalized:
- **Success:** `finished`, `confirmed`
- **Failed:** `failed`, `refunded`, `expired`
- **Pending:** `waiting`, `confirming`, `sending`, `partially_paid`

### Testing

- Use NOWPayments sandbox/test API keys for testing
- Request sandbox API key from [NOWPayments](https://nowpayments.io/help/api)
- API Key: Get from NOWPayments Dashboard → Settings → Payments → API keys
- IPN Secret: Get from NOWPayments Dashboard → Settings → Payments → Instant payment notifications