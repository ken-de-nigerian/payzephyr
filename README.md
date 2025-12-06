# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A unified payment abstraction layer for Laravel that supports multiple payment providers with automatic fallback, webhooks, and comprehensive transaction logging. Built for production use with clean architecture and extensive testing.

---

## 🚀 Features

- **Multiple Payment Providers**: Paystack, Flutterwave, Monnify, Stripe, and PayPal
- **Automatic Fallback**: Seamlessly switch to back-up providers if primary fails
- **Fluent API**: Clean, expressive syntax for payment operations
- **Idempotency Support**: Prevent duplicate charges with unique keys across supported providers
- **Webhook Security**: Secure signature validation for all providers
- **Transaction Logging**: Automatic database logging with status tracking
- **Multi-Currency Support**: Support for 100+ currencies across providers
- **Health Checks**: Automatic provider availability monitoring
- **Production Ready**: Comprehensive error handling and security features
- **Well Tested**: Full test coverage with Pest PHP
- **Type Safe**: Strict PHP 8.2+ typing throughout

---

## 📦 Installation

### Requirements
- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x

### Install via Composer

```bash
composer require kendenigerian/payzephyr
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=payments-config
```

This creates `config/payments.php` where you configure your payment providers.

### Publish & Run Migrations

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

This creates the `payment_transactions` table for automatic transaction logging.

---

## ⚙️ Configuration

### Environment Variables

Add your provider credentials to `.env`:

```env
# Default Payment Provider
PAYMENTS_DEFAULT_PROVIDER=paystack

# Fallback Provider
PAYMENTS_FALLBACK_PROVIDER=stripe

# Paystack Configuration
PAYSTACK_SECRET_KEY=your_paystack_secret_key_here
PAYSTACK_PUBLIC_KEY=your_paystack_public_key_here
PAYSTACK_MERCHANT_EMAIL=your_merchant_email@example.com
PAYSTACK_CALLBACK_URL=https://yourapp.com/payments/paystack/callback
PAYSTACK_WEBHOOK_URL=https://yourapp.com/payments/paystack/webhook
PAYSTACK_BASE_URL=https://api.paystack.co
PAYSTACK_ENABLED=true

# Flutterwave Configuration
FLUTTERWAVE_SECRET_KEY=your_flutterwave_secret_key_here
FLUTTERWAVE_PUBLIC_KEY=your_flutterwave_public_key_here
FLUTTERWAVE_ENCRYPTION_KEY=your_flutterwave_encryption_key_here
FLUTTERWAVE_CALLBACK_URL=https://yourapp.com/payments/flutterwave/callback
FLUTTERWAVE_WEBHOOK_URL=https://yourapp.com/payments/flutterwave/webhook
FLUTTERWAVE_BASE_URL=https://api.flutterwave.com/v3
FLUTTERWAVE_ENABLED=false

# Monnify Configuration
MONNIFY_API_KEY=your_monnify_api_key_here
MONNIFY_SECRET_KEY=your_monnify_secret_key_here
MONNIFY_CONTRACT_CODE=your_monnify_contract_code_here
MONNIFY_CALLBACK_URL=https://yourapp.com/payments/monnify/callback
MONNIFY_BASE_URL=https://api.monnify.com
MONNIFY_ENABLED=false

# Stripe Configuration
STRIPE_SECRET_KEY=your_stripe_secret_key_here
STRIPE_PUBLIC_KEY=your_stripe_public_key_here
STRIPE_WEBHOOK_SECRET=your_stripe_webhook_secret_here
STRIPE_CALLBACK_URL=https://yourapp.com/payments/stripe/callback
STRIPE_BASE_URL=https://api.stripe.com
STRIPE_ENABLED=false

# PayPal Configuration
PAYPAL_CLIENT_ID=your_paypal_client_id_here
PAYPAL_CLIENT_SECRET=your_paypal_client_secret_here
PAYPAL_MODE=sandbox
PAYPAL_CALLBACK_URL=https://yourapp.com/payments/paypal/callback
PAYPAL_BASE_URL=https://api-m.sandbox.paypal.com
PAYPAL_ENABLED=false

# Currency Configuration
PAYMENTS_DEFAULT_CURRENCY=NGN
PAYMENTS_CURRENCY_CACHE_TTL=3600

# Webhook Configuration
PAYMENTS_WEBHOOK_PATH=/payments/webhook
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true

# Health Check Configuration
PAYMENTS_HEALTH_CHECK_ENABLED=true
PAYMENTS_HEALTH_CHECK_CACHE_TTL=300
PAYMENTS_HEALTH_CHECK_TIMEOUT=5

# Logging Configuration
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_LOG_CHANNEL=stack

# Security Configuration
PAYMENTS_ENCRYPT_KEYS=true
PAYMENTS_RATE_LIMIT_ENABLED=true
PAYMENTS_RATE_LIMIT_ATTEMPTS=60
PAYMENTS_RATE_LIMIT_DECAY=1

# Testing Mode
PAYMENTS_TESTING_MODE=false
```

