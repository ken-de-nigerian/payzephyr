# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ken-de-nigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/ken-de-nigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/ken-de-nigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/ken-de-nigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)

A unified payment abstraction layer for Laravel that supports multiple payment providers with automatic fallback, webhooks, and currency conversion. Built for production use with clean architecture and comprehensive testing.

## Features

- 🚀 **Multiple Payment Providers**: Paystack, Flutterwave, Monnify, Stripe, and PayPal
- 🔄 **Automatic Fallback**: Seamlessly switch to backup providers if primary fails
- 🎯 **Fluent API**: Clean, expressive syntax for payment operations
- 🔐 **Webhook Verification**: Secure signature validation for all providers
- 💱 **Currency Support**: Multi-currency with normalization
- 🏥 **Health Checks**: Automatic provider availability monitoring
- 📝 **Transaction Logging**: Optional database logging of all transactions
- ✅ **Production Ready**: Comprehensive error handling and logging
- 🧪 **Well Tested**: Full test coverage with Pest PHP
- 📚 **Well Documented**: Extensive documentation and examples

## Supported Providers

| Provider | Charge | Verify | Webhooks | Currencies |
|----------|:------:|:------:|:--------:|------------|
| Paystack | ✅ | ✅ | ✅ | NGN, GHS, ZAR, USD |
| Flutterwave | ✅ | ✅ | ✅ | NGN, USD, EUR, GBP, KES, UGX, TZS |
| Monnify | ✅ | ✅ | ✅ | NGN |
| Stripe | ✅ | ✅ | ✅ | USD, EUR, GBP, CAD, AUD |
| PayPal | ✅ | ✅ | ✅ | USD, EUR, GBP, CAD, AUD |

## Installation

You can install the package via Composer:

```bash
composer require ken-de-nigerian/payzephyr
```

### Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=payments-config
```

This will create `config/payments.php` where you can configure your payment providers.

### Publish Migrations (Optional)

If you want transaction logging, publish and run the migrations:

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

### Environment Configuration

Add your provider credentials to `.env`:

```env
# Default Provider
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe

# Paystack
PAYSTACK_SECRET_KEY=your_secret_key
PAYSTACK_PUBLIC_KEY=your_public_key
PAYSTACK_ENABLED=true

# Flutterwave
FLUTTERWAVE_SECRET_KEY=your_secret_key
FLUTTERWAVE_PUBLIC_KEY=your_public_key
FLUTTERWAVE_ENCRYPTION_KEY=your_encryption_key
FLUTTERWAVE_ENABLED=false

# Monnify
MONNIFY_API_KEY=your_api_key
MONNIFY_SECRET_KEY=your_secret_key
MONNIFY_CONTRACT_CODE=your_contract_code
MONNIFY_ENABLED=false

# Stripe
STRIPE_SECRET_KEY=your_secret_key
STRIPE_PUBLIC_KEY=your_public_key
STRIPE_WEBHOOK_SECRET=your_webhook_secret
STRIPE_ENABLED=false

# PayPal
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_MODE=sandbox
PAYPAL_ENABLED=false
```

## Usage

### Basic Payment Flow

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Using the default provider
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

### Specify a Provider

```php
// Use a specific provider
return Payment::amount(10000)
    ->email('customer@example.com')
    ->with('flutterwave')
    ->callback(route('payment.callback'))
    ->redirect();
```

### Multiple Providers with Fallback

```php
// Try Paystack first, fallback to Stripe if it fails
return Payment::amount(10000)
    ->email('customer@example.com')
    ->with(['paystack', 'stripe'])
    ->callback(route('payment.callback'))
    ->redirect();
```

### Full Example with All Options

```php
return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->description('Premium subscription')
    ->metadata([
        'order_id' => 12345,
        'plan' => 'premium',
    ])
    ->customer([
        'name' => 'John Doe',
        'phone' => '+2348012345678',
    ])
    ->channels(['card']) // Specific channels (Paystack/Monnify)
    ->callback(route('payment.callback'))
    ->with('paystack')
    ->redirect();
```

### API-Only Mode (No Redirect)

For providers like Stripe that use client-side confirmation:

```php
$response = Payment::amount(10000)
    ->email('customer@example.com')
    ->with('stripe')
    ->charge();

// Return client secret to frontend
return response()->json([
    'client_secret' => $response->metadata['client_secret'],
    'reference' => $response->reference,
]);
```

### Verify Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// In your callback controller
public function callback(Request $request)
{
    $reference = $request->reference;
    
    try {
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            // Payment successful
            // Update your database, send confirmation email, etc.
            
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

### Using Helper Function

```php
return payment()
    ->amount(10000)
    ->email('customer@example.com')
    ->redirect();
