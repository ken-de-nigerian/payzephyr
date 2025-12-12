# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A unified payment abstraction layer for Laravel that supports multiple payment providers with automatic fallback, webhooks, and comprehensive transaction logging. Built for production use with clean architecture and extensive testing.

---

## 🚀 Features

- **Multiple Payment Providers**: Paystack, Flutterwave, Monnify, Stripe, PayPal, Square, OPay
- **Automatic Fallback**: Seamlessly switch to back-up providers if primary fails
- **Fluent API**: Clean, expressive syntax for payment operations
- **Idempotency Support**: Prevent duplicate charges with unique keys across supported providers
- **Webhook Security**: Secure signature validation and replay attack prevention for all providers
- **Transaction Logging**: Automatic database logging with status tracking
- **Multi-Currency Support**: Support for provider-specific currencies (Stripe supports 135+ currencies)
- **Health Checks**: Automatic provider availability monitoring
- **Production Ready**: Comprehensive error handling and security features
- **Well Tested**: Full test coverage with Pest PHP (90%+ coverage)
- **Type Safe**: Strict PHP 8.2+ typing throughout

⚠️ **Provider-Specific:**
- Currency support varies by provider (see [Provider Details](docs/providers.md))
- Payment channels are mapped but not all providers support all channels
- Some providers have unique configuration requirements

---

## 📦 Installation

> **👋 New to PayZephyr?** Check out our **[Getting Started Guide](docs/GETTING_STARTED.md)** for a complete step-by-step tutorial!

### Requirements
- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- Composer

### Quick Install

```bash
# 1. Install the package
composer require kendenigerian/payzephyr

# 2. Run the install command (publishes config, migrations, and optionally runs migrations)
php artisan payzephyr:install

# 3. Configure your environment variables (see below)
```

**That's it!** You're ready to start accepting payments.

> **💡 Alternative:** If you prefer manual setup:
> ```bash
> php artisan vendor:publish --tag=payments-config
> php artisan vendor:publish --tag=payments-migrations
> php artisan migrate
> ```

---

## ⚙️ Configuration

Add your provider credentials to `.env`:

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

# Optional Security Settings
PAYMENTS_RATE_LIMIT_ENABLED=true
PAYMENTS_RATE_LIMIT_ATTEMPTS=10
PAYMENTS_WEBHOOK_TIMESTAMP_TOLERANCE=300
```

**📖 See [Configuration Guide](docs/DOCUMENTATION.md#configuration) for complete details.**

---

## 💳 Quick Start

### Basic Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

try {
    // Redirect user to the payment page
    return Payment::amount(10000)
        ->email('customer@example.com')
        ->callback(route('payment.callback'))
        ->redirect();
} catch (ChargeException $e) {
    // Handle payment initialization failure
    return back()->with('error', 'Payment initialization failed: ' . $e->getMessage());
}
```

### Using Helper Function

```php
try {
    return payment()
        ->amount(10000)
        ->email('customer@example.com')
        ->callback(route('payment.callback'))
        ->redirect();
} catch (\Exception $e) {
    return back()->with('error', $e->getMessage());
}
```

### With All Options

```php
use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

try {
    return Payment::amount(50000)
        ->currency('NGN')
        ->email('customer@example.com')
        ->reference('ORDER_' . time())
        ->description('Premium subscription')
        ->idempotency(Str::uuid()->toString())
        ->metadata(['order_id' => 12345])
        ->customer(['name' => 'John Doe', 'phone' => '+2348012345678'])
        ->channels(['card', 'bank_transfer'])
        ->with('paystack') // Specify provider (optional)
        ->redirect();
} catch (ProviderException $e) {
    // All providers failed
    Log::error('Payment failed across all providers', [
        'error' => $e->getMessage(),
        'exceptions' => $e->getContext()
    ]);
    return back()->with('error', 'Unable to process payment. Please try again later.');
}
```

### Verify Payment

```php
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        // verify() searches all providers if no provider specified
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            // Payment successful - update your database
            Order::where('payment_reference', $reference)
                ->update(['status' => 'paid']);
            
            return view('payment.success', [
                'amount' => $verification->amount,
                'reference' => $verification->reference,
            ]);
        }
        
        return view('payment.failed', [
            'message' => 'Payment was not successful'
        ]);
    } catch (VerificationException $e) {
        logger()->error('Payment verification failed', [
            'reference' => $reference,
            'error' => $e->getMessage(),
        ]);
        
        return view('payment.error');
    }
}
```

---

## 🔄 How It Works - Complete Payment Flow

Understanding the payment flow helps you integrate PayZephyr effectively:

### Step 1: Initialize Payment (Your Code)