---

## 💳 Quick Start

### Basic Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Redirect user to the payment page
// Note: Builder methods can be chained in any order
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect(); // Must be called last to execute
```

### Using Helper Function

```php
// The payment() helper works exactly like the Payment facade
// All builder methods are chainable in any order
return payment()
    ->amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect(); // Must be called last to execute
```

### With All Options

```php
use Illuminate\Support\Str;

// Builder methods can be chained in any order
// with() or using() can be called anywhere in the chain
// charge() or redirect() must be called last
return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->description('Premium subscription')
    ->idempotency(Str::uuid()->toString()) // Prevent double billing
    ->metadata(['order_id' => 12345])
    ->customer(['name' => 'John Doe', 'phone' => '+2348012345678'])
    ->channels(['card', 'bank_transfer'])
    ->with('paystack') // or ->using('paystack')
    ->redirect(); // Must be called last to execute
```

### Verify Payment

```php
public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        // verify() is a standalone method, NOT chainable
        // It searches all providers if no provider is specified
        $verification = Payment::verify($reference);
        
        // Or specify a provider explicitly
        // $verification = Payment::verify($reference, 'paystack');
        
        if ($verification->isSuccessful()) {
            // Payment successful
            return view('payment.success', [
                'amount' => $verification->amount,
                'reference' => $verification->reference,
            ]);
        }
        
        return view('payment.failed');
    } catch (\Exception $e) {
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

Understanding how payments work in this package will help you use it effectively. Here's the step-by-step process:

### Step 1: Initialize Payment (Your Code)

```php
// In your controller
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

**What happens:**
1. You call `Payment::amount()` to start building a payment request
2. You chain methods to add details (email, currency, reference, etc.)
3. You call `redirect()` which:
   - Builds a `ChargeRequest` object with all your data
   - Sends it to `PaymentManager`
   - `PaymentManager` picks a payment provider (Paystack, Stripe, etc.)
   - The provider creates a payment and returns a checkout URL
   - Customer gets redirected to that URL

### Step 2: Customer Pays (On Provider's Site)

- Customer enters card details on Paystack/Stripe's secure checkout page
- Provider processes the payment
- Customer sees success/failure message

### Step 3: Customer Returns (Callback Route)

```php
// routes/web.php
Route::get('/payment/callback', [PaymentController::class, 'callback']);

// In your controller
public function callback(Request $request)
{
    $reference = $request->input('reference');
    $verification = Payment::verify($reference);
    
    if ($verification->isSuccessful()) {
        // Payment succeeded - update your database
        Order::where('payment_reference', $reference)
            ->update(['status' => 'paid']);
    }
}
```

**What happens:**
1. Provider redirects customer back to your `callback` URL
2. You get the payment reference from the URL
3. You call `Payment::verify()` to check payment status
4. `PaymentManager` searches all providers to find the payment
5. You update your database based on the result

### Step 4: Webhook Arrives (Automatic Notification)

**Important:** Webhooks can arrive BEFORE or AFTER the customer returns!

```php
// app/Listeners/HandlePaystackWebhook.php
public function handle(array $payload): void
{
    if ($payload['event'] === 'charge.success') {
        $reference = $payload['data']['reference'];
        
        // Update order status
        Order::where('payment_reference', $reference)
            ->update(['status' => 'paid']);
    }
}
```

**What happens:**
1. Provider sends a POST request to `/payments/webhook/paystack`
2. `WebhookController` receives it
3. Controller verifies the webhook signature (security check)
4. Controller updates the payment record in database
5. Controller fires Laravel events
6. Your event listeners handle the webhook
7. You update orders, send emails, etc.

### Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ 1. YOUR CODE: Initialize Payment                            │
│    Payment::amount(1000)->email('user@example.com')->redirect() │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. PAYMENT MANAGER: Choose Provider & Create Payment        │
│    - Checks which providers are enabled                     │
│    - Tries default provider (e.g., Paystack)                │
│    - If fails, tries fallback (e.g., Stripe)               │
│    - Creates payment on provider's API                      │
│    - Gets checkout URL                                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. CUSTOMER: Redirected to Provider's Checkout             │
│    - Customer enters card details                           │
│    - Provider processes payment                             │
│    - Payment succeeds or fails                              │
└──────────────────────┬──────────────────────────────────────┘
                       │
        ┌──────────────┴──────────────┐
        │                              │
        ▼                              ▼
┌──────────────────┐        ┌──────────────────┐
│ 4A. CALLBACK     │        │ 4B. WEBHOOK      │
│ Customer returns │        │ Provider sends   │
│ to your site     │        │ notification     │
└────────┬─────────┘        └────────┬─────────┘
         │                           │
         │                           │
         ▼                           ▼
┌──────────────────┐        ┌──────────────────┐
│ Verify payment   │        │ Update database  │
│ Update order     │        │ Fire events      │
│ Show success     │        │ Handle webhook   │
└──────────────────┘        └──────────────────┘
```

### Key Points to Remember

1. **Two Ways to Know Payment Status:**
   - **Callback:** Customer returns to your site (may not always happen)
   - **Webhook:** Provider sends notification (more reliable)

2. **Always Handle Both:**
   - Check if order is already paid (webhook might have updated it first)
   - Use idempotency checks to prevent processing twice

3. **Automatic Fallback:**
   - If Paystack fails, automatically tries Stripe
   - No code changes needed - just configure multiple providers

4. **Database Logging:**
   - All payments are automatically logged to `payment_transactions` table
   - You can query this table to see payment history

---

## 🔔 Webhooks

### Webhook URLs

Configure these in your provider dashboards:

- **Paystack**: `https://yourdomain.com/payments/webhook/paystack`
- **Flutterwave**: `https://yourdomain.com/payments/webhook/flutterwave`
- **Monnify**: `https://yourdomain.com/payments/webhook/monnify`
- **Stripe**: `https://yourdomain.com/payments/webhook/stripe`
- **PayPal**: `https://yourdomain.com/payments/webhook/paypal`

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
        
        $order = Order::where('payment_reference', $reference)->first();
        
        if ($order) {
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            Mail::to($order->customer_email)->send(new OrderConfirmation($order));
        }
    }
}
```

**📖 For complete webhook documentation, see [docs/webhooks.md](docs/webhooks.md)**

---

## 🏦 Supported Providers

| Provider        | Charge | Verify | Webhooks | Currencies                        | Special Features                |
|-----------------|:------:|:------:|:--------:|-----------------------------------|---------------------------------|
| **Paystack**    |   ✅    |   ✅    |    ✅     | NGN, GHS, ZAR, USD                | USSD, Bank Transfer             |
| **Flutterwave** |   ✅    |   ✅    |    ✅     | NGN, USD, EUR, GBP, KES, UGX, TZS | Mobile Money, MPESA             |
| **Monnify**     |   ✅    |   ✅    |    ✅     | NGN                               | Bank Transfer, Dynamic Accounts |
| **Stripe**      |   ✅    |   ✅    |    ✅     | 135+ currencies                   | Apple Pay, Google Pay, SCA      |
| **PayPal**      |   ✅    |   ✅    |    ✅     | USD, EUR, GBP, CAD, AUD           | PayPal Balance, Credit          |

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
    // Process order
}

// Available scopes
PaymentTransaction::successful()->get();
PaymentTransaction::failed()->get();
PaymentTransaction::pending()->get();
```