```

## Webhooks

Webhooks are automatically registered for all enabled providers at:

```
POST /payments/webhook/{provider}
```

### Webhook Events

The package dispatches Laravel events for webhooks:

```php
// Listen to specific provider webhooks
Event::listen('payments.webhook.paystack', function ($payload) {
    // Handle Paystack webhook
});

// Listen to all webhook events
Event::listen('payments.webhook', function ($provider, $payload) {
    // Handle any provider webhook
});
```

### Configure Webhook URLs

Set these URLs in your provider dashboards:

- **Paystack**: `https://yourdomain.com/payments/webhook/paystack`
- **Flutterwave**: `https://yourdomain.com/payments/webhook/flutterwave`
- **Monnify**: `https://yourdomain.com/payments/webhook/monnify`
- **Stripe**: `https://yourdomain.com/payments/webhook/stripe`
- **PayPal**: `https://yourdomain.com/payments/webhook/paypal`

### Webhook Signature Verification

Signatures are automatically verified. Disable if needed in config:

```php
'webhook' => [
    'verify_signature' => false, // Not recommended for production
],
```

## Health Checks

The package automatically checks provider health before attempting charges:

```php
use KenDeNigerian\PayZephyr\PaymentManager;

$manager = app(PaymentManager::class);
$driver = $manager->driver('paystack');

if ($driver->healthCheck()) {
    // Provider is available
}
```

Health checks are cached for 5 minutes by default. Configure in `config/payments.php`:

```php
'health_check' => [
    'enabled' => true,
    'cache_ttl' => 300, // 5 minutes
],
```

## Currency Conversion

The package supports multiple currencies. Amounts are automatically converted to the provider's minor units (cents, kobo, etc.):

```php
Payment::amount(100.50) // Automatically converts to 10050 minor units
    ->currency('NGN')
    ->email('customer@example.com')
    ->redirect();
```

## Transaction Logging

Enable automatic logging of all transactions:

```php
'logging' => [
    'enabled' => true,
    'table' => 'payment_transactions',
],
```

Transactions are logged with:
- Reference
- Provider
- Status
- Amount and currency
- Customer email
- Metadata
- Timestamps

## Error Handling

The package throws specific exceptions for different error types:

```php
use KenDeNigerian\PayZephyr\Exceptions\{
    PaymentException,
    ChargeException,
    VerificationException,
    ProviderException,
    WebhookException
};

try {
    $response = Payment::amount(10000)
        ->email('customer@example.com')
        ->charge();
} catch (ChargeException $e) {
    // Handle charge failure
} catch (ProviderException $e) {
    // All providers failed
} catch (PaymentException $e) {
    // General payment error
}
```

## Testing

Run tests with:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

The package includes comprehensive tests for:
- All payment drivers
- Webhook verification
- Fallback logic
- Currency handling
- Error scenarios

### Mocking in Tests

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Mock a successful payment
Payment::shouldReceive('charge')
    ->once()
    ->andReturn(new ChargeResponse(
        reference: 'TEST_REF',
        authorizationUrl: 'https://checkout.example.com',
        accessCode: 'code_123',
        status: 'pending',
    ));
```

## Advanced Usage

### Direct Driver Access

```php
use KenDeNigerian\PayZephyr\PaymentManager;

$manager = app(PaymentManager::class);
$driver = $manager->driver('paystack');

// Use driver directly
$response = $driver->charge($chargeRequest);
```

### Custom Providers

Implement `DriverInterface` to add custom providers:

```php
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;

class CustomDriver extends AbstractDriver implements DriverInterface
{
    protected string $name = 'custom';
    
    // Implement required methods...
}
```

Register in config:

```php
'providers' => [
    'custom' => [
        'driver' => \App\Payments\CustomDriver::class,
        'secret_key' => env('CUSTOM_SECRET'),
        'enabled' => true,
    ],
],
```

## Events

The package dispatches several events:

- `payments.webhook.{provider}` - Provider-specific webhook received
- `payments.webhook` - Any webhook received

Listen to events in your `EventServiceProvider`:

```php
protected $listen = [
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
];
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for details on recent changes.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email ken-de-nigerian@gmail.com instead of using the issue tracker.

## Credits

- [Nwaneri Chukwunyere Kenneth](https://github.com/ken-de-nigeriann)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

- 📧 Email: ken.de.nigerian@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 📖 Documentation: [Full Documentation](https://github.com/ken-de-nigerian/payzephyr/wiki)
