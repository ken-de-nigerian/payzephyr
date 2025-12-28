# PayZephyr - Complete Documentation

**What is PayZephyr?** A Laravel package that lets you accept payments from multiple providers (Paystack, Stripe, PayPal, etc.) using one simple API. Write your payment code once, and PayZephyr handles the complexity of different payment providers.

**Key Benefits:**
- **One API for all providers** - Switch providers without changing code
- **Automatic fallback** - If one provider fails, automatically tries another
- **Built-in security** - Webhook validation, replay protection, data sanitization
- **Transaction logging** - All payments automatically saved to database

## Table of Contents

1. [Installation & Configuration](#installation--configuration)
2. [Basic Usage](#basic-usage)
3. [Webhooks & Events](#webhooks--events)
4. [Payment Providers](#payment-providers)
5. [Error Handling](#error-handling)
6. [Security](#security)
7. [Testing](#testing)
8. [Troubleshooting](#troubleshooting)

---

## Installation & Configuration

```bash
composer require kendenigerian/payzephyr
php artisan payzephyr:install
```

Add provider credentials to `.env`:

```env
# Default Provider
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe

# Paystack (Required: secret_key, public_key)
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true

# Stripe (Required: secret_key, public_key, webhook_secret)
STRIPE_SECRET_KEY=sk_test_xxxxx
STRIPE_PUBLIC_KEY=pk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx
STRIPE_ENABLED=false

# Flutterwave (Required: secret_key, public_key, encryption_key)
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST_xxxxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST_xxxxx
FLUTTERWAVE_ENCRYPTION_KEY=xxxxx
FLUTTERWAVE_ENABLED=false

# Monnify (Required: api_key, secret_key, contract_code)
MONNIFY_API_KEY=MK_TEST_xxxxx
MONNIFY_SECRET_KEY=xxxxx
MONNIFY_CONTRACT_CODE=xxxxx
MONNIFY_ENABLED=false

# PayPal (Required: client_id, client_secret)
PAYPAL_CLIENT_ID=xxxxx
PAYPAL_CLIENT_SECRET=xxxxx
PAYPAL_MODE=sandbox
PAYPAL_ENABLED=false

# Square (Required: access_token, location_id)
SQUARE_ACCESS_TOKEN=EAAAxxx
SQUARE_LOCATION_ID=location_xxx
SQUARE_WEBHOOK_SIGNATURE_KEY=xxx
SQUARE_ENABLED=false

# OPay (Required: merchant_id, public_key, secret_key for status API)
OPAY_MERCHANT_ID=your_merchant_id
OPAY_PUBLIC_KEY=your_public_key
OPAY_SECRET_KEY=your_secret_key  # Required for status API authentication and webhook validation
OPAY_BASE_URL=https://liveapi.opaycheckout.com
OPAY_ENABLED=false

# Mollie (Required: api_key; Optional: webhook_secret for signature validation)
MOLLIE_API_KEY=test_xxx
MOLLIE_WEBHOOK_SECRET=xxxxx
MOLLIE_ENABLED=false

# NOWPayments (Required: api_key, ipn_secret)
NOWPAYMENTS_API_KEY=xxxxx
NOWPAYMENTS_IPN_SECRET=xxxxx
NOWPAYMENTS_ENABLED=false

# Optional Settings
PAYMENTS_DEFAULT_CURRENCY=NGN
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true

# Subscription Configuration
PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES=false
PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED=true
PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE=subscription_transactions
PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED=true
PAYMENTS_SUBSCRIPTIONS_RETRY_ENABLED=false
PAYMENTS_SUBSCRIPTIONS_RETRY_MAX_ATTEMPTS=3
PAYMENTS_SUBSCRIPTIONS_RETRY_DELAY_HOURS=24
PAYMENTS_SUBSCRIPTIONS_GRACE_PERIOD=7
PAYMENTS_SUBSCRIPTIONS_NOTIFICATIONS_ENABLED=false

# Webhook Configuration
PAYMENTS_WEBHOOK_MAX_RETRIES=3              # Maximum webhook processing retries
PAYMENTS_WEBHOOK_RETRY_BACKOFF=60           # Seconds to wait before retry

# Cache Configuration
PAYMENTS_CACHE_SESSION_TTL=3600             # Cache session TTL in seconds (default: 1 hour)
```

Callback URL is required: `->callback(route('payment.callback'))`

---

## Basic Usage

**Note:** Amounts are in major currency units (e.g., `100.00` for ₦100.00). The package automatically converts to minor units (kobo/cents) internally.

### Simple Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// 100.00 = ₦100.00 (package handles conversion automatically)
return Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback')) // Required: where customer returns after payment
    ->redirect(); // Redirects customer to payment provider's page
```

### With Options

```php
return Payment::amount(500.00) // ₦500.00
    ->currency('NGN')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->reference('ORDER_123') // Your order ID
    ->description('Premium subscription')
    ->metadata(['order_id' => 12345]) // Custom data
    ->redirect();
```

### Verify Payment

```php
public function callback(Request $request)
{
    $verification = Payment::verify($request->input('reference'));
    
    if ($verification->isSuccessful()) {
        return view('payment.success', [
            'amount' => $verification->amount,
            'reference' => $verification->reference,
        ]);
    }
    
    return view('payment.failed');
}
```

---

## Advanced Usage

### Multiple Providers with Fallback

```php
return Payment::amount(100.00)
    ->email('customer@example.com')
    ->with(['paystack', 'stripe'])
    ->redirect();
```

### Direct Driver Access

```php
use KenDeNigerian\PayZephyr\PaymentManager;

$manager = app(PaymentManager::class);
$driver = $manager->driver('paystack');

// Check health
if ($driver->healthCheck()) {
    // Provider is available
}

// Check currency support
if ($driver->isCurrencySupported('NGN')) {
    // Currency supported
}
```

### API-Only Mode

```php
// Get payment details without redirecting
// Note: Callback URL is still required for redirect-based providers (Stripe, Square, etc.)
$response = Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))  // Required for redirect-based providers
    ->with('stripe')
    ->charge();

return response()->json([
    'reference' => $response->reference,
    'authorization_url' => $response->authorizationUrl,
]);
```

### Custom Reference Generation

```php
$reference = 'ORDER_' . auth()->id() . '_' . time();

return Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->reference($reference)
    ->redirect();
```

### Idempotency Keys

Idempotency keys are **automatically generated** for every payment request to prevent duplicate charges. You can optionally provide your own key:

```php
use Illuminate\Support\Str;

// Option 1: Let the package auto-generate (recommended)
return Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
// A UUID v4 idempotency key is automatically generated

// Option 2: Provide your own idempotency key
$idempotencyKey = Str::uuid()->toString();

return Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->idempotency($idempotencyKey)  // Optional: override auto-generated key
    ->redirect();
```

**Note:** If you don't provide an idempotency key, the package automatically generates a UUID v4 key for you. This ensures consistent key formatting across all providers and simplifies driver logic.

**Validation:** Custom idempotency keys are validated for format and length:
- Must contain only alphanumeric characters, dashes, and underscores
- Maximum length: 255 characters
- Invalid keys will throw an `InvalidArgumentException`

---

## Payment Channels

PayZephyr provides a unified channel abstraction that works consistently across all providers. Use these unified channel names:

- `'card'` - Credit/debit card payments
- `'bank_transfer'` - Bank transfer/direct debit
- `'ussd'` - USSD payments
- `'mobile_money'` - Mobile money (M-Pesa, MTN, etc.)
- `'qr_code'` - QR code payments

The package automatically maps these unified names to provider-specific formats:

```php
// Works across all providers
Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->channels(['card', 'bank_transfer'])  // Unified names
    ->redirect();
```

**Provider Mapping:**
- **Paystack**: `['card', 'bank_transfer']` → `['card', 'bank_transfer']`
- **Monnify**: `['card', 'bank_transfer']` → `['CARD', 'ACCOUNT_TRANSFER']`
- **Flutterwave**: `['card', 'bank_transfer']` → `'card,banktransfer'` (comma-separated)
- **Stripe**: `['card']` → `['card']`
- **PayPal**: Channels are ignored (PayPal doesn't support channel filtering)
- **Square**: Channels are ignored (Square uses default payment methods)
- **OPay**: Channels are mapped to OPay's payment method format
- **Mollie**: Channels are mapped to Mollie's payment method format

If no channels are specified, each provider uses its default payment methods.

---

## Payment Providers

### Supported Providers

| Provider    | Charge | Verify | Webhooks | Currencies                                                                                 | Features                        |
|-------------|:------:|:------:|:--------:|--------------------------------------------------------------------------------------------|---------------------------------|
| Paystack    |   ✅    |   ✅    |    ✅     | NGN, GHS, ZAR, USD                                                                         | USSD, Bank Transfer             |
| Flutterwave |   ✅    |   ✅    |    ✅     | NGN, USD, EUR, GBP, KES, UGX, TZS                                                          | Mobile Money, MPESA             |
| Monnify     |   ✅    |   ✅    |    ✅     | NGN                                                                                        | Bank Transfer, Dynamic Accounts |
| Stripe      |   ✅    |   ✅    |    ✅     | 135+ currencies                                                                            | Apple Pay, Google Pay, SCA      |
| PayPal      |   ✅    |   ✅    |    ✅     | USD, EUR, GBP, CAD, AUD                                                                    | PayPal Balance, Credit          |
| Square      |   ✅    |   ✅    |    ✅     | USD, CAD, GBP, AUD                                                                         | Online Checkout, Payment Links  |
| OPay        |   ✅    |   ✅    |    ✅     | NGN                                                                                        | Card, Bank Transfer, USSD       |
| Mollie      |   ✅    |   ✅    |    ✅     | EUR, USD, GBP, CHF, SEK, NOK, DKK, PLN, CZK, HUF, 30+                                      | iDEAL, Card, Bank Transfer      |
| NOWPayments |   ✅    |   ✅    |    ✅     | USD, NGN, EUR, GBP, BTC, ETH, USDT, USDC, BNB, ADA, DOT, MATIC, SOL, 100+ cryptocurrencies | Cryptocurrency payments         |

### Provider-Specific Configuration

Each provider has specific configuration requirements. See the [Provider Details](providers.md) documentation for complete information.

---

---

## Webhooks & Events

### Webhook Setup

Configure webhook URLs in provider dashboards:
- Paystack: `https://yourdomain.com/payments/webhook/paystack`
- Stripe: `https://yourdomain.com/payments/webhook/stripe`

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
];

// app/Listeners/HandlePaystackWebhook.php
public function handle(array $payload): void
{
    if ($payload['event'] === 'charge.success') {
        Order::where('payment_reference', $payload['data']['reference'])
            ->update(['status' => 'paid']);
    }
}
```

### Payment Events

```php
// PaymentInitiated, PaymentVerificationSuccess, PaymentVerificationFailed
Event::listen(PaymentVerificationSuccess::class, function ($event) {
    Order::where('reference', $event->reference)
        ->update(['status' => 'paid']);
});
```

---

## Transaction Logging

All payment and subscription transactions are automatically logged:

```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

// Payment transactions
PaymentTransaction::where('reference', 'ORDER_123')->first();
PaymentTransaction::successful()->get();
PaymentTransaction::failed()->get();

// Subscription transactions (automatically logged on create/update/cancel)
SubscriptionTransaction::where('subscription_code', 'SUB_xyz')->first();
SubscriptionTransaction::active()->get();
SubscriptionTransaction::forCustomer('user@example.com')->get();
SubscriptionTransaction::forPlan('PLN_abc123')->get();
```

See [Subscriptions Guide](SUBSCRIPTIONS.md#transaction-logging) for complete subscription logging documentation.

---

## Error Handling

```php
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

try {
    return Payment::amount(100.00)->redirect();
} catch (ChargeException $e) {
    return back()->with('error', 'Payment initialization failed');
} catch (ProviderException $e) {
    return back()->with('error', 'Payment service unavailable');
}
```

**Exception Types:** `ChargeException`, `VerificationException`, `ProviderException`, `DriverNotFoundException`, `InvalidConfigurationException`

---

## Monitoring & Health Checks

**Endpoint:** `GET /payments/health` - Monitor provider availability

**What it does:** Checks if payment providers are working and returns their health status and supported currencies.

**Response:**
```json
{
  "status": "operational",
  "providers": {
    "paystack": {
      "healthy": true,
      "currencies": ["NGN", "USD", "GHS", "ZAR"]
    },
    "stripe": {
      "healthy": true,
      "currencies": ["USD", "EUR", "GBP"]
    }
  }
}
```

**Usage:**

**Generate a token first:**
```bash
php artisan tinker
>>> Str::random(32)
=> "xK9mP2qR7vT4wY8zA1bC3dE5fG6hI0j"
```

**Add to `.env`:**
```env
PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS=xK9mP2qR7vT4wY8zA1bC3dE5fG6hI0j
```

**Then use it:**
```bash
# With Bearer token
curl -H "Authorization: Bearer xK9mP2qR7vT4wY8zA1bC3dE5fG6hI0j" https://yourdomain.com/payments/health

# Or query parameter
curl https://yourdomain.com/payments/health?token=xK9mP2qR7vT4wY8zA1bC3dE5fG6hI0j
```

**Programmatic Access:**

```php
// Get token from config (the one you generated and added to .env)
$token = config('payments.health_check.allowed_tokens')[0] ?? null;

// Check health endpoint
$response = Http::withToken($token)->get(url('/payments/health'));
$data = $response->json();

if ($data['providers']['paystack']['healthy']) {
    // Provider is available
}

// Check individual provider
$driver = app(PaymentManager::class)->driver('paystack');
$isHealthy = $driver->getCachedHealthCheck(); // Cached (recommended)
```

**Security:** Configure IP whitelisting and token authentication in production. See [Security Guide](SECURITY.md#3-health-endpoint-security) for details.

**Caching:** Health checks are cached (default: 5 minutes). Configure via `PAYMENTS_HEALTH_CHECK_CACHE_TTL`.

---

## Security

- Always use HTTPS for webhook URLs
- Enable signature verification in production
- Use environment variables for credentials
- Rotate API keys periodically
- Monitor failed webhooks

See [Security Guide](SECURITY.md) for details.

---

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Static analysis
composer analyse

# Format code
composer format
```

### Test Example

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

test('payment charge works', function () {
    $response = Payment::amount(100.00)
        ->email('test@example.com')
        ->with('paystack')
        ->charge();

    expect($response->reference)->toBeString()
        ->and($response->status)->toBe('pending');
});

test('payment verification works', function () {
    $verification = Payment::verify('ref_123');
    
    expect($verification->isSuccessful())->toBeBool();
});
```

### Testing Subscriptions

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

test('subscription plan creation works', function () {
    $planDTO = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly',
        currency: 'NGN'
    );
    
    $plan = Payment::subscription()
        ->planData($planDTO)
        ->with('paystack')
        ->createPlan();
    
    expect($plan)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class)
        ->and($plan->planCode)->toBeString()
        ->and($plan->name)->toBe('Test Plan');
});

test('subscription creation works', function () {
    $subscription = Payment::subscription()
        ->customer('test@example.com')
        ->plan('PLN_test123')
        ->with('paystack')
        ->subscribe();
    
    expect($subscription)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class)
        ->and($subscription->subscriptionCode)->toBeString()
        ->and($subscription->status)->toBeString();
});

test('subscription transaction logging works', function () {
    use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
    
    $subscription = Payment::subscription()
        ->customer('test@example.com')
        ->plan('PLN_test123')
        ->with('paystack')
        ->subscribe();
    
    // Verify transaction was logged
    $logged = SubscriptionTransaction::where('subscription_code', $subscription->subscriptionCode)->first();
    
    expect($logged)->not->toBeNull()
        ->and($logged->status)->toBe($subscription->status);
});

test('subscription webhook events fire', function () {
    Event::fake();
    
    // Simulate webhook payload
    $payload = [
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_test123',
            'status' => 'active',
            'customer' => ['email' => 'test@example.com'],
            'plan' => ['plan_code' => 'PLN_test', 'name' => 'Test Plan'],
        ],
    ];
    
    // Dispatch event (simulating webhook handler)
    \KenDeNigerian\PayZephyr\Events\SubscriptionCreated::dispatch(
        $payload['data']['subscription_code'],
        'paystack',
        $payload['data']
    );
    
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\SubscriptionCreated::class);
});
```

### Mocking Subscription Operations

```php
use Mockery;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;

test('subscription with mocked driver', function () {
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface::class);
    
    $mockDriver->shouldReceive('createPlan')
        ->once()
        ->andReturn(new PlanResponseDTO(
            planCode: 'PLN_test',
            name: 'Test Plan',
            amount: 1000.0,
            interval: 'monthly',
            currency: 'NGN'
        ));
    
    // Inject mock driver for testing
    // ... test implementation
});
```

---

## API Reference

**Payment Facade:**
- Builder: `amount()`, `email()`, `callback()`, `reference()`, `metadata()`, `with()`
- Actions: `charge()`, `redirect()`, `verify()`

**Subscription Facade:**
- Builder: `customer()`, `plan()`, `planData()`, `trialDays()`, `startDate()`, `quantity()`, `authorization()`, `idempotency()`, `token()`, `with()`
- Actions: `subscribe()`, `create()`, `createPlan()`, `get()`, `cancel()`, `enable()`, `list()`
- Query Builder: `Payment::subscriptions()` - `forCustomer()`, `forPlan()`, `active()`, `cancelled()`, `whereStatus()`, `createdAfter()`, `createdBefore()`, `from()`, `take()`, `page()`, `get()`, `first()`, `count()`, `exists()`

**Response Objects:**
- `ChargeResponseDTO`: `reference`, `authorizationUrl`, `status`, `isSuccessful()`
- `VerificationResponseDTO`: `reference`, `status`, `amount`, `isSuccessful()`, `authorizationCode`
- `PlanResponseDTO`: `planCode`, `name`, `amount`, `interval`, `currency`, `toArray()`, `jsonSerialize()`
- `SubscriptionResponseDTO`: `subscriptionCode`, `status`, `customer`, `plan`, `amount`, `currency`, `nextPaymentDate`, `emailToken`, `metadata`

**Models:**
- `PaymentTransaction`: Payment transaction logging
- `SubscriptionTransaction`: Subscription transaction logging with scopes (`active()`, `cancelled()`, `forCustomer()`, `forPlan()`)

**Events:**
- `SubscriptionCreated`: Fired when subscription is created
- `SubscriptionRenewed`: Fired when subscription renews
- `SubscriptionCancelled`: Fired when subscription is cancelled
- `SubscriptionPaymentFailed`: Fired when subscription payment fails

See [API Reference](API_REFERENCE.md) for complete documentation.

**Payment Facade:**
- Builder: `amount()`, `email()`, `callback()`, `reference()`, `metadata()`, `with()`
- Actions: `charge()`, `redirect()`, `verify()`

**Response Objects:**
- `ChargeResponseDTO`: `reference`, `authorizationUrl`, `status`, `isSuccessful()`
- `VerificationResponseDTO`: `reference`, `status`, `amount`, `isSuccessful()`

See [API Reference](API_REFERENCE.md) for complete documentation.

---

## Architecture

**Key Components:**
- Payment Facade → PaymentManager → Drivers → External APIs
- Services: StatusNormalizer, ChannelMapper, ProviderDetector
- DTOs: Type-safe data objects

See [Architecture Guide](architecture.md) for details.

---

## Troubleshooting

### Payment Issues

**Payment initialization fails:** Check credentials in `.env`, verify provider enabled, check currency support

**Webhook not received:** Verify webhook URL, check signature verification, ensure queue workers running

**Verification fails:** Check reference format, verify transaction exists on provider

**Fallback not working:** Verify fallback provider configured, check health status, ensure currency support

### Subscription Issues

**"Subscription not found" error:**
- **Cause**: Subscription code doesn't exist or incorrect format
- **Solution**: Verify subscription code format (Paystack: starts with `SUB_`), check if subscription exists in provider dashboard
- **Prevention**: Always save subscription codes immediately after creation

**"Unknown/Unexpected parameter: metadata" error:**
- **Cause**: Attempting to send metadata in plan creation (not supported by Paystack)
- **Solution**: Remove metadata from plan creation - metadata is only supported for subscriptions, not plans
- **Status**: Fixed in v1.8.0

**Duplicate subscription prevention:**
- **Cause**: `prevent_duplicates` enabled and customer already has active subscription
- **Solution**: Cancel existing subscription first, or disable `prevent_duplicates` in config
- **Configuration**: `PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES=false`

**Idempotency key conflicts:**
- **Cause**: Reusing same idempotency key for different operations
- **Solution**: Generate unique keys per operation (include user ID, plan code, timestamp)
- **Best Practice**: Use `->idempotency()` for automatic UUID generation

**Event listeners not firing:**
- **Cause**: Events not registered in `EventServiceProvider`, or webhook not configured
- **Solution**: Register events in `EventServiceProvider::$listen`, verify webhook URL in provider dashboard
- **Check**: Ensure queue workers are running for webhook processing

**Transaction logging not working:**
- **Cause**: Logging disabled in config, or table doesn't exist
- **Solution**: Enable `PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED=true`, run migrations
- **Verify**: Check `subscription_transactions` table exists

**State transition errors:**
- **Cause**: Attempting invalid state transition (e.g., cancelling already cancelled subscription)
- **Solution**: Check current status before operations, use `SubscriptionStatus::canTransitionTo()` to validate
- **Example**: Use `SubscriptionStatus::fromString($status)->canBeCancelled()` before cancelling

**Email token missing for cancel/enable:**
- **Cause**: Email token not saved after subscription creation
- **Solution**: Always save `$subscription->emailToken` immediately after creation
- **Critical**: Token is required for cancel/enable operations and cannot be retrieved later

**Need help?** [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)

---

## License

The MIT License (MIT). Please see [LICENSE](../LICENSE) for more information.

---

**Built for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**