```php
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

**What happens:**
1. You build a payment request with fluent methods
2. `redirect()` creates a checkout session with the provider
3. Customer is redirected to provider's secure payment page
4. Transaction is automatically logged to database

### Step 2: Customer Pays (Provider's Site)

- Customer completes payment on provider's secure page
- Provider processes the transaction
- Customer sees success/failure confirmation

### Step 3: Customer Returns (Callback)

```php
public function callback(Request $request)
{
    $reference = $request->input('reference');
    $verification = Payment::verify($reference);
    
    if ($verification->isSuccessful()) {
        // Update your order status
        Order::where('payment_reference', $reference)
            ->update(['status' => 'paid']);
    }
}
```

### Step 4: Webhook Notification (Async)

> ⚠️ **CRITICAL:** Webhooks are processed **asynchronously** via Laravel's queue system. You **MUST** run queue workers for webhooks to work:
>
> ```bash
> # Production (using supervisor)
> php artisan queue:work --queue=default --tries=3
> 
> # Development
> php artisan queue:listen
> ```
>
> Without queue workers, webhooks will be queued but never processed!

```php
// app/Listeners/HandlePaystackWebhook.php
public function handle(array $payload): void
{
    if ($payload['event'] === 'charge.success') {
        $reference = $payload['data']['reference'];
        
        // Update order status (idempotent - safe to run multiple times)
        Order::where('payment_reference', $reference)
            ->update(['status' => 'paid']);
    }
}
```

**Important:** Webhooks can arrive BEFORE or AFTER the customer returns to your callback URL. Always design your callback and webhook handlers to be **idempotent** (safe to run multiple times).

---

## 🔔 Webhooks

**⚠️ Important: Webhooks require queue workers. See [Queue Worker Setup](docs/webhooks.md#-queue-worker-setup-required)**

### Webhook URLs

Configure these in your provider dashboards:

- **Paystack**: `https://yourdomain.com/payments/webhook/paystack`
- **Flutterwave**: `https://yourdomain.com/payments/webhook/flutterwave`
- **Monnify**: `https://yourdomain.com/payments/webhook/monnify`
- **Stripe**: `https://yourdomain.com/payments/webhook/stripe`
- **PayPal**: `https://yourdomain.com/payments/webhook/paypal`
- **Square**: `https://yourdomain.com/payments/webhook/square`

### Listening to Events

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
    'payments.webhook' => [
        \App\Listeners\HandleAnyWebhook::class,
    ],
];
```

### Example Listener

```php
namespace App\Listeners;

class HandlePaystackWebhook
{
    public function handle(array $payload): void
    {
        $event = $payload['event'] ?? null;
        
        match($event) {
            'charge.success' => $this->handleSuccess($payload['data']),
            'charge.failed' => $this->handleFailure($payload['data']),
            default => logger()->info("Unhandled event: {$event}"),
        };
    }
    
    private function handleSuccess(array $data): void
    {
        $reference = $data['reference'];
        
        // Idempotent update (safe to run multiple times)
        DB::transaction(function () use ($reference) {
            $order = Order::where('payment_reference', $reference)
                ->lockForUpdate()
                ->first();
            
            if ($order && $order->status !== 'paid') {
                $order->update(['status' => 'paid', 'paid_at' => now()]);
                Mail::to($order->customer_email)->send(new OrderConfirmation($order));
            }
        });
    }
}
```

**📖 For complete webhook documentation, see [docs/webhooks.md](docs/webhooks.md)**

---

## 🏦 Supported Providers

| Provider        | Charge | Verify | Webhooks | Idempotency | Channels | Currencies                        |
|-----------------|:------:|:------:|:--------:|:-----------:|:--------:|-----------------------------------|
| **Paystack**    |   ✅    |   ✅    |    ✅     |      ✅      |    5     | NGN, GHS, ZAR, USD                |
| **Flutterwave** |   ✅    |   ✅    |    ✅     |      ✅      |   10+    | NGN, USD, EUR, GBP, KES, UGX, TZS |
| **Monnify**     |   ✅    |   ✅    |    ✅     |      ✅      |    4     | NGN                               |
| **Stripe**      |   ✅    |   ✅    |    ✅     |      ✅      |    6+    | 135+ currencies                   |
| **PayPal**      |   ✅    |   ✅    |    ✅     |      ❌      |    1     | USD, EUR, GBP, CAD, AUD           |
| **Square**      |   ✅    |   ✅    |    ✅     |      ✅      |    4     | USD, CAD, GBP, AUD                |
| **OPay**        |   ✅    |   ✅    |    ✅     |      ✅      |    5     | NGN                               |

**Notes:**
- ✅ = Fully supported
- ❌ = Not supported by provider
- **Channels**: Number of payment methods (card, bank transfer, USSD, etc.)
- **Idempotency**: Prevents duplicate charges with unique keys

**📖 For provider-specific details, see [docs/providers.md](docs/providers.md)**

---

## 🗄️ Transaction Logging

All transactions are automatically logged to the `payment_transactions` table:

```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

