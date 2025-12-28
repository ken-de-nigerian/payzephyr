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
- **Enterprise subscriptions** - Enhanced subscription management with automatic transaction logging and lifecycle events
- **Transaction logging** - All subscription operations automatically logged to database with full audit trail
- **Idempotency support** - Prevent duplicate subscriptions with automatic UUID generation or custom keys
- **Lifecycle events** - Webhook events for subscription created, renewed, cancelled, and payment failed
- **Business validation** - Built-in validation prevents duplicate subscriptions and validates plan eligibility
- **State management** - Comprehensive subscription status enum with state machine logic and transition validation
- **Query builder** - Advanced subscription filtering with fluent interface for complex queries

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

// With more options (including idempotency for retry safety)
return Payment::amount(500.00) // ₦500.00
    ->currency('NGN')
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->reference('ORDER_123') // Your order ID
    ->description('Premium subscription')
    ->metadata(['order_id' => 12345]) // Custom data
    ->idempotency('unique-key-123') // Prevents duplicate charges on retries
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

## Subscriptions

**Currently, only PaystackDriver supports subscriptions.** Support for other providers will be added in future releases.

PayZephyr provides enterprise-grade subscription management with automatic transaction logging, idempotency support, lifecycle events, business validation, and comprehensive state management. All subscription operations are automatically logged to the database for audit and analytics.

### Quick Example

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

// Create a subscription plan (using facade)
$planDTO = new SubscriptionPlanDTO(
    name: 'Monthly Premium',
    amount: 5000.00,  // ₦5,000.00
    interval: 'monthly',
    currency: 'NGN',
);

$plan = Payment::subscription()
    ->planData($planDTO)
    ->with('paystack')  // Currently only PaystackDriver supports subscriptions
    ->createPlan();

// Create a subscription with idempotency (prevents duplicates on retries)
$subscription = payment()->subscription()
    ->customer('customer@example.com')
    ->plan($plan['plan_code'])
    ->idempotency() // Auto-generates UUID, or pass custom key
    ->with('paystack')
    ->subscribe();  // Final action method (create() is also available as an alias)

// Query subscriptions using query builder
$activeSubscriptions = Payment::subscriptions()
    ->forCustomer('customer@example.com')
    ->active()
    ->from('paystack')
    ->get();

// Subscription automatically logged to subscription_transactions table
// Access logged subscription:
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
$logged = SubscriptionTransaction::where('subscription_code', $subscription->subscriptionCode)->first();

// Save subscription details to your own table
DB::table('subscriptions')->insert([
    'subscription_code' => $subscription->subscriptionCode,
    'email_token' => $subscription->emailToken,  // Important for cancel/enable
    'status' => $subscription->status,
]);
```

**Key Features:**
- **Automatic transaction logging** - All subscriptions logged to database automatically with full audit trail
- **Idempotency support** - Prevent duplicate subscriptions with automatic UUID generation or custom keys
- **Lifecycle events** - Webhook events for subscription created, renewed, cancelled, and payment failed
- **Business validation** - Built-in validation prevents duplicates and validates plan eligibility
- **State management** - Comprehensive subscription status enum with state machine logic and transition validation
- **Query builder** - Advanced subscription filtering with fluent interface: `Payment::subscriptions()->forCustomer()->active()->get()`

**📖 See [Subscriptions Guide](docs/SUBSCRIPTIONS.md) for complete documentation**

**👨‍💻 Developers**: Want to add subscription support for a new driver? See the [Developer Guide](docs/SUBSCRIPTIONS.md#developer-guide-adding-subscription-support-to-a-driver).

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

**Latest: v1.8.0** - Major subscription enhancements with enterprise-grade features

**v1.8.0 Highlights**:
- **Subscription Transaction Logging** - Automatic logging of all subscription operations to database
- **Idempotency Support** - Prevent duplicate subscriptions with automatic UUID generation or custom keys
- **Lifecycle Events** - Comprehensive webhook events (SubscriptionCreated, SubscriptionRenewed, SubscriptionCancelled, SubscriptionPaymentFailed)
- **Business Validation** - Built-in validation prevents duplicate subscriptions and validates plan eligibility
- **State Management** - Subscription status enum with state machine logic and transition validation
- **Query Builder** - Advanced subscription filtering with fluent interface for complex queries
- **Lifecycle Hooks** - Optional interface for custom drivers to hook into subscription lifecycle events

See [Changelog](docs/CHANGELOG.md) for complete release notes.

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