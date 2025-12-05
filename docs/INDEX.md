# PayZephyr Documentation

Welcome to the PayZephyr documentation! This guide will help you get started and make the most of the package.

---

## 📚 Table of Contents

### Getting Started
1. [Installation & Quick Start](../README.md)
2. [Configuration Guide](#configuration)
3. [Basic Usage Examples](#basic-usage)

### Core Documentation
1. [Architecture Overview](architecture.md) - System design and components
2. [Payment Providers](providers.md) - Detailed provider information
3. [Webhook Integration](webhooks.md) - Complete webhook guide

### Advanced Topics
1. [Transaction Logging](#transaction-logging)
2. [Error Handling](#error-handling)
3. [Security Best Practices](../SECURITY_AUDIT.md)
4. [Testing Your Integration](#testing)

### Development
1. [Contributing Guidelines](../CONTRIBUTING.md)
2. [Changelog](../CHANGELOG.md)
3. [API Reference](#api-reference)

---

## 🚀 Quick Links

### By Use Case

**I want to...**
- 💳 **Accept payments** → [Basic Usage](#basic-usage)
- 🔔 **Handle webhooks** → [Webhook Guide](webhooks.md)
- 🏦 **Add a new provider** → [Architecture](architecture.md) + [Contributing](../CONTRIBUTING.md)
- 🔐 **Secure my integration** → [Security Audit](../SECURITY_AUDIT.md)
- 🐛 **Debug issues** → [Error Handling](#error-handling)
- 📊 **Track transactions** → [Transaction Logging](#transaction-logging)

### By Provider

- **Paystack** → [Paystack Section](providers.md#paystack)
- **Flutterwave** → [Flutterwave Section](providers.md#flutterwave)
- **Monnify** → [Monnify Section](providers.md#monnify)
- **Stripe** → [Stripe Section](providers.md#stripe)
- **PayPal** → [PayPal Section](providers.md#paypal)

---

## Configuration

### Environment Setup

```env
# Core Settings
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe
PAYMENTS_LOGGING_ENABLED=true

# Paystack Configuration
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
PAYSTACK_ENABLED=true

# Stripe Configuration
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxx
STRIPE_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
STRIPE_ENABLED=true

# See providers.md for complete configuration options
```

### Configuration File

The main configuration is in `config/payments.php`:

```php
return [
    'default' => env('PAYMENTS_DEFAULT_PROVIDER', 'paystack'),
    'fallback' => env('PAYMENTS_FALLBACK_PROVIDER', 'stripe'),
    
    'providers' => [
        'paystack' => [/* ... */],
        'stripe' => [/* ... */],
        // ... more providers
    ],
    
    'webhook' => [
        'verify_signature' => true, // ALWAYS true in production
        'path' => '/payments/webhook',
    ],
    
    'logging' => [
        'enabled' => true,
        'table' => 'payment_transactions',
    ],
];
```

**📖 See [providers.md](providers.md) for detailed provider configuration**

---

## Basic Usage

### 1. Simple Payment

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect();
```

### 2. With Metadata

```php
return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->idempotency(Str::uuid()->toString()) // Prevent double billing
    ->metadata([
        'order_id' => 12345,
        'customer_id' => auth()->id(),
    ])
    ->description('Premium Plan Subscription')
    ->redirect();
```

### 3. Multiple Providers

```php
// Try Paystack, fallback to Stripe
return Payment::amount(10000)
    ->email('customer@example.com')
    ->with(['paystack', 'stripe'])
    ->redirect();
```

### 4. Verify Payment

```php
public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            // Update your database
            Order::where('reference', $reference)
                ->update(['status' => 'paid']);
            
            return view('payment.success');
        }
        
        return view('payment.failed');
        
    } catch (\Exception $e) {
        logger()->error('Verification failed', [
            'reference' => $reference,
            'error' => $e->getMessage(),
        ]);
        
        return view('payment.error');
    }
}
```

### 5. Using Helper Function

```php
// Same as Payment facade, more concise
return payment()
    ->amount(10000)
    ->email('customer@example.com')
    ->redirect();
```

**📖 See [Architecture Guide](architecture.md) for advanced patterns**

---

## Transaction Logging

All payments are automatically logged to the database when logging is enabled.

### Query Transactions

```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

// Get all successful payments
$successful = PaymentTransaction::successful()->get();

// Get failed payments
$failed = PaymentTransaction::failed()->get();

// Get pending payments
$pending = PaymentTransaction::pending()->get();

// Get by email
$userTransactions = PaymentTransaction::where('email', 'user@example.com')
    ->orderBy('created_at', 'desc')
    ->get();

// Get by reference
$transaction = PaymentTransaction::where('reference', 'ORDER_123')
    ->first();
```

### Check Transaction Status

```php
$transaction = PaymentTransaction::where('reference', $reference)->first();

if ($transaction->isSuccessful()) {
    // Process successful payment
}

if ($transaction->isFailed()) {
    // Handle failed payment
}

if ($transaction->isPending()) {
    // Payment still processing
}
```

### Transaction Model Properties

```php
$transaction->id            // Auto-increment ID
$transaction->reference     // Payment reference
$transaction->provider      // Provider name (paystack, stripe, etc.)
$transaction->status        // Status (success, failed, pending)
$transaction->amount        // Amount (decimal)
$transaction->currency      // Currency code (NGN, USD, etc.)
$transaction->email         // Customer email
$transaction->channel       // Payment channel (card, bank, etc.)
$transaction->metadata      // Custom metadata (array)
$transaction->customer      // Customer info (array)
$transaction->paid_at       // Payment timestamp
$transaction->created_at    // Created timestamp
$transaction->updated_at    // Updated timestamp
```

---

## Error Handling

### Exception Hierarchy

```php
Exception
└── PaymentException (base)
    ├── DriverNotFoundException
    ├── InvalidConfigurationException
    ├── ChargeException
    ├── VerificationException
    ├── WebhookException
    └── ProviderException
```

### Catching Specific Exceptions

```php
use KenDeNigerian\PayZephyr\Exceptions\{
    ChargeException,
    VerificationException,
    ProviderException
};

try {
    $response = Payment::amount(10000)
        ->email('customer@example.com')
        ->charge();
        
} catch (ChargeException $e) {
    // Handle charge failure
    logger()->error('Charge failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
} catch (ProviderException $e) {
    // All providers failed
    return back()->with('error', 'All payment providers are unavailable');
    
} catch (PaymentException $e) {
    // General payment error
    return back()->with('error', 'Payment processing failed');
}
```

### Exception Context

```php
try {
    Payment::verify($reference);
} catch (ProviderException $e) {
    // Get detailed error context
    $context = $e->getContext();
    
    // $context['exceptions'] contains errors from all providers
    foreach ($context['exceptions'] as $provider => $error) {
        logger()->error("Provider $provider failed: $error");
    }
}
```

---

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test file
vendor/bin/pest tests/Unit/PaystackDriverTest.php

# Static analysis
composer analyse

# Format code
composer format
```

### Writing Tests

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

### Mocking in Tests

```php
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponse;

Payment::shouldReceive('charge')
    ->once()
    ->andReturn(new ChargeResponse(
        reference: 'TEST_REF',
        authorizationUrl: 'https://checkout.test.com',
        accessCode: 'access_123',
        status: 'pending',
    ));
```

---

## API Reference

### Fluent API Methods

#### Builder Methods (Chainable)

```php
Payment::amount(float $amount)           // Set payment amount
Payment::currency(string $currency)      // Set currency (default: NGN)
Payment::email(string $email)            // Set customer email (required)
Payment::reference(string $reference)    // Set custom reference
Payment::callback(string $url)           // Set callback URL
Payment::metadata(array $metadata)       // Set custom metadata
Payment::description(string $description) // Set payment description
Payment::customer(array $customer)       // Set customer information
Payment::channels(array $channels)       // Set payment channels
Payment::with(string|array $providers)   // Set provider(s)
```

#### Action Methods

```php
Payment::charge()                        // Get ChargeResponse (no redirect)
Payment::redirect()                      // Redirect user to the payment page
Payment::verify(string $reference)       // Verify payment status
```

### Response Objects

#### ChargeResponse

```php
$response->reference          // string - Payment reference
$response->authorizationUrl   // string - URL to redirect user
$response->accessCode         // string - Access code
$response->status             // string - Payment status (pending, success, etc.)
$response->metadata           // array - Custom metadata
$response->provider           // string - Provider name

// Methods
$response->isSuccessful()     // bool
$response->isPending()        // bool
```

#### VerificationResponse

```php
$verification->reference      // string - Payment reference
$verification->status         // string - Payment status
$verification->amount         // float - Amount paid
$verification->currency       // string - Currency code
$verification->paidAt         // ?string - Payment timestamp
$verification->channel        // ?string - Payment channel
$verification->cardType       // ?string - Card type (if applicable)
$verification->bank           // ?string - Bank name (if applicable)
$verification->customer       // ?array - Customer information
$verification->metadata       // array - Custom metadata
$verification->provider       // string - Provider name

// Methods
$verification->isSuccessful() // bool - Payment succeeded
$verification->isFailed()     // bool - Payment failed
$verification->isPending()    // bool - Payment pending
```

---

## Troubleshooting

### Common Issues

#### 1. Webhook Not Received

**Symptoms:** Webhook endpoint not called by provider

**Solutions:**
- Ensure URL is accessible publicly (use ngrok for local testing)
- Verify HTTPS is enabled (most providers require it)
- Check provider dashboard for webhook delivery status
- Verify webhook URL is correctly configured
- Check server firewall settings

#### 2. Signature Validation Fails

**Symptoms:** Webhook returns 403 Forbidden

**Solutions:**
- Verify webhook secret is correct in `.env`
- Ensure `PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true`
- Check provider documentation for correct header name
- Confirm raw body is being used (not parsed JSON)

#### 3. Provider Not Found

**Symptoms:** `DriverNotFoundException`

**Solutions:**
- Verify provider is enabled in config
- Check provider name spelling
- Ensure credentials are set in `.env`
- Run `php artisan config:clear`

#### 4. Amount Mismatch

**Symptoms:** Wrong amount charged

**Solutions:**
- Ensure amount is in major units (100.00, not 10000)
- Check currency decimal places
- Verify `getAmountInMinorUnits()` is used correctly

#### 5. Transaction Not Logged

**Symptoms:** No records in `payment_transactions` table

**Solutions:**
- Run migrations: `php artisan migrate`
- Verify `PAYMENTS_LOGGING_ENABLED=true`
- Check database connection
- Review application logs for errors

### Debug Mode

Enable detailed logging:

```php
// config/logging.php
'channels' => [
    'payments' => [
        'driver' => 'single',
        'path' => storage_path('logs/payments.log'),
        'level' => 'debug',
    ],
],
```

---

## Best Practices

### 1. Security
- ✅ Always enable webhook signature verification in production
- ✅ Use HTTPS for all webhook URLs
- ✅ Rotate API keys periodically
- ✅ Never commit credentials to version control
- ✅ Use environment variables for all sensitive data

### 2. Error Handling
- ✅ Always wrap payment operations in try-catch blocks
- ✅ Log errors with context for debugging
- ✅ Show user-friendly error messages
- ✅ Implement retry logic for transient failures
- ✅ Monitor failed payments

### 3. Testing
- ✅ Test with sandbox/test credentials first
- ✅ Test all payment flows (success, failure, timeout)
- ✅ Test webhook handling
- ✅ Test with different currencies
- ✅ Test fallback mechanisms

### 4. Performance
- ✅ Enable health check caching
- ✅ Use queue workers for webhook processing
- ✅ Implement rate limiting
- ✅ Monitor provider response times
- ✅ Cache provider availability status

### 5. Monitoring
- ✅ Set up alerts for failed payments
- ✅ Monitor webhook delivery success rate
- ✅ Track provider uptime
- ✅ Review transaction logs regularly
- ✅ Set up exception monitoring (Sentry, Bugsnag)

---

## Support & Resources

### Getting Help

- 📧 **Email**: ken.de.nigerian@gmail.com
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💡 **Feature Requests**: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 📖 **Wiki**: [GitHub Wiki](https://github.com/ken-de-nigerian/payzephyr/wiki)

### Provider Documentation

- [Paystack Documentation](https://paystack.com/docs)
- [Flutterwave Documentation](https://developer.flutterwave.com/docs)
- [Monnify Documentation](https://docs.monnify.com)
- [Stripe Documentation](https://stripe.com/docs)
- [PayPal Documentation](https://developer.paypal.com/docs)

---

## Next Steps

1. ✅ [Install the package](../README.md)
2. ✅ [Configure your providers](providers.md)
3. ✅ [Implement basic payment flow](#basic-usage)
4. ✅ [Set up webhooks](webhooks.md)
5. ✅ [Test your integration](#testing)
6. ✅ [Review security guidelines](../SECURITY_AUDIT.md)
7. ✅ Deploy to production

---

**Happy Coding! 🚀**