// Query transactions
$transactions = PaymentTransaction::where('email', 'user@example.com')
    ->successful()
    ->get();

// Check status
$transaction = PaymentTransaction::where('reference', 'ORDER_123')->first();

if ($transaction->isSuccessful()) {
    // Process order fulfillment
}

// Available scopes
PaymentTransaction::successful()->get();
PaymentTransaction::failed()->get();
PaymentTransaction::pending()->get();
```

---

## 🏢 Multi-Tenancy Support

PayZephyr automatically provides session isolation in multi-tenant applications:

### Automatic Isolation

When Laravel authentication is active, payment sessions are automatically isolated per user:

```php
// User 1's payment
Auth::loginUsingId(1);
Payment::amount(10000)->charge(); // Cached with user_1 prefix

// User 2's payment (completely isolated)
Auth::loginUsingId(2);
Payment::amount(20000)->charge(); // Cached with user_2 prefix
```

**Current Support:**
- ✅ User-based isolation (via Laravel auth)
- ✅ Session-based isolation
- ✅ IP-based rate limiting fallback

---

## 🧪 Testing

### Using Sandbox Credentials

All providers support sandbox/test modes:

```env
# Paystack Test Mode
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx

# Stripe Test Mode
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxx

# Monnify Sandbox
MONNIFY_BASE_URL=https://sandbox.monnify.com
```

### Testing in Your Application

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_payment_initialization()
    {
        $response = Payment::amount(10000)
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();
        
        $this->assertNotEmpty($response->reference);
        $this->assertNotEmpty($response->authorizationUrl);
        $this->assertEquals('pending', $response->status);
    }
    
    public function test_payment_verification()
    {
        // Use real test credentials to verify against sandbox
        $response = Payment::amount(10000)
            ->email('test@example.com')
            ->callback('https://example.com/callback')
            ->charge();
        
        // In real tests, you'd complete payment on provider's test page
        // For now, we just verify the structure
        $this->assertIsString($response->reference);
    }
}
```

### Testing Webhooks

```php
public function test_webhook_processing()
{
    // Mock webhook payload
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST_123',
            'amount' => 1000000, // 10,000 in kobo
            'status' => 'success',
        ],
    ];
    
    // Send webhook request
    $response = $this->postJson('/payments/webhook/paystack', $payload, [
        'x-paystack-signature' => $this->generateSignature($payload),
    ]);
    
    $response->assertStatus(202); // Queued
    
    // Process queue
    Queue::fake();
    $this->artisan('queue:work --once');
    
    // Assert transaction updated
    $this->assertDatabaseHas('payment_transactions', [
        'reference' => 'TEST_123',
        'status' => 'success',
    ]);
}
```

