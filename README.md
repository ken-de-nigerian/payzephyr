# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A unified payment abstraction layer for Laravel that supports multiple payment providers with automatic fallback, webhooks, and comprehensive transaction logging. Built for production use with clean architecture and extensive testing.

---

## 🚀 Features

- **Multiple Payment Providers**: Paystack, Flutterwave, Monnify, Stripe, PayPal, Square etc.
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

⚠️ **Provider-Specific:**
- Currency support (check provider documentation)
- Payment channels (mapped but not all providers support all channels)
- Required configuration fields

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

# 2. Publish configuration
php artisan vendor:publish --tag=payments-config

# 3. Publish and run migrations
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

**That's it!** You're ready to start accepting payments.

**📖 Need more help?** See the [Getting Started Guide](docs/GETTING_STARTED.md) for detailed instructions with examples.

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

# Optional Settings
PAYMENTS_DEFAULT_CURRENCY=NGN
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
```

**📖 See [Configuration Guide](docs/DOCUMENTATION.md#configuration) for complete details.**

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
    ->channels(['card', 'bank_transfer']) // Unified channel names work across all providers
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

## 🔔 Webhooks

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
| **Square**      |   ✅    |   ✅    |    ✅     | USD, CAD, GBP, AUD                | Online Checkout, Card Payments  |

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

### Getting Started
- **[Getting Started Guide](docs/GETTING_STARTED.md)** ⭐ **Start here if you're new!** — Step-by-step beginner tutorial
- **[Complete Documentation](docs/DOCUMENTATION.md)** - Comprehensive guide covering all features
- **[Installation & Setup](README.md)** - You are here

### Core Documentation
- **[Architecture Guide](docs/architecture.md)** - System design and components
- **[Provider Details](docs/providers.md)** - Detailed provider information
- **[Webhook Guide](docs/webhooks.md)** - Complete webhook documentation

### For Contributors
- **[Contributing Guide for Beginners](docs/CONTRIBUTING_GUIDE.md)** ⭐ **New to open source?** — Step-by-step contribution tutorial
- **[Contributing Guidelines](docs/CONTRIBUTING.md)** - Detailed technical contribution guide
- **[Architecture Guide](docs/architecture.md)** - Understand the codebase structure

### Additional Resources
- **[CHANGELOG](docs/CHANGELOG.md)** - Version history and updates
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
Payment::charge()                        // Returns ChargeResponseDTO (no redirect)
Payment::redirect()                      // Redirects user to payment page
```

**Note:** `charge()` and `redirect()` must be called last in the chain to execute the payment. They compile all the builder data and process the transaction.

#### Verification Method (Standalone - NOT chainable)

```php
Payment::verify(string $reference, ?string $provider = null)  // Returns VerificationResponseDTO
```

**Note:** `verify()` is a standalone method that cannot be chained. It searches all enabled providers if no provider is specified, or verifies with the specified provider.

### Response Objects

```php
// ChargeResponseDTO
$response->reference          // Payment reference
$response->authorizationUrl   // URL to redirect user
$response->accessCode         // Access code
$response->status             // Payment status
$response->metadata           // Metadata array
$response->provider           // Provider name

// VerificationResponseDTO
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

Contributions are welcome! Please see [CONTRIBUTING.md](docs/CONTRIBUTING.md) for:
- Code of Conduct
- Development setup
- Coding standards
- Testing guidelines
- Pull request process
- Adding new providers

---

## 📝 Changelog

Please see [CHANGELOG.md](docs/CHANGELOG.md) for recent changes.

### Latest Release: v1.1.3

### Changed
- **Core Classes**: Marked all core classes as `final` for better OCP compliance
   - All driver classes (PayPalDriver, StripeDriver, SquareDriver, PaystackDriver, FlutterwaveDriver, MonnifyDriver)
   - Core service classes (PaymentManager, DriverFactory, StatusNormalizer, ProviderDetector, ChannelMapper)
   - Controller and model classes (WebhookController, PaymentTransaction, Payment, PaymentServiceProvider)
   - All exception classes
   - This prevents inheritance and enforces composition, improving code maintainability

### Fixed
- **Square Driver**: Updated API version and cleaned up logging
   - Updated Square API version from `2024-01-18` to `2024-10-18`
   - Removed debug logging added for troubleshooting 401 authentication errors
   - Cleaned up unnecessary logs while maintaining essential operational logging
   - Updated SquareDriverCoverageTest to reflect new API version

- **Tests**: Refactored all test files to work with final classes
   - Replaced partial mocks of final driver classes with real instances and HTTP client mocking via `setClient()` method
   - Updated PaymentManager tests to use real instances with reflection-based driver injection into internal cache
   - Replaced DriverFactory mocks with direct driver injection into PaymentManager
   - Fixed status normalizer expectations in WebhookControllerCoverageTest to match actual driver behavior
   - Updated PayPalDriverWebhookTest to properly mock StreamInterface for HTTP response bodies
   - All 659 tests now pass successfully (1,336 assertions)

### Technical Details
- Tests now use composition (injecting mocks via public setters/reflection) instead of inheritance
- PaymentManager tests inject mock drivers directly into the internal `$drivers` cache using reflection
- Driver tests mock HTTP clients instead of extending final driver classes
- Maintains full test coverage while respecting final class constraints (OCP compliance)
- Improved test isolation by using real instances where possible

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