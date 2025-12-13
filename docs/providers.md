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
| Square      |  ❌  |  ✅  |  ❌  |  ✅  |  ❌  |      CAD, AUD      |
| OPay        |  ✅  |  ❌  |  ❌  |  ❌  |  ❌  |         -          |

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
Payment::amount(10000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->with('opay') // or ->using('opay')
    ->redirect(); // Must be called last
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/opay`

OPay uses HMAC SHA256 for webhook signature validation. The signature is sent in the `x-opay-signature` header.

**Important:** Set your secret key in `.env` for webhook validation and status API authentication:
```env
OPAY_SECRET_KEY=your_secret_key
```

**Note:** The secret key (private key) is required for:
- Status API authentication (HMAC-SHA512 signature generation)
- Webhook signature validation

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
MOLLIE_WEBHOOK_URL=https://yourdomain.com
MOLLIE_ENABLED=true
```

### Supported Currencies

- EUR (Euro)
- USD (US Dollar)
- GBP (British Pound)
- And 30+ other currencies supported by Mollie

### Features

- Redirect-based checkout with hosted payment page
- Multiple payment methods (iDEAL, Credit Card, Bank Transfer, etc.)
- Recurring payments support
- Refunds support
- Multi-currency support

### Usage Example

```php
Payment::amount(10.00)
    ->currency('EUR')
    ->email('customer@example.com')
    ->description('Order #123')
    ->callback(route('payment.callback'))
    ->with('mollie')
    ->redirect();
```

### Webhook Configuration

URL: `https://yourdomain.com/payments/webhook/mollie`

**Important:** Mollie doesn't use signature-based webhook validation. Instead, PayZephyr automatically fetches payment details from the Mollie API when receiving webhooks to verify their authenticity.

Configure your webhook URL in your Mollie Dashboard:
1. Go to Developers → Webhooks
2. Add webhook URL: `https://yourdomain.com/payments/webhook/mollie`
3. Select payment events you want to receive

**Security Note:** For production, consider whitelisting Mollie's IP addresses for additional security.

### Testing

- Use Mollie test API keys (starts with `test_`)
- Test payment methods are available in the Mollie test environment
- API Key: Get from Mollie Dashboard → Developers → API Keys

### Webhook Validation

Unlike other providers, Mollie doesn't use HMAC signatures for webhook validation. PayZephyr handles this by:
1. Receiving the webhook with payment ID
2. Fetching payment details from Mollie API using the payment ID
3. Verifying the payment exists and matches the webhook data
4. Validating timestamp to prevent replay attacks

This ensures webhook authenticity without requiring signature validation.