**📖 For complete testing guide, see [docs/DOCUMENTATION.md#testing](docs/DOCUMENTATION.md#testing)**

---

## 🔧 Advanced Usage

### Multiple Providers with Fallback

```php
// Try Paystack first, fallback to Stripe if it fails
return Payment::amount(10000)
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
$response = Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->with('stripe')
    ->charge(); // Returns ChargeResponseDTO

return response()->json([
    'reference' => $response->reference,
    'authorization_url' => $response->authorizationUrl,
    'status' => $response->status,
]);
```

**📖 For advanced patterns, see [docs/architecture.md](docs/architecture.md)**

---

## 🐛 Troubleshooting

### Common Issues

#### 1. Webhooks Not Processing

**Symptoms:** Webhooks arrive but transactions don't update

**Solution:**
```bash
# Make sure queue workers are running
php artisan queue:work

# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

#### 2. "Provider Not Found" Error

**Symptoms:** `DriverNotFoundException`

**Solution:**
```env
# Ensure provider is enabled
PAYSTACK_ENABLED=true

# Check credentials are set
PAYSTACK_SECRET_KEY=sk_test_xxxxx
```

#### 3. Health Check Failing

**Symptoms:** `getCachedHealthCheck()` returns false

**Solution:**
```php
// Bypass cache to check real status
$driver = app(PaymentManager::class)->driver('paystack');
$isHealthy = $driver->healthCheck(); // Direct check

// Clear health check cache
Cache::forget('payments.health.paystack');
```

#### 4. Rate Limit Exceeded

**Symptoms:** "Too many payment attempts" error

**Solution:**
```php
// Clear rate limit for testing
RateLimiter::clear('payment_charge:user_1');

// Or adjust limits in config
'rate_limit' => [
    'max_attempts' => 20, // Increase limit
    'decay_seconds' => 120, // Longer window
],
```

#### 5. Health Check Endpoint

PayZephyr provides a built-in health check endpoint to monitor provider availability:

**Endpoint:** `GET /payments/health`

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
      "currencies": ["USD", "EUR", "GBP", "CAD", "AUD"]
    },
    "flutterwave": {
      "healthy": false,
      "currencies": ["NGN", "USD", "EUR", "GBP"]
    }
  }
}
```

**Usage:**
- Monitor provider health in your application
- Set up uptime monitoring (e.g., UptimeRobot, Pingdom)
- Check provider availability before processing payments
- Health checks are cached (default: 5 minutes) to avoid excessive API calls

**Configuration:**
```env
# Adjust cache TTL (in seconds)
PAYMENTS_HEALTH_CHECK_CACHE_TTL=300
```

#### 6. Webhook Signature Validation Failing

**Symptoms:** Webhooks return 403 Unauthorized

**Solution:**
```env
# Ensure correct webhook secret
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx

# Not the same as API secret key!
# Get from provider dashboard → Webhooks → Signing Secret
```

### Debug Mode

Enable detailed logging:

```env
# Enable query logging
DB_LOG_QUERIES=true

# Increase log level
LOG_LEVEL=debug

# Check logs
storage/logs/laravel.log
```

### Still Having Issues?

1. Review [complete documentation](docs/DOCUMENTATION.md)
2. Search [GitHub issues](https://github.com/ken-de-nigerian/payzephyr/issues)
3. Open a [new issue](https://github.com/ken-de-nigerian/payzephyr/issues/new) with:
  - Laravel version
  - PHP version
  - PayZephyr version
  - Provider name
  - Error message and stack trace
  - Steps to reproduce

---

## 📚 Documentation

### Getting Started
- **[Getting Started Guide](docs/GETTING_STARTED.md)** ⭐ Start here if you're new!
- **[Complete Documentation](docs/DOCUMENTATION.md)** - Comprehensive guide
- **[Installation & Setup](README.md)** - You are here

### Core Documentation
- **[Architecture Guide](docs/architecture.md)** - System design
- **[API Reference](docs/API_REFERENCE.md)** - Complete API docs
- **[Provider Details](docs/providers.md)** - Provider-specific information
- **[Webhook Guide](docs/webhooks.md)** - Complete webhook documentation

### For Contributors
- **[Contributing Guide for Beginners](docs/CONTRIBUTING_GUIDE.md)** ⭐ New to open source?
- **[Contributing Guidelines](docs/CONTRIBUTING.md)** - Technical contribution guide

### Additional Resources
- **[CHANGELOG](docs/CHANGELOG.md)** - Version history
- **[LICENSE](LICENSE)** - MIT License

---

## 🤝 Contributing

Contributions are welcome! Please see:
- **[CONTRIBUTING_GUIDE.md](docs/CONTRIBUTING_GUIDE.md)** - Step-by-step guide for beginners
- **[CONTRIBUTING.md](docs/CONTRIBUTING.md)** - Technical guidelines

Key areas for contribution:
- Adding new payment providers
- Improving test coverage
- Enhancing documentation
- Reporting bugs
- Suggesting features

---

## 📝 Changelog

Please see [CHANGELOG.md](docs/CHANGELOG.md) for recent changes.

### Latest Release: v1.2.0

#### 🔒 Security Enhancements
- **CRITICAL:** SQL injection prevention in table name validation
- **CRITICAL:** Webhook replay attack prevention with timestamp validation (all drivers)
- **CRITICAL:** Multi-tenant cache isolation
- **HIGH:** Automatic log sanitization for sensitive data
- **HIGH:** Rate limiting for payment initialization
- Enhanced input validation (email, URL, reference format)

#### ✅ Added
- Security configuration section in config
- Comprehensive security test suite (85+ tests)
- Security guide documentation
- Enhanced webhook timestamp validation for all providers

#### 📚 Documentation
- Added comprehensive [Security Guide](docs/SECURITY.md)
- Updated all documentation with security best practices
- Enhanced troubleshooting section

See [CHANGELOG.md](docs/CHANGELOG.md) for complete details.

---

## 📄 License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

---

## 🌟 Show Your Support

If PayZephyr helped your project:
- ⭐ Star the repository on GitHub
- 🐦 Tweet about it
- 📝 Write a blog post
- 💰 Sponsor the project
- 🤝 Contribute code or documentation

---

**Built with ❤️ for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**