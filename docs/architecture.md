# Architecture Guide

## Overview

This guide explains how PayZephyr is built internally. You don't need to understand this to use the package, but it's helpful if you want to:
- Customize the package
- Add new payment providers
- Debug issues
- Understand how everything works together

The package follows clean architecture principles with clear separation of concerns:

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
│   - Coordinates health checks                │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│           Drivers Layer                      │
│  AbstractDriver ← Implements DriverInterface │
│         ├─ PaystackDriver                    │
│         ├─ FlutterwaveDriver                 │
│         ├─ MonnifyDriver                     │
│         ├─ StripeDriver                      │
│         └─ PayPalDriver                      │
└──────────────┬──────────────────────────────┘
               │
┌──────────────▼──────────────────────────────┐
│      External Payment APIs                   │
│   (Paystack, Stripe, etc.)                   │
└──────────────────────────────────────────────┘
```

## Core Components

### 1. Contracts (Interfaces)

Think of interfaces as "contracts" that define what a class must do.

- **DriverInterface**: Every payment provider driver (PaystackDriver, StripeDriver, etc.) must implement this interface. It defines methods like `charge()`, `verify()`, and `validateWebhook()`.

### 2. Data Transfer Objects (DTOs)

DTOs are simple classes that hold data. They ensure all payment providers use the same data format.

- **ChargeRequest**: Holds payment request data (amount, email, currency, etc.)
- **ChargeResponse**: Holds the response after creating a payment (reference, checkout URL, etc.)
- **VerificationResponse**: Holds the result of checking a payment status

**Why DTOs?** Different providers have different API formats, but DTOs give us a consistent format to work with internally.

### 3. Drivers

Drivers are classes that talk to specific payment providers. Each provider has its own driver.

**AbstractDriver** (Base Class):
All drivers extend this class. It provides common functionality:
- HTTP client setup (for making API requests)
- Request/response handling
- Health checks (checking if provider is working)
- Logging
- Currency validation
- Reference generation

**Individual Drivers** (Each Provider Has One):
- **PaystackDriver**: Handles Paystack payments (Nigerian-focused)
- **FlutterwaveDriver**: Handles Flutterwave payments (African-focused)
- **MonnifyDriver**: Handles Monnify payments (Nigerian, uses OAuth2)
- **StripeDriver**: Handles Stripe payments (Global, uses official SDK)
- **PayPalDriver**: Handles PayPal payments (Global, uses REST API)

**How They Work:**
Each driver knows how to:
1. Format requests for that provider's API
2. Parse responses from that provider
3. Validate webhooks from that provider
4. Check if the provider is healthy/working
5. Extract data from webhook payloads (reference, status, channel)
6. Resolve the correct verification ID (some providers use reference, others use access codes/session IDs)

**Webhook Data Extraction:**
Each driver implements provider-specific methods for extracting data from webhooks:
- `extractWebhookReference()` - Gets the payment reference from the webhook
- `extractWebhookStatus()` - Gets the payment status (in provider-native format)
- `extractWebhookChannel()` - Gets the payment channel/method
- `resolveVerificationId()` - Determines which ID to use for verification

This follows the **Open/Closed Principle** - adding new providers doesn't require modifying core classes.

### 4. PaymentManager

The PaymentManager is the "brain" that coordinates everything:

**What it does:**
- Creates and caches driver instances (so we don't recreate them every time)
- Figures out which driver class to use based on config (e.g., 'paystack' → PaystackDriver)
- Manages fallback logic (if Paystack fails, try Stripe)
- Runs health checks before processing payments
- Handles errors gracefully (catches exceptions, tries next provider)

**Think of it as:** A traffic controller that routes payment requests to the right provider and handles failures.

### 5. Payment (Fluent API)

This is the main class you interact with. It provides a clean, chainable interface:

```php
Payment::amount(1000)
    ->currency('NGN')
    ->email('user@example.com')
    ->with('paystack')
    ->redirect();
