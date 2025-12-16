# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**PayZephyr** is a Laravel package that makes accepting payments simple. Instead of writing different code for each payment provider (Paystack, Stripe, PayPal, etc.), you write code once and PayZephyr handles the rest.

**Features**
- **One API for all providers** - Switch between Paystack, Stripe, PayPal, and more without changing your code
- **Automatic fallback** - If one provider fails, PayZephyr automatically tries another
- **Built-in security** - Webhook signature validation, replay protection, and data sanitization
- **Production ready** - Comprehensive error handling, transaction logging, and extensive testing

See [Provider Details](docs/providers.md) for currency and channel support.

---

## Installation

New to PayZephyr? Check out our [Getting Started Guide](docs/GETTING_STARTED.md).

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

**Alternative:** Manual setup:
> ```bash
> php artisan vendor:publish --tag=payments-config
> php artisan vendor:publish --tag=payments-migrations
> php artisan migrate
> ```

---

## Configuration

Add provider credentials to `.env`:

```env
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true
```

See [Configuration Guide](docs/DOCUMENTATION.md#installation--configuration) for all options.

---

## Quick Start

**What happens:** Customer clicks "Pay" → Redirected to payment page → Returns to your site → You verify payment

### Initialize Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Basic payment (amount in major currency unit: 100.00 = ₦100.00 for NGN)
return Payment::amount(100.00)
    ->email('customer@example.com')
    ->callback(route('payment.callback')) // Where to return after payment
    ->redirect(); // Sends customer to payment page

// With more options
return Payment::amount(500.00) // ₦500.00
    ->currency('NGN')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->reference('ORDER_123') // Your order ID
    ->description('Premium subscription')
    ->metadata(['order_id' => 12345]) // Custom data
    ->with('paystack') // Optional: force specific provider
    ->redirect();
```

### Verify Payment

After customer returns from payment page:

```php
public function callback(Request $request)
{
    // Get payment reference from URL
    $verification = Payment::verify($request->input('reference'));
    
    if ($verification->isSuccessful()) {
        // Payment succeeded - update your database
        Order::where('payment_reference', $verification->reference)
            ->update(['status' => 'paid']);
        return view('payment.success');
    }
    
    // Payment failed
    return view('payment.failed');
}
```

---

## Webhooks

**Queue workers required.** Configure webhook URLs in provider dashboards:

- Paystack: `https://yourdomain.com/payments/webhook/paystack`
- Stripe: `https://yourdomain.com/payments/webhook/stripe`
- See [Webhook Guide](docs/webhooks.md) for all providers

### Setup

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

See [Webhook Guide](docs/webhooks.md) for complete setup.

---

## Supported Providers

| Provider    | Charge | Verify | Webhooks | Idempotency | Channels | Currencies                                            |
|-------------|:------:|:------:|:--------:|:-----------:|:--------:|-------------------------------------------------------|
| Paystack    |   ✅    |   ✅    |    ✅     |      ✅      |    5     | NGN, GHS, ZAR, USD                                    |
| Flutterwave |   ✅    |   ✅    |    ✅     |      ✅      |   10+    | NGN, USD, EUR, GBP, KES, UGX, TZS                     |
| Monnify     |   ✅    |   ✅    |    ✅     |      ✅      |    4     | NGN                                                   |
| Stripe      |   ✅    |   ✅    |    ✅     |      ✅      |    6+    | 135+ currencies                                       |
| PayPal      |   ✅    |   ✅    |    ✅     |      ✅      |    1     | USD, EUR, GBP, CAD, AUD                               |
| Square      |   ✅    |   ✅    |    ✅     |      ✅      |    4     | USD, CAD, GBP, AUD                                    |
| OPay        |   ✅    |   ✅    |    ✅     |      ✅      |    5     | NGN                                                   |
| Mollie      |   ✅    |   ✅    |    ✅     |      ✅      |   10+    | EUR, USD, GBP, CHF, SEK, NOK, DKK, PLN, CZK, HUF, 30+ |
| NOWPayments |   ✅    |   ✅    |    ✅     |      ✅      |   100+   | USD, NGN, EUR, GBP, BTC, ETH, USDT, USDC, BNB, ADA, DOT, MATIC, SOL, 100+ cryptocurrencies |

**Notes:**
- ✅ = Fully supported
- ❌ = Not supported
- **Channels**: Number of payment methods (card, bank transfer, USSD, etc.)
- **Idempotency**: Prevents duplicate charges with unique keys

**📖 For provider-specific details, see [docs/providers.md](docs/providers.md)**

---

## Transaction Logging

All transactions are automatically logged:

```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

PaymentTransaction::where('reference', 'ORDER_123')->first();
PaymentTransaction::successful()->get();
PaymentTransaction::failed()->get();
```

---

---

## Testing

Use sandbox credentials for testing:

```env
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
```

See [Testing Guide](docs/DOCUMENTATION.md#testing) for examples.

---

## Advanced Usage

```php
// Fallback providers
Payment::amount(100.00)
    ->with(['paystack', 'stripe'])
    ->redirect();

// API-only mode (no redirect)
$response = Payment::amount(100.00)->charge();
return response()->json($response);

// Direct driver access
$driver = app(PaymentManager::class)->driver('paystack');
$driver->healthCheck();
```

See [Architecture Guide](docs/architecture.md) for advanced patterns.

---

## Troubleshooting

**Webhooks not processing?** Ensure queue workers are running:
```bash
php artisan queue:work
```

**Provider not found?** Check `.env`:
```env
PAYSTACK_ENABLED=true
PAYSTACK_SECRET_KEY=sk_test_xxxxx
```

**Health check endpoint:** `GET /payments/health` - Monitor provider availability

See [Troubleshooting Guide](docs/DOCUMENTATION.md#troubleshooting) for more.

---

## Documentation

- **[Getting Started](docs/GETTING_STARTED.md)** - Step-by-step tutorial
- **[Complete Guide](docs/DOCUMENTATION.md)** - Full documentation
- **[Webhooks](docs/webhooks.md)** - Webhook setup
- **[Providers](docs/providers.md)** - Provider details
- **[Architecture](docs/architecture.md)** - System design

---

## Contributing

Contributions welcome! See [Contributing Guide](docs/CONTRIBUTING.md).

---

## Changelog

See [CHANGELOG.md](docs/CHANGELOG.md) for version history.

**Latest: v1.4.1** - Code review fixes: race condition protection, improved logging, cache optimization

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

---

## Support

If PayZephyr helped your project:
- Star the repository on GitHub
- Share it with others
- Contribute code or documentation

---

**Built for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**