---

## 📚 Documentation

### Core Documentation
- **[Installation & Setup](README.md)** - You are here
- **[Architecture Guide](docs/architecture.md)** - System design and components
- **[Provider Details](docs/providers.md)** - Detailed provider information
- **[Webhook Guide](docs/webhooks.md)** - Complete webhook documentation

### Additional Resources
- **[CHANGELOG](CHANGELOG.md)** - Version history and updates
- **[CONTRIBUTING](CONTRIBUTING.md)** - Contribution guidelines
- **[SECURITY](SECURITY_AUDIT.md)** - Security audit and best practices
- **[LICENSE](LICENSE)** - MIT License

---

## 🔧 Advanced Usage

### Multiple Providers with Fallback

```php
// Try Paystack first, fallback to Stripe
// with() or using() can be called anywhere in the chain
return Payment::amount(10000)
    ->email('customer@example.com')
    ->with(['paystack', 'stripe']) // or ->using(['paystack', 'stripe'])
    ->redirect(); // Must be called last to execute
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
// Use charge() instead of redirect() to get the response object
$response = Payment::amount(10000)
    ->email('customer@example.com')
    ->with('stripe') // or ->using('stripe')
    ->charge(); // Must be called last to execute

return response()->json([
    'reference' => $response->reference,
    'authorization_url' => $response->authorizationUrl,
]);
```

