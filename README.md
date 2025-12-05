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
# Default Provider
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe

# Paystack
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
PAYSTACK_ENABLED=true

# Flutterwave
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST-xxxxxxxxxxxxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST-xxxxxxxxxxxxx
FLUTTERWAVE_ENCRYPTION_KEY=FLWSECK_TESTxxxxxxxxxxxxx
FLUTTERWAVE_ENABLED=false

# Monnify
MONNIFY_API_KEY=MK_TEST_xxxxxxxxxxxxx
MONNIFY_SECRET_KEY=xxxxxxxxxxxxx
MONNIFY_CONTRACT_CODE=xxxxxxxxxxxxx
MONNIFY_ENABLED=false

# Stripe
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxx
STRIPE_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
STRIPE_ENABLED=false

# PayPal
PAYPAL_CLIENT_ID=xxxxxxxxxxxxx
PAYPAL_CLIENT_SECRET=xxxxxxxxxxxxx
PAYPAL_WEBHOOK_ID=YOUR_WEBHOOK_ID_HERE
PAYPAL_MODE=sandbox  # sandbox or live
PAYPAL_ENABLED=false

# Transaction Logging
PAYMENTS_LOGGING_ENABLED=true

# Webhook Configuration
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
```

---

## 💳 Quick Start

### Basic Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Redirect user to the payment page
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

### Using Helper Function

```php
return payment()
    ->amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

### With All Options

```php
use Illuminate\Support\Str;

return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->description('Premium subscription')
    ->idempotency(Str::uuid()->toString()) // Prevent double billing
    ->metadata(['order_id' => 12345])
    ->customer(['name' => 'John Doe', 'phone' => '+2348012345678'])
    ->channels(['card', 'bank_transfer'])
    ->with('paystack')
    ->redirect();

```

### Verify Payment

```php
public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        $verification = Payment::verify($reference);
        
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
    ->with('stripe')
    ->charge();

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
    $response = Payment::amount(10000)
        ->email('test@example.com')
        ->with('paystack')
        ->charge();

    expect($response->reference)->toBeString()
        ->and($response->status)->toBe('pending');
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

```php
// Builder methods (chainable)
Payment::amount(float $amount)
Payment::currency(string $currency)
Payment::email(string $email)
Payment::reference(string $reference)
Payment::idempotency(string $key)        // Set unique idempotency key
Payment::callback(string $url)
Payment::metadata(array $metadata)
Payment::description(string $description)
Payment::customer(array $customer)
Payment::channels(array $channels)
Payment::with(string|array $providers)

// Action methods
Payment::charge()                        // Returns ChargeResponse
Payment::redirect()                      // Redirects to payment page
Payment::verify(string $reference)       // Returns VerificationResponse
```

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

### Latest Release: v1.1.0

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

### In Progress
- [x] Transaction logging (v1.1.0)
- [x] Security enhancements (v1.1.0)
- [x] PayPal improvements (v1.1.0)

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