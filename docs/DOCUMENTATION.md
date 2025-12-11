# PayZephyr - Complete Documentation

## Table of Contents

1. [Introduction](#introduction)
2. [Installation & Setup](#installation--setup)
3. [Configuration](#configuration)
4. [Basic Usage](#basic-usage)
5. [Advanced Usage](#advanced-usage)
6. [Payment Providers](#payment-providers)
7. [Webhooks](#webhooks)
8. [Transaction Logging](#transaction-logging)
9. [Error Handling](#error-handling)
10. [Security](#security)
11. [Testing](#testing)
12. [API Reference](#api-reference)
13. [Architecture](#architecture)
14. [Troubleshooting](#troubleshooting)

---

## Introduction

PayZephyr is a unified payment abstraction layer for Laravel
that supports multiple payment providers with automatic fallback,
webhooks, and comprehensive transaction logging.
It provides a clean, fluent API for processing payments across different providers without changing your code.

### Key Features

- **Multiple Payment Providers**: Paystack, Flutterwave, Monnify, Stripe, PayPal, and Square
- **Automatic Fallback**: Seamlessly switch to back up providers if primary fails
- **Fluent API**: Clean, expressive syntax for payment operations
- **Idempotency Support**: Prevent duplicate charges with unique keys
- **Webhook Security**: Secure signature validation for all providers
- **Transaction Logging**: Automatic database logging with status tracking
- **Multi-Currency Support**: Support for 100+ currencies across providers
- **Health Checks**: Automatic provider availability monitoring
- **Production Ready**: Comprehensive error handling and security features

---

## Installation & Setup

### Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- Composer

### Installation

```bash
composer require kendenigerian/payzephyr
```

### Run Install Command

```bash
php artisan payzephyr:install
```

This command automatically:
- Publishes the configuration file (`config/payments.php`)
- Publishes migration files
- Optionally runs migrations (you'll be prompted)

**Force overwrite existing files:**
```bash
php artisan payzephyr:install --force
```

> **💡 Alternative Manual Setup:** If you prefer to set up manually:
> ```bash
> php artisan vendor:publish --tag=payments-config
> php artisan vendor:publish --tag=payments-migrations
> php artisan migrate
> ```

This creates the `payment_transactions` table for automatic transaction logging.

---

## Configuration

### Required Environment Variables

Add your provider credentials to `.env`. Only configure the providers you plan to use:

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

# Optional Settings
PAYMENTS_DEFAULT_CURRENCY=NGN
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
```

**Note:** `base_url` is optional and defaults to production endpoints. Only set it if using custom endpoints.

### Callback URL Configuration

The callback URL determines where customers are redirected after completing payment. **It is required** when using the fluent API.

**Required:** You must call `->callback()` in your payment chain:
```php
Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))  // Required!
    ->redirect();
```

**Note:** The payment will fail with an `InvalidConfigurationException` if the callback URL is not provided.

---

## Basic Usage

### Simple Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

### With Options

```php
use Illuminate\Support\Str;

return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->description('Premium subscription')
    ->idempotency(Str::uuid()->toString())
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
// Note: Callback URL is still required for redirect-based providers (Stripe, Square, etc.)
$response = Payment::amount(10000)
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

return Payment::amount(10000)
    ->email('customer@example.com')
    ->reference($reference)
    ->redirect();
```

### Idempotency Keys

```php
use Illuminate\Support\Str;

// Prevent duplicate charges
$idempotencyKey = Str::uuid()->toString();

return Payment::amount(10000)
    ->email('customer@example.com')
    ->idempotency($idempotencyKey)
    ->redirect();
```

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
Payment::amount(10000)
    ->email('customer@example.com')
    ->channels(['card', 'bank_transfer'])  // Unified names
    ->redirect();
```

**Provider Mapping:**
- **Paystack**: `['card', 'bank_transfer']` → `['card', 'bank_transfer']`
- **Monnify**: `['card', 'bank_transfer']` → `['CARD', 'ACCOUNT_TRANSFER']`
- **Flutterwave**: `['card', 'bank_transfer']` → `'card,banktransfer'` (comma-separated)
- **Stripe**: `['card']` → `['card']`
- **PayPal**: Channels are ignored (PayPal doesn't support channel filtering)

If no channels are specified, each provider uses its default payment methods.

---

## Payment Providers

### Supported Providers

| Provider        | Charge | Verify | Webhooks | Currencies                        | Special Features                |
|-----------------|:------:|:------:|:--------:|-----------------------------------|---------------------------------|
| **Paystack**    |   ✅    |   ✅    |    ✅     | NGN, GHS, ZAR, USD                | USSD, Bank Transfer             |
| **Flutterwave** |   ✅    |   ✅    |    ✅     | NGN, USD, EUR, GBP, KES, UGX, TZS | Mobile Money, MPESA             |
| **Monnify**     |   ✅    |   ✅    |    ✅     | NGN                               | Bank Transfer, Dynamic Accounts |
| **Stripe**      |   ✅    |   ✅    |    ✅     | 135+ currencies                   | Apple Pay, Google Pay, SCA      |
| **PayPal**      |   ✅    |   ✅    |    ✅     | USD, EUR, GBP, CAD, AUD           | PayPal Balance, Credit          |

### Provider-Specific Configuration

Each provider has specific configuration requirements. See the [Provider Details](providers.md) documentation for complete information.

---

## Webhooks

**⚠️ Important: Webhooks are processed asynchronously via Laravel's queue system. You must run queue workers for webhooks to be processed. See [Queue Worker Setup](webhooks.md#-queue-worker-setup-required) for details.**

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

use App\Models\Order;

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

For complete webhook documentation, see [docs/webhooks.md](webhooks.md).

---

## Transaction Logging

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

### Transaction Model Properties

- `reference`: Unique transaction ID
- `provider`: Payment provider used
- `status`: Payment status (success, failed, pending)
- `amount`: Payment amount
- `currency`: Currency code
- `email`: Customer email
- `channel`: Payment method used
- `metadata`: Custom metadata
- `customer`: Customer information
- `paid_at`: Payment completion timestamp

---

## Error Handling

### Exception Types

- `ChargeException`: Payment initialization failed
- `VerificationException`: Payment verification failed
- `ProviderException`: All providers failed
- `DriverNotFoundException`: Provider not found or disabled
- `InvalidConfigurationException`: Configuration error

### Error Handling Example

```php
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

try {
    return Payment::amount(10000)
        ->email('customer@example.com')
        ->redirect();
} catch (ChargeException $e) {
    // Payment initialization failed
    logger()->error('Payment charge failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    return back()->with('error', 'Payment initialization failed. Please try again.');
} catch (ProviderException $e) {
    // All providers failed
    logger()->error('All payment providers failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    return back()->with('error', 'Payment service is temporarily unavailable.');
}
```

---

## Security

### Best Practices

1. ✅ Always use HTTPS for webhook URLs
2. ✅ Enable signature verification in production
3. ✅ Rotate API keys periodically
4. ✅ Use environment variables for credentials
5. ✅ Monitor failed webhooks for attacks
6. ✅ Implement rate limiting on webhooks
7. ✅ Keep the package updated

### Webhook Security

Webhooks are automatically validated using provider-specific signature verification. Never disable signature verification in production.

### API Key Security

Never commit API keys to version control. Always use environment variables and `.env` files (which should be in `.gitignore`).

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
    $response = Payment::amount(10000)
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

---

## API Reference

For complete API documentation, see **[API Reference](API_REFERENCE.md)**.

The API Reference includes:
- Complete method signatures and parameters
- Return types and exceptions
- All classes, interfaces, and services
- Data Transfer Objects (DTOs)
- Enums and constants
- Examples and usage patterns

### Quick Reference

#### Payment Facade Methods

**Builder Methods** (chainable in any order):
- `amount()`, `currency()`, `email()`, `reference()`, `callback()`, `metadata()`, `idempotency()`, `description()`, `customer()`, `channels()`, `with()`, `using()`

**Action Methods** (must be called last):
- `charge()` - Returns ChargeResponseDTO
- `redirect()` - Redirects to payment page

**Verification Method** (standalone):
- `verify($reference, $provider)` - Returns VerificationResponseDTO

#### Response Objects

**ChargeResponseDTO:**
- `reference`, `authorizationUrl`, `accessCode`, `status`, `metadata`, `provider`
- Methods: `isSuccessful()`, `isPending()`

**VerificationResponseDTO:**
- `reference`, `status`, `amount`, `currency`, `paidAt`, `channel`, `customer`, `metadata`, `provider`
- Methods: `isSuccessful()`, `isFailed()`, `isPending()`

**📖 See [API Reference](API_REFERENCE.md) for complete documentation.**

---

## Architecture

For detailed architecture documentation, see **[Architecture Guide](architecture.md)**.

The architecture guide covers:
- System design and component relationships
- Data flow diagrams
- Design patterns used
- SOLID principles
- Extensibility and customization
- Performance considerations

**Key Components:**
- **Payment Facade** - Fluent API entry point
- **PaymentManager** - Coordinates drivers and fallback logic
- **Drivers** - Provider-specific implementations
- **Services** - StatusNormalizer, ChannelMapper, ProviderDetector, DriverFactory
- **DTOs** - Type-safe data objects
- **Contracts** - Interfaces for dependency injection

**📖 See [Architecture Guide](architecture.md) for complete details.**

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
│         └─ PayPalDriver                     │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│      External Payment APIs                   │
└──────────────────────────────────────────────┘
```

For detailed architecture, see [docs/architecture.md](architecture.md).

---

## Troubleshooting

### Common Issues

#### Payment Initialization Fails

1. Check provider credentials in `.env`
2. Verify provider is enabled in config
3. Check currency support
4. Review error logs

#### Webhook Not Received

1. Verify webhook URL is correct
2. Check webhook signature verification
3. Ensure webhook endpoint is accessible
4. Check provider dashboard for webhook status

#### Verification Fails

1. Ensure reference is correct
2. Check if provider supports verification
3. Verify transaction exists on provider
4. Review error logs

#### Fallback Not Working

1. Verify fallback provider is configured
2. Check provider health status
3. Ensure both providers support the currency
4. Review error logs

### Getting Help

- 📧 **Email**: ken.de.nigerian@payzephyr.dev
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💡 **Feature Requests**: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 📖 **Documentation**: [GitHub Wiki](https://github.com/ken-de-nigerian/payzephyr/wiki)

---

## License

The MIT License (MIT). Please see [LICENSE](../LICENSE) for more information.

---

**Built with ❤️ for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**