**📖 For advanced patterns, see [docs/architecture.md](docs/architecture.md)**

---

## 🔐 Security

### Reporting Vulnerabilities

**Do NOT** create public GitHub issues for security vulnerabilities.

📧 Email security issues to: **ken.de.nigerian@gmail.com**

### Security Best Practices

1. ✅ Always use HTTPS for webhook URLs
2. ✅ Enable signature verification in production
3. ✅ Rotate API keys periodically
4. ✅ Use environment variables for credentials
5. ✅ Monitor failed webhooks for attacks
6. ✅ Implement rate limiting on webhooks
7. ✅ Keep the package updated

**📖 For the complete security guide, see [SECURITY_AUDIT.md](SECURITY_AUDIT.md)**

---

## 🧪 Testing

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
    // Builder methods can be chained in any order
    $response = Payment::amount(10000)
        ->email('test@example.com')
        ->with('paystack') // or ->using('paystack')
        ->charge(); // Must be called last

    expect($response->reference)->toBeString()
        ->and($response->status)->toBe('pending');
});

test('payment verification works', function () {
    // verify() is standalone, not chainable
    $verification = Payment::verify('ref_123');
    
    expect($verification->isSuccessful())->toBeBool();
});
```

---

## 🏗️ Architecture

PayZephyr follows clean architecture principles:

```
┌─────────────────────────────────────────────┐
│           Facades & Helpers                  │
│     (Payment::, payment())                   │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│          Payment (Fluent API)                │
│    Builds ChargeRequest & calls Manager      │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│         PaymentManager                       │
│   - Manages driver instances                 │
│   - Handles fallback logic                   │
│   - Logs transactions                        │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│           Drivers Layer                      │
│  AbstractDriver ← DriverInterface            │
│         ├─ PaystackDriver                    │
│         ├─ FlutterwaveDriver                 │
│         ├─ MonnifyDriver                     │
│         ├─ StripeDriver                      │
│         └─ PayPalDriver                      │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│      External Payment APIs                   │
└──────────────────────────────────────────────┘
```

**📖 For detailed architecture, see [docs/architecture.md](docs/architecture.md)**

---

## 📊 API Reference

### Payment Methods

#### Builder Methods (Chainable - Can be called in any order)

```php
Payment::amount(float $amount)           // Set payment amount
Payment::currency(string $currency)      // Set currency (default: NGN)
Payment::email(string $email)            // Set customer email (required)
Payment::reference(string $reference)    // Set custom reference
Payment::idempotency(string $key)        // Set unique idempotency key
Payment::callback(string $url)           // Set callback URL
Payment::metadata(array $metadata)       // Set custom metadata
Payment::description(string $description) // Set payment description
Payment::customer(array $customer)       // Set customer information
Payment::channels(array $channels)        // Set payment channels
Payment::with(string|array $providers)    // Set provider(s) for this transaction
Payment::using(string|array $providers)   // Alias for with()
```

**Note:** Builder methods can be chained in any order. They return the Payment instance for method chaining.

#### Action Methods (Must be called last)

```php
Payment::charge()                        // Returns ChargeResponse (no redirect)
Payment::redirect()                      // Redirects user to payment page
```

**Note:** `charge()` and `redirect()` must be called last in the chain to execute the payment. They compile all the builder data and process the transaction.

#### Verification Method (Standalone - NOT chainable)

```php
Payment::verify(string $reference, ?string $provider = null)  // Returns VerificationResponse
```

**Note:** `verify()` is a standalone method that cannot be chained. It searches all enabled providers if no provider is specified, or verifies with the specified provider.

### Response Objects

```php
// ChargeResponse
$response->reference          // Payment reference
$response->authorizationUrl   // URL to redirect user
$response->accessCode         // Access code
$response->status             // Payment status
$response->metadata           // Metadata array
$response->provider           // Provider name