```

**How it works:**
1. You call methods like `amount()`, `email()`, etc. to build up payment details
2. Each method returns the Payment instance, so you can chain them
3. When you call `redirect()` or `charge()`, it:
   - Builds a `ChargeRequest` object from all the chained data
   - Sends it to `PaymentManager` to process
   - Returns the result (redirect URL or response object)

**Builder Methods** (can be called in any order):
- `amount()`, `currency()`, `email()`, `reference()`, `metadata()`, etc.

**Action Methods** (must be called last):
- `redirect()` - Process payment and redirect customer
- `charge()` - Process payment and return response object

### 6. Service Provider

The `PaymentServiceProvider` is what connects this package to Laravel:

**What it registers:**
- PaymentManager as a singleton (only one instance exists)
- Payment class binding (so you can use the Payment facade)
- Config file (publishes `config/payments.php`)
- Migrations (creates `payment_transactions` table)
- Webhook routes (registers `/payments/webhook/{provider}` routes)

**When Laravel boots:** This service provider runs and sets everything up automatically.

## Data Flow - How Payments Are Processed

### Charge Flow (Creating a Payment)

Here's what happens step-by-step when you create a payment:

1. **You call the Payment API:**
   ```php
   Payment::amount(1000)->email('user@example.com')->redirect()
   ```

2. **Payment class builds a ChargeRequest:**
   - Takes all the data from your chained methods
   - Creates a `ChargeRequest` object with amount, email, currency, etc.

3. **PaymentManager receives the request:**
   - Gets the list of providers to try (from `with()` or config defaults)
   - Example: `['paystack', 'stripe']` means try Paystack first, then Stripe

4. **For each provider in the list:**
   - ✅ Check if provider is enabled in config
   - ✅ Run health check (is the provider's API working?)
   - ✅ Verify currency support (does Paystack support NGN?)
   - ✅ Try to create the payment
   - ✅ If successful: return the result
   - ✅ If failed: try the next provider

5. **Driver makes the API call:**
   - PaystackDriver formats the request for Paystack's API
   - Sends HTTP POST request to Paystack
   - Gets response back

6. **Driver returns ChargeResponse:**
   - Converts Paystack's response to our standard `ChargeResponse` format
   - Contains: reference, checkout URL, status, etc.

7. **Transaction is logged:**
   - Saves payment details to `payment_transactions` table
   - This happens automatically (if logging is enabled)

8. **User is redirected:**
   - If you called `redirect()`: customer goes to checkout page
   - If you called `charge()`: you get the response object to handle yourself

### Verification Flow (Checking Payment Status)

Here's what happens when you verify a payment:

1. **You call verify:**
   ```php
   Payment::verify($reference)  // Searches all providers
   // OR
   Payment::verify($reference, 'paystack')  // Check specific provider
   ```

2. **PaymentManager resolves verification context:**
   - Checks cache for the provider and ID associated with the reference
   - If cached: uses the driver's `resolveVerificationId()` to get the correct verification ID
   - If not cached: checks database for the transaction
   - If found in database: uses the driver's `resolveVerificationId()` to resolve the ID
   - Falls back to heuristics (reference format) if needed
   - This ensures the correct ID is used for verification (some providers use reference, others use access codes/session IDs)

3. **PaymentManager searches for the payment:**
   - If you specified a provider: only checks that one
   - If you didn't: checks ALL enabled providers
   - This is useful because you might not know which provider processed the payment (if fallback was used)

4. **For each provider:**
   - Gets the driver (e.g., PaystackDriver)
   - Uses the resolved verification ID (from step 2)
   - Calls `verify()` on the driver with the correct ID
   - Driver makes API call to check payment status
   - If found: returns the result
   - If not found: tries next provider

5. **Transaction is updated:**
   - Updates the `payment_transactions` record with latest status
   - Updates: status, payment method, timestamp, etc.

6. **VerificationResponse is returned:**
   - Contains: reference, status, amount, currency, payment date, etc.
   - You can check `$response->isSuccessful()` to see if payment succeeded

7. **Your app handles the result:**
   - Update order status
   - Send confirmation email
   - Process the order

### Webhook Flow (Receiving Payment Notifications)

Here's what happens when a payment provider sends a webhook:

1. **Provider sends webhook:**
   - Paystack sends POST request to `/payments/webhook/paystack`
   - Contains payment status, reference, amount, etc.

2. **WebhookController receives it:**
   - Laravel routes the request to `WebhookController@handle`
   - Controller gets the provider name from the URL (`paystack`)

3. **PaymentManager loads the driver:**
   - Gets PaystackDriver instance
   - Driver knows how to validate Paystack webhooks

4. **Driver validates signature:**
   - Checks the webhook signature to ensure it's really from Paystack
   - Prevents fake webhooks from hackers
   - Uses HMAC SHA512 with your secret key

5. **Controller extracts payment data (via Driver):**
   - Delegates to the driver to extract reference, status, and channel
   - Each driver implements `extractWebhookReference()`, `extractWebhookStatus()`, and `extractWebhookChannel()`
   - This follows the Open/Closed Principle - provider-specific logic is encapsulated in drivers

6. **Controller updates database:**
   - Updates `payment_transactions` record
   - Changes status from 'pending' to 'success' or 'failed'
   - Saves payment method, timestamp, etc.
   - Status is normalized using the StatusNormalizer service

7. **Controller fires Laravel events:**
   - `payments.webhook.paystack` (provider-specific event)
   - `payments.webhook` (generic event for all providers)
   - Your event listeners can react to these events

8. **Your listeners handle the webhook:**
   - Update order status
   - Send confirmation email
   - Process the order
   - Whatever you need!

9. **Controller returns 200 OK:**
   - Tells the provider "Got it, thanks!"
   - Provider won't retry the webhook

## Design Patterns

### 1. Abstract Factory Pattern
PaymentManager acts as factory for driver instances.

### 2. Strategy Pattern
Each driver is a strategy for processing payments.

### 3. Facade Pattern
Payment facade provides simplified interface.

### 4. DTO Pattern
Consistent data structures across providers.

### 5. Chain of Responsibility
Fallback mechanism tries providers in sequence.

## Error Handling

Exception hierarchy:
```
Exception
└── PaymentException (base)
    ├── DriverNotFoundException
    ├── InvalidConfigurationException
    ├── ChargeException
    ├── VerificationException
    ├── WebhookException
    ├── CurrencyException
    └── ProviderException (all providers failed)
