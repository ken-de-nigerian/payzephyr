# 🚀 Quick Reference Card

## Installation
```bash
composer require ken-de-nigerian/payzephyr
php artisan vendor:publish --tag=payments-config
php artisan migrate  # Optional, for transaction logging
```

## Configuration (.env)
```env
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYSTACK_SECRET_KEY=sk_live_xxx
PAYSTACK_PUBLIC_KEY=pk_live_xxx
```

## Basic Usage

### Simple Payment
```php
Payment::amount(10000)->email('user@example.com')->redirect();
```

### With Provider
```php
Payment::amount(10000)->with('paystack')->email('user@example.com')->redirect();
```

### With Fallback
```php
Payment::amount(10000)->with(['paystack', 'stripe'])->email('user@example.com')->redirect();
```

### Full Options
```php
Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_123')
    ->description('Premium subscription')
    ->metadata(['order_id' => 123])
    ->callback(route('payment.callback'))
    ->with('paystack')
    ->redirect();
```

### Verify Payment
```php
$result = Payment::verify($reference);

if ($result->isSuccessful()) {
    // Process order
}
```

## Webhook Setup

### Routes (Auto-registered)
```
POST /payments/webhook/paystack
POST /payments/webhook/flutterwave
POST /payments/webhook/monnify
POST /payments/webhook/stripe
POST /payments/webhook/paypal
```

### Event Listener
```php
// EventServiceProvider
protected $listen = [
    'payments.webhook.paystack' => [
        HandlePaystackWebhook::class,
    ],
];
```

### Listener Implementation
```php
class HandlePaystackWebhook
{
    public function handle(array $payload): void
    {
        if ($payload['event'] === 'charge.success') {
            $reference = $payload['data']['reference'];
            // Update order status
        }
    }
}
```

## Provider Configuration

### Paystack
```env
PAYSTACK_SECRET_KEY=sk_live_xxx
PAYSTACK_PUBLIC_KEY=pk_live_xxx
PAYSTACK_ENABLED=true
```

### Flutterwave
```env
FLUTTERWAVE_SECRET_KEY=FLWSECK-xxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK-xxx
FLUTTERWAVE_ENCRYPTION_KEY=xxx
FLUTTERWAVE_ENABLED=true
```

### Stripe
```env
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_PUBLIC_KEY=pk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_ENABLED=true
```

### PayPal
```env
PAYPAL_CLIENT_ID=xxx
PAYPAL_CLIENT_SECRET=xxx
PAYPAL_MODE=live
PAYPAL_ENABLED=true
```

### Monnify
```env
MONNIFY_API_KEY=xxx
MONNIFY_SECRET_KEY=xxx
MONNIFY_CONTRACT_CODE=xxx
MONNIFY_ENABLED=true
```

## Testing
```bash
composer test              # Run tests
composer test-coverage     # With coverage
composer format           # Fix code style
```

## Helper Function
```php
payment()->amount(10000)->email('user@example.com')->redirect();
```

## Error Handling
```php
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

try {
    $response = Payment::amount(10000)->charge();
} catch (PaymentException $e) {
    logger()->error($e->getMessage());
}
```

## Health Check
```php
$manager = app(PaymentManager::class);
$driver = $manager->driver('paystack');

if ($driver->healthCheck()) {
    // Provider is available
}
```

## Verification Response
```php
$result = Payment::verify($reference);

// Check status
$result->isSuccessful()  // true/false
$result->isFailed()      // true/false  
$result->isPending()     // true/false

// Get data
$result->reference       // Transaction reference
$result->amount         // Amount paid
$result->currency       // Currency code
$result->status         // Status string
$result->paidAt         // Payment timestamp
$result->provider       // Provider used
$result->channel        // Payment channel
```

## Common Commands
```bash
# Publish config
php artisan vendor:publish --tag=payments-config

# Publish migrations  
php artisan vendor:publish --tag=payments-migrations

# Run migrations
php artisan migrate

# Clear cache
php artisan cache:clear
```

## Documentation Links
- Full Documentation: README.md
- Architecture: docs/architecture.md
- Providers: docs/providers.md
- Webhooks: docs/webhooks.md
- Examples: examples/laravel-app/

## Support
- GitHub: https://github.com/ken-de-nigerian/payzephyr
- Issues: https://github.com/ken-de-nigerian/payzephyr/issues
- Email: ken.de.nigerian@gmail.com

## Quick Troubleshooting

**Payment fails immediately?**
- Check API keys in .env
- Verify provider is enabled
- Check health: `$driver->healthCheck()`

**Webhook not working?**
- Verify signature verification is enabled
- Check webhook URL in provider dashboard
- Ensure route is accessible
- Check logs: `storage/logs/laravel.log`

**Currency not supported?**
- Check provider's supported currencies
- See docs/providers.md for currency matrix

**All providers failing?**
- Check health checks
- Verify network connectivity
- Check API key validity
- Review logs

---

**Version:** 1.0.0  
**License:** MIT  
**Status:** Production Ready