// VerificationResponse
$verification->reference      // Payment reference
$verification->status         // Payment status
$verification->amount         // Amount paid
$verification->currency       // Currency
$verification->paidAt         // Payment timestamp
$verification->channel        // Payment channel
$verification->customer       // Customer info
$verification->isSuccessful() // Boolean
$verification->isFailed()     // Boolean
$verification->isPending()    // Boolean
```

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for:
- Code of Conduct
- Development setup
- Coding standards
- Testing guidelines
- Pull request process
- Adding new providers

---

## 📝 Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for recent changes.

### Latest Release: v1.0.1

**Security Updates:**
- ✅ Fixed critical webhook signature validation
- ✅ Enhanced input validation
- ✅ Added transaction logging

**New Features:**
- ✅ PaymentTransaction model with scopes
- ✅ Automatic database logging
- ✅ PayPal zero-decimal currency support

**Improvements:**
- ✅ Better floating-point handling
- ✅ Removed unused dependencies
- ✅ Comprehensive security audit

---

## 📄 License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

---

### Built With
- [Laravel](https://laravel.com) - The PHP Framework
- [Guzzle](https://docs.guzzlephp.org) - HTTP Client
- [Stripe PHP](https://github.com/stripe/stripe-php) - Stripe SDK
- [Pest PHP](https://pestphp.com) - Testing Framework

---

## 💬 Support & Community

### Get Help
- 📧 **Email**: ken.de.nigerian@gmail.com
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💡 **Feature Requests**: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 📖 **Documentation**: [GitHub Wiki](https://github.com/ken-de-nigerian/payzephyr/wiki)

### Stay Updated
- ⭐ Star the repository
- 👁️ Watch for releases
- 🔔 Subscribe to discussions

---

## 🌟 Show Your Support

If PayZephyr helped your project:
- ⭐ Star the repository on GitHub
- 🐦 Tweet about it
- 📝 Write a blog post
- 💰 Sponsor the project
- 🤝 Contribute code or documentation

---

## 🗺️ Roadmap

### Planned Features
- [ ] Support for more payment providers (Square, Razorpay)
- [ ] Subscription management
- [ ] Refund operations
- [ ] Multi-tenancy support
- [ ] Admin dashboard
- [ ] Payment analytics
- [ ] Recurring billing
- [ ] Split payments enhancements

---

**Built with ❤️ for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**

---

## Quick Links

| Resource         | Link                                                                              |
|------------------|-----------------------------------------------------------------------------------|
| 📦 Packagist     | [kendenigerian/payzephyr](https://packagist.org/packages/kendenigerian/payzephyr) |
| 🐙 GitHub        | [ken-de-nigerian/payzephyr](https://github.com/ken-de-nigerian/payzephyr)         |
| 📖 Documentation | [docs/](docs/INDEX.md)                                                            |
| 🔐 Security      | [SECURITY_AUDIT.md](SECURITY_AUDIT.md)                                            |
| 📝 Changelog     | [CHANGELOG.md](CHANGELOG.md)                                                      |
| 🤝 Contributing  | [CONTRIBUTING.md](CONTRIBUTING.md)                                                |
| ⚖️ License       | [LICENSE](LICENSE)                                                                |