```

Each exception can carry context via `setContext()`.

## Configuration

Configuration is hierarchical:
- Package defaults (config/payments.php)
- Environment variables (.env)
- Runtime overrides

## Security

- Webhook signatures verified by default
- API keys never exposed in logs
- HTTPS enforced (except testing mode)
- Rate limiting supported
- Input validation in DTOs

## Extensibility

### Adding New Providers

The package follows the **Open/Closed Principle (OCP)**, making it easy to add new providers without modifying existing code.

1. **Create driver class extending `AbstractDriver`**
2. **Implement `DriverInterface` methods:**
   - `charge()` - Create a payment
   - `verify()` - Verify payment status
   - `validateWebhook()` - Validate webhook signatures
   - `healthCheck()` - Check provider availability
   - `extractWebhookReference()` - Extract reference from webhook payload
   - `extractWebhookStatus()` - Extract status from webhook payload
   - `extractWebhookChannel()` - Extract payment channel from webhook payload
   - `resolveVerificationId()` - Resolve the ID needed for verification
3. **Add configuration to `config/payments.php`**
4. **That's it!** The system automatically uses your driver

**Example:**
```php
class SquareDriver extends AbstractDriver
{
    // ... implement required methods ...
    
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['data']['id'] ?? null;
    }
    
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['data']['status'] ?? 'unknown';
    }
    
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['payment_method'] ?? null;
    }
    
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        // Square uses the provider ID for verification
        return $providerId;
    }
}
```

**Benefits:**
- ✅ No need to modify `WebhookController` or `PaymentManager`
- ✅ Provider-specific logic is encapsulated in the driver
- ✅ Easy to test and maintain
- ✅ Follows SOLID principles

### Custom DTOs

DTOs can be extended for provider-specific features while maintaining compatibility.

## Testing Strategy

- Unit tests for each driver
- Integration tests for manager
- Feature tests for facade
- Mock external APIs
- Test fallback scenarios
- Test error conditions

## Performance Considerations

- Driver instances cached after creation
- Health checks cached (configurable TTL)
- Minimal dependencies
- Lazy loading of drivers
- Efficient HTTP client reuse
