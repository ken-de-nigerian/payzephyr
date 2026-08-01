# Advanced Usage

This chapter covers patterns that go beyond the everyday `Payment::amount()->redirect()` flow, useful once you're comfortable with the basics and need finer control.

## Idempotency: preventing accidental double charges

If a customer double-clicks "Pay," or your frontend retries a failed request automatically, you risk creating two separate charges for what should be one payment. Idempotency keys solve this: attach one, and if PayZephyr sees the same key again, the provider recognizes it as a retry of the same request rather than a new one:

```php
Payment::amount(100.00)
    ->email('customer@example.com')
    ->idempotency('order-'.$order->id) // your own stable key
    ->redirect();
```

If you don't need to choose the key yourself, PayZephyr can generate one for you:

```php
Payment::amount(100.00)->email('customer@example.com')->idempotency()->redirect();
```

**Use a key derived from something stable in your own data** (an order ID, for instance) rather than something that changes on every request: the whole point is that retrying the *same* logical operation produces the *same* key.

## Direct driver access

Everything you've seen so far goes through the `Payment` fluent builder, which is the right layer for almost all application code. Occasionally you need to reach a driver directly: checking a provider's health outside of the built-in endpoint, or calling a driver method the fluent builder doesn't expose:

```php
use KenDeNigerian\PayZephyr\PaymentManager;

$driver = app(PaymentManager::class)->driver('paystack');

$driver->healthCheck();              // bool - can we currently reach Paystack?
$driver->getSupportedCurrencies();   // ['NGN', 'GHS', 'ZAR', 'USD']
$driver->getName();                  // 'paystack'
```

Every driver implements `DriverInterface`, so code written against `$driver` works the same way regardless of which specific provider it is, useful if you're writing a diagnostic tool or admin panel that needs to loop over all configured providers generically.

## Health checks, beyond the built-in endpoint

PayZephyr's `/payments/health` endpoint (see [Configuration](configuration.md#health-check) and [Security](security.md#health-endpoint)) is built on the same `healthCheck()` method you can call directly:

```php
use KenDeNigerian\PayZephyr\PaymentManager;

$manager = app(PaymentManager::class);

foreach (['paystack', 'stripe'] as $provider) {
    $driver = $manager->driver($provider);
    $healthy = $driver->getCachedHealthCheck(); // cached, respects health_check.cache_ttl
    // ...report $healthy however you like - a custom dashboard, a scheduled alert, etc.
}
```

`getCachedHealthCheck()` respects the same caching as the built-in endpoint (so calling it doesn't hammer the provider's API on every check); `healthCheck()` (no `getCached` prefix) always makes a fresh request if you specifically need an uncached result.

## Working with multiple currencies

`isCurrencySupported()` lets you validate a currency against a specific provider before attempting a charge, rather than letting the charge fail and having to parse an exception:

```php
$driver = app(\KenDeNigerian\PayZephyr\PaymentManager::class)->driver('paystack');

if (! $driver->isCurrencySupported('EUR')) {
    // fall back to a different provider, or reject the request with a clear message
}
```

## Reading transaction history directly

PayZephyr logs every charge and subscription automatically (see [Configuration](configuration.md#transaction-logging)). You can query these tables like any other Eloquent model, which is often simpler than re-deriving the same information from a provider's API:

```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

PaymentTransaction::where('reference', 'ORDER_123')->first();
PaymentTransaction::successful()->get();
PaymentTransaction::failed()->whereDate('created_at', today())->get();
```

```php
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

SubscriptionTransaction::active()->get();
SubscriptionTransaction::forCustomer('user@example.com')->get();
SubscriptionTransaction::forPlan('PLN_abc123')->get();
```

These are ordinary Eloquent models with query scopes; feel free to build on top of them (relationships to your own `User`/`Order` models, additional scopes, and so on) rather than treating them as read-only.

## Next steps

- [Custom Drivers](custom-drivers.md): if direct driver access isn't enough and you need to build your own
- [Architecture](architecture.md): how the pieces in this chapter fit into PayZephyr's overall design
