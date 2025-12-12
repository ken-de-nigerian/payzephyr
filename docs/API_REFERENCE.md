# API Reference

Complete API documentation for PayZephyr package.

## Table of Contents

1. [Payment Facade](#payment-facade)
2. [PaymentManager](#paymentmanager)
3. [Drivers](#drivers)
4. [Data Transfer Objects](#data-transfer-objects)
5. [Services](#services)
6. [Contracts (Interfaces)](#contracts-interfaces)
7. [Models](#models)
8. [Enums](#enums)
9. [Exceptions](#exceptions)
10. [Events](#events)
11. [Jobs](#jobs)
12. [HTTP Endpoints](#http-endpoints)

---

## Payment Facade

The main entry point for payment operations.

### Namespace
```php
use KenDeNigerian\PayZephyr\Facades\Payment;
```

### Builder Methods (Chainable)

All builder methods can be called in any order and return the Payment instance for chaining.

#### `amount(float $amount): Payment`
Set the payment amount in major currency units (e.g., 100.00 for $100.00).

```php
Payment::amount(10000) // ₦100.00 or $100.00
```

#### `currency(string $currency): Payment`
Set the currency code (ISO 4217). Automatically converted to uppercase.

```php
Payment::currency('NGN')
Payment::currency('usd') // Automatically becomes 'USD'
```

#### `email(string $email): Payment`
Set the customer email address. Required for most providers.

```php
Payment::email('customer@example.com')
```

#### `reference(string $reference): Payment`
Set a custom transaction reference. If not provided, one will be auto-generated.

```php
Payment::reference('ORDER_12345')
```

#### `callback(string $url): Payment`
Set the callback URL where the customer will be redirected after payment. **Required** for `redirect()` and `charge()`.

```php
Payment::callback(route('payment.callback'))
Payment::callback('https://example.com/payment/callback')
```

#### `metadata(array $metadata): Payment`
Set custom metadata to be passed to the provider and stored in transaction logs.

```php
Payment::metadata([
    'order_id' => 12345,
    'customer_id' => auth()->id(),
    'subscription_id' => 'sub_123',
])
```

#### `idempotency(string $key): Payment`
Set an idempotency key to prevent duplicate charges. **Note:** If not provided, a UUID v4 key is automatically generated. This parameter is optional.

```php
Payment::idempotency(Str::uuid()->toString())
```

#### `description(string $description): Payment`
Set a description for the payment.

```php
Payment::description('Premium Plan Subscription')
```

#### `customer(array $customer): Payment`
Set customer information.

```php
Payment::customer([
    'name' => 'John Doe',
    'phone' => '+2348012345678',
    'address' => '123 Main St',
])
```

#### `channels(array $channels): Payment`
Set payment channels. Uses unified channel names that are automatically mapped to provider-specific formats.

```php
Payment::channels(['card', 'bank_transfer'])
// Unified channels: card, bank_transfer, ussd, mobile_money, qr_code
```

#### `with(string|array $providers): Payment`
Set the provider(s) to use for this transaction. If array, providers are tried in order (fallback).

```php
Payment::with('paystack')
Payment::with(['paystack', 'stripe']) // Try paystack first, then stripe
```

#### `using(string|array $providers): Payment`
Alias for `with()`. Same functionality.

```php
Payment::using('paystack')
Payment::using(['paystack', 'stripe'])
```

### Action Methods (Must be called last)

These methods execute the payment and must be called last in the chain.

#### `charge(): ChargeResponseDTO`
Process the payment and return the response object without redirecting.

```php
$response = Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->charge();

// Returns ChargeResponseDTO
echo $response->authorizationUrl; // URL to redirect user
echo $response->reference; // Transaction reference
```

**Throws:**
- `InvalidConfigurationException` - If callback URL is missing
- `ProviderException` - If all providers fail

#### `redirect(): RedirectResponse`
Process the payment and redirect the user to the payment page.

```php
return Payment::amount(10000)
    ->email('customer@example.com')
    ->callback(route('payment.callback'))
    ->redirect(); // Redirects user to payment page
```

**Throws:**
- `InvalidConfigurationException` - If callback URL is missing
- `ProviderException` - If all providers fail

### Verification Method (Standalone)

#### `verify(string $reference, ?string $provider = null): VerificationResponseDTO`
Verify a payment by reference. This is a standalone method and cannot be chained.

```php
// Search all providers
$verification = Payment::verify('PAYSTACK_123456');

// Verify with specific provider
$verification = Payment::verify('PAYSTACK_123456', 'paystack');

if ($verification->isSuccessful()) {
    // Payment succeeded
}
```

**Returns:** `VerificationResponseDTO`

**Throws:**
- `ProviderException` - If payment not found in any provider

---

## PaymentManager

Manages driver instances and coordinates payment operations.

### Namespace
```php
use KenDeNigerian\PayZephyr\PaymentManager;
```

### Methods

#### `driver(?string $name = null): DriverInterface`
Get a driver instance for the specified provider. If no name provided, returns the default driver.

```php
$manager = app(PaymentManager::class);
$driver = $manager->driver('paystack');
$driver = $manager->driver(); // Default driver
```

**Returns:** `DriverInterface`

**Throws:**
- `DriverNotFoundException` - If driver not found or disabled

#### `chargeWithFallback(ChargeRequestDTO $request, ?array $providers = null): ChargeResponseDTO`
Process payment with automatic fallback to backup providers.

```php
$request = ChargeRequestDTO::fromArray([
    'amount' => 10000,
    'currency' => 'NGN',
    'email' => 'customer@example.com',
    'callback_url' => route('payment.callback'),
]);

$response = $manager->chargeWithFallback($request, ['paystack', 'stripe']);
```

**Parameters:**
- `ChargeRequestDTO $request` - Payment request data
- `?array $providers` - Optional provider list. If null, uses fallback chain from config.

**Returns:** `ChargeResponseDTO`

**Throws:**
- `ProviderException` - If all providers fail

#### `verify(string $reference, ?string $provider = null): VerificationResponseDTO`
Verify a payment by reference.

```php
$verification = $manager->verify('PAYSTACK_123456');
$verification = $manager->verify('PAYSTACK_123456', 'paystack');
```

**Returns:** `VerificationResponseDTO`

**Throws:**
- `ProviderException` - If payment not found

#### `getDefaultDriver(): string`
Get the name of the default payment provider.

```php
$default = $manager->getDefaultDriver(); // 'paystack'
```

**Returns:** `string`

#### `getFallbackChain(): array`
Get the fallback provider chain (default + fallback).

```php
$chain = $manager->getFallbackChain(); // ['paystack', 'stripe']
```

**Returns:** `array<string>`

#### `getEnabledProviders(): array`
Get all enabled payment providers.

```php
$providers = $manager->getEnabledProviders();
// ['paystack' => [...], 'stripe' => [...]]
```

**Returns:** `array<string, array>`

---

## Drivers

All drivers implement `DriverInterface` and extend `AbstractDriver`.

### AbstractDriver

Base class providing common functionality for all drivers.

#### Protected Methods (Available to concrete drivers)

##### `makeRequest(string $method, string $uri, array $options = []): ResponseInterface`
Make an HTTP request to the provider's API. Automatically adds idempotency headers if available.

```php
$response = $this->makeRequest('POST', '/api/charge', [
    'json' => $payload,
    'headers' => ['Custom-Header' => 'value'],
]);
```

##### `parseResponse(ResponseInterface $response): array`
Parse JSON response from API.

```php
$data = $this->parseResponse($response);
```

##### `generateReference(?string $prefix = null): string`
Generate a unique transaction reference.

```php
$reference = $this->generateReference('PAYSTACK');
// Returns: PAYSTACK_1234567890_abc123def456
```

##### `isCurrencySupported(string $currency): bool`
Check if the provider supports a currency.

```php
if ($this->isCurrencySupported('NGN')) {
    // Currency supported
}
```

##### `healthCheck(): bool`
Check if the provider is available and healthy.

```php
if ($driver->healthCheck()) {
    // Provider is healthy
}
```

##### `getCachedHealthCheck(): bool`
Get cached health check result (faster than `healthCheck()`).

```php
if ($driver->getCachedHealthCheck()) {
    // Provider is healthy (cached result)
}
```

##### `log(string $level, string $message, array $context = []): void`
Log a message with context.

```php
$this->log('info', 'Charge successful', ['reference' => $reference]);
$this->log('error', 'Charge failed', ['error' => $e->getMessage()]);
```

##### `mapChannels(?ChargeRequestDTO $request): ?array`
Map unified channels to provider-specific format.

```php
$channels = $this->mapChannels($request);
// Returns provider-specific channel array or null
```

##### `normalizeStatus(string $status): string`
Normalize status using StatusNormalizer service.

```php
$normalized = $this->normalizeStatus('SUCCESS'); // Returns 'success'
```

#### Abstract Methods (Must be implemented)

##### `validateConfig(): void`
Validate that all required configuration is present.

##### `getDefaultHeaders(): array`
Get default HTTP headers for API requests (e.g., Authorization).

### DriverInterface Methods

All drivers must implement these methods:

#### `charge(ChargeRequestDTO $request): ChargeResponseDTO`
Initialize a payment/charge.

**Returns:** `ChargeResponseDTO`

**Throws:**
- `ChargeException` - If charge fails

#### `verify(string $reference): VerificationResponseDTO`
Verify a payment transaction.

**Returns:** `VerificationResponseDTO`

**Throws:**
- `VerificationException` - If verification fails

#### `validateWebhook(array $headers, string $body): bool`
Validate webhook signature.

**Returns:** `bool` - True if signature is valid

#### `healthCheck(): bool`
Check if provider is available.

**Returns:** `bool`

#### `getName(): string`
Get the provider name.

**Returns:** `string`

#### `getSupportedCurrencies(): array`
Get list of supported currencies.

**Returns:** `array<string>`

#### `extractWebhookReference(array $payload): ?string`
Extract payment reference from webhook payload.

**Returns:** `?string` - Reference or null if not found

#### `extractWebhookStatus(array $payload): string`
Extract payment status from webhook payload (in provider-native format).

**Returns:** `string` - Status in provider format

#### `extractWebhookChannel(array $payload): ?string`
Extract payment channel from webhook payload.

**Returns:** `?string` - Channel or null if not found

#### `resolveVerificationId(string $reference, string $providerId): string`
Resolve the actual ID needed for verification.

**Parameters:**
- `string $reference` - Package reference (e.g., PAYSTACK_123)
- `string $providerId` - Provider's internal ID (e.g., access_code)

**Returns:** `string` - ID to use for verification

---

## Data Transfer Objects

### ChargeRequestDTO

Immutable payment request data object.

#### Properties

```php
public readonly float $amount;              // Payment amount
public readonly string $currency;           // Currency code (ISO 4217)
public readonly string $email;              // Customer email
public readonly ?string $reference;        // Custom reference
public readonly ?string $callbackUrl;       // Callback URL
public readonly array $metadata;          // Custom metadata
public readonly ?string $description;      // Payment description
public readonly ?array $customer;          // Customer information
public readonly ?array $customFields;       // Custom fields
public readonly ?array $split;             // Split payment config
public readonly ?array $channels;         // Payment channels
public readonly ?string $idempotencyKey;   // Idempotency key
```

#### Methods

##### `getAmountInMinorUnits(): int`
Convert amount to minor currency units (cents, kobo, etc.).

```php
$request = new ChargeRequestDTO(amount: 100.00, currency: 'USD', email: 'test@example.com');
$cents = $request->getAmountInMinorUnits(); // 10000
```

##### `fromArray(array $data): ChargeRequestDTO`
Create instance from array.

```php
$request = ChargeRequestDTO::fromArray([
    'amount' => 10000,
    'currency' => 'NGN',
    'email' => 'customer@example.com',
]);
```

##### `toArray(): array`
Convert to array.

```php
$array = $request->toArray();
```

### ChargeResponseDTO

Immutable payment response data object.

#### Properties

```php
public readonly string $reference;         // Payment reference
public readonly string $authorizationUrl;  // URL to redirect user
public readonly string $accessCode;       // Provider access code
public readonly string $status;           // Payment status
public readonly array $metadata;          // Metadata
public readonly ?string $provider;        // Provider name
```

#### Methods

##### `isSuccessful(): bool`
Check if payment was successful.

##### `isPending(): bool`
Check if payment is pending.

##### `toArray(): array`
Convert to array.

### VerificationResponseDTO

Immutable verification response data object.

#### Properties

```php
public readonly string $reference;        // Payment reference
public readonly string $status;           // Payment status
public readonly float $amount;           // Amount paid
public readonly string $currency;        // Currency code
public readonly ?string $paidAt;         // Payment timestamp (ISO 8601)
public readonly array $metadata;        // Metadata
public readonly ?string $provider;       // Provider name
public readonly ?string $channel;        // Payment channel
public readonly ?string $cardType;       // Card type (if applicable)
public readonly ?string $bank;           // Bank name (if applicable)
public readonly ?array $customer;        // Customer information
```

#### Methods

##### `isSuccessful(): bool`
Check if payment was successful.

##### `isFailed(): bool`
Check if payment failed.

##### `isPending(): bool`
Check if payment is pending.

##### `toArray(): array`
Convert to array.

##### `fromArray(array $data): VerificationResponseDTO`
Create instance from array.

---

## Services

### StatusNormalizer

Normalizes payment statuses across providers.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
```

#### Methods

##### `normalize(string $status, ?string $provider = null): string`
Normalize a status string.

```php
$normalizer = app(StatusNormalizer::class);
$normalized = $normalizer->normalize('SUCCESS', 'paystack'); // 'success'
$normalized = $normalizer->normalize('PAID'); // 'success'
```

**Returns:** `string` - Normalized status (success, failed, pending, or lowercase original)

##### `registerProviderMappings(string $provider, array $mappings): self`
Register provider-specific status mappings.

```php
$normalizer->registerProviderMappings('paypal', [
    'success' => ['PAYMENT.CAPTURE.COMPLETED', 'COMPLETED'],
    'failed' => ['PAYMENT.CAPTURE.DENIED'],
]);
```

**Returns:** `self` - For method chaining

##### `getProviderMappings(): array`
Get all registered provider mappings.

##### `getDefaultMappings(): array`
Get default status mappings.

##### `normalizeStatic(string $status): string`
Normalize status without container (static method).

```php
$normalized = StatusNormalizer::normalizeStatic('SUCCESS'); // 'success'
```

### ChannelMapper

Maps unified payment channels to provider-specific formats.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
```

#### Methods

##### `mapChannels(?array $channels, string $provider): ?array`
Map unified channels to provider format.

```php
$mapper = app(ChannelMapper::class);
$paystackChannels = $mapper->mapChannels(['card', 'bank_transfer'], 'paystack');
// Returns: ['card', 'bank_transfer']
```

**Returns:** `?array` - Provider-specific channels or null if empty/not supported

##### `supportsChannels(string $provider): bool`
Check if provider supports channel selection.

```php
if ($mapper->supportsChannels('paystack')) {
    // Provider supports channels
}
```

##### `getDefaultChannels(string $provider): ?array`
Get default channels for provider.

```php
$defaults = $mapper->getDefaultChannels('paystack');
```

##### `getUnifiedChannels(): array`
Get all unified channel constants.

```php
$channels = $mapper->getUnifiedChannels();
// ['card', 'bank_transfer', 'ussd', 'mobile_money', 'qr_code']
```

### ProviderDetector

Detects provider from transaction reference.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Services\ProviderDetector;
```

#### Methods

##### `detectFromReference(string $reference): ?string`
Detect provider from reference prefix.

```php
$detector = app(ProviderDetector::class);
$provider = $detector->detectFromReference('PAYSTACK_123456'); // 'paystack'
$provider = $detector->detectFromReference('FLW_789012'); // 'flutterwave'
```

**Returns:** `?string` - Provider name or null if not detected

##### `registerPrefix(string $prefix, string $provider): self`
Register a custom reference prefix.

```php
$detector->registerPrefix('CUSTOM', 'customprovider');
```

**Returns:** `self` - For method chaining

##### `getPrefixes(): array`
Get all registered prefixes.

```php
$prefixes = $detector->getPrefixes();
// ['PAYSTACK' => 'paystack', 'FLW' => 'flutterwave', ...]
```

### DriverFactory

Creates driver instances.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Services\DriverFactory;
```

#### Methods

##### `create(string $name, array $config): DriverInterface`
Create a driver instance.

```php
$factory = app(DriverFactory::class);
$driver = $factory->create('paystack', $config);
```

**Returns:** `DriverInterface`

**Throws:**
- `DriverNotFoundException` - If driver class not found

##### `register(string $name, string $class): self`
Register a custom driver.

```php
$factory->register('custom', CustomDriver::class);
```

**Returns:** `self` - For method chaining

**Throws:**
- `DriverNotFoundException` - If class doesn't exist or doesn't implement DriverInterface

##### `getRegisteredDrivers(): array`
Get all registered custom driver names.

##### `isRegistered(string $name): bool`
Check if a driver is registered.

---

## Contracts (Interfaces)

### DriverInterface

Contract for all payment provider drivers.

```php
interface DriverInterface
{
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO;
    public function verify(string $reference): VerificationResponseDTO;
    public function validateWebhook(array $headers, string $body): bool;
    public function healthCheck(): bool;
    public function getName(): string;
    public function getSupportedCurrencies(): array;
    public function extractWebhookReference(array $payload): ?string;
    public function extractWebhookStatus(array $payload): string;
    public function extractWebhookChannel(array $payload): ?string;
    public function resolveVerificationId(string $reference, string $providerId): string;
}
```

### StatusNormalizerInterface

Contract for status normalization.

```php
interface StatusNormalizerInterface
{
    public function normalize(string $status, ?string $provider = null): string;
    public function registerProviderMappings(string $provider, array $mappings): self;
}
```

### ChannelMapperInterface

Contract for channel mapping.

```php
interface ChannelMapperInterface
{
    public function mapChannels(?array $channels, string $provider): ?array;
    public function supportsChannels(string $provider): bool;
    public function getDefaultChannels(string $provider): ?array;
}
```

### ProviderDetectorInterface

Contract for provider detection.

```php
interface ProviderDetectorInterface
{
    public function detectFromReference(string $reference): ?string;
    public function registerPrefix(string $prefix, string $provider): self;
}
```

---

## Models

### PaymentTransaction

Eloquent model for transaction logging.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
```

#### Properties

```php
$transaction->id            // Auto-increment ID
$transaction->reference     // Payment reference
$transaction->provider      // Provider name
$transaction->status        // Payment status
$transaction->amount        // Amount (decimal:2)
$transaction->currency      // Currency code
$transaction->email         // Customer email
$transaction->channel       // Payment channel
$transaction->metadata      // Metadata (ArrayObject)
$transaction->customer      // Customer info (ArrayObject)
$transaction->paid_at       // Payment timestamp
$transaction->created_at    // Created timestamp
$transaction->updated_at    // Updated timestamp
```

#### Methods

##### `isSuccessful(): bool`
Check if payment was successful (uses StatusNormalizer).

##### `isFailed(): bool`
Check if payment failed (uses StatusNormalizer).

##### `isPending(): bool`
Check if payment is pending (uses StatusNormalizer).

#### Scopes

##### `scopeSuccessful(Builder $query): Builder`
Filter successful payments.

```php
$successful = PaymentTransaction::successful()->get();
```

##### `scopeFailed(Builder $query): Builder`
Filter failed payments.

```php
$failed = PaymentTransaction::failed()->get();
```

##### `scopePending(Builder $query): Builder`
Filter pending payments.

```php
$pending = PaymentTransaction::pending()->get();
```

#### Static Methods

##### `setTableName(string $table): void`
Set the table name (used by service provider).

```php
PaymentTransaction::setTableName('custom_transactions');
```

---

## Enums

### PaymentStatus

Type-safe payment status enum.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
```

#### Cases

```php
PaymentStatus::SUCCESS    // 'success'
PaymentStatus::FAILED     // 'failed'
PaymentStatus::PENDING    // 'pending'
PaymentStatus::CANCELLED  // 'cancelled'
```

#### Methods

##### `isSuccessful(): bool`
Check if status is successful.

##### `isFailed(): bool`
Check if status is failed.

##### `isPending(): bool`
Check if status is pending.

##### `all(): array`
Get all status values.

##### `tryFromString(string $value): ?self`
Try to create enum from string.

```php
$status = PaymentStatus::tryFromString('success'); // PaymentStatus::SUCCESS
$status = PaymentStatus::tryFromString('invalid'); // null
```

##### `fromString(string $value): self`
Create enum from string (throws if invalid).

```php
$status = PaymentStatus::fromString('success'); // PaymentStatus::SUCCESS
$status = PaymentStatus::fromString('invalid'); // ValueError
```

##### `isValid(string $value): bool`
Check if value is valid status.

##### `isSuccessfulString(string $status): bool`
Check if string status is successful.

##### `isFailedString(string $status): bool`
Check if string status is failed.

##### `isPendingString(string $status): bool`
Check if string status is pending.

### PaymentChannel

Type-safe payment channel enum.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Enums\PaymentChannel;
```

#### Cases

```php
PaymentChannel::CARD           // 'card'
PaymentChannel::BANK_TRANSFER   // 'bank_transfer'
PaymentChannel::USSD           // 'ussd'
PaymentChannel::MOBILE_MONEY   // 'mobile_money'
PaymentChannel::QR_CODE        // 'qr_code'
```

#### Methods

##### `label(): string`
Get human-readable label.

```php
PaymentChannel::CARD->label(); // 'Credit/Debit Card'
```

##### `values(): array`
Get all channel values.

```php
PaymentChannel::values(); // ['card', 'bank_transfer', 'ussd', 'mobile_money', 'qr_code']
```

---

## Exceptions

All exceptions extend `PaymentException` and can carry context.

### PaymentException

Base exception for all payment-related errors.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
```

#### Methods

##### `getContext(): array`
Get exception context.

##### `setContext(array $context): self`
Set exception context.

### DriverNotFoundException

Thrown when a driver is not found or disabled.

```php
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;

try {
    $driver = $manager->driver('nonexistent');
} catch (DriverNotFoundException $e) {
    // Handle error
}
```

### InvalidConfigurationException

Thrown when required configuration is missing.

```php
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
```

### ChargeException

Thrown when a charge/payment fails.

```php
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
```

### VerificationException

Thrown when verification fails.

```php
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
```

### WebhookException

Thrown when webhook processing fails.

```php
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;
```

### ProviderException

Thrown when all providers fail (fallback exhausted).

```php
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;

try {
    $response = Payment::amount(10000)->charge();
} catch (ProviderException $e) {
    $context = $e->getContext();
    // $context['exceptions'] contains errors from all providers
}
```

#### Static Methods

##### `withContext(string $message, array $context): self`
Create exception with context.

```php
throw ProviderException::withContext('All providers failed', [
    'exceptions' => ['paystack' => 'Error 1', 'stripe' => 'Error 2'],
]);
```

---

## Events

### WebhookReceived

Event dispatched when a webhook is processed.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
```

#### Properties

```php
$event->provider   // Provider name
$event->payload    // Webhook payload array
$event->reference  // Payment reference (may be null)
```

#### Usage

```php
Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    if ($event->provider === 'paystack' && $event->reference) {
        // Handle Paystack webhook
        Order::where('payment_reference', $event->reference)
            ->update(['status' => 'paid']);
    }
});
```

---

## HTTP Resources

Laravel API resources for transforming DTOs to JSON responses.

### ChargeResource

Transforms `ChargeResponseDTO` to JSON response format.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Http\Resources\ChargeResource;
```

#### Usage

```php
$response = Payment::amount(10000)->charge();
return new ChargeResource($response);
```

#### Response Format

```json
{
    "reference": "PAYSTACK_123456",
    "authorization_url": "https://checkout.paystack.com/...",
    "status": "pending",
    "provider": "paystack",
    "amount": {
        "value": 10000,
        "currency": "NGN"
    },
    "metadata": {...},
    "created_at": "2024-01-01T00:00:00Z"
}
```

### VerificationResource

Transforms `VerificationResponseDTO` to JSON response format.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Http\Resources\VerificationResource;
```

#### Usage

```php
$verification = Payment::verify('PAYSTACK_123456');
return new VerificationResource($verification);
```

#### Response Format

```json
{
    "reference": "PAYSTACK_123456",
    "status": "success",
    "provider": "paystack",
    "channel": "card",
    "amount": {
        "value": 10000.0,
        "currency": "NGN"
    },
    "paid_at": "2024-01-01T00:00:00Z",
    "metadata": {...},
    "verified_at": "2024-01-01T00:00:00Z"
}
```

## HTTP Requests

### WebhookRequest

Form request for validating webhook payloads.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
```

#### Validation Rules

```php
[
    'event' => 'sometimes|string',
    'eventType' => 'sometimes|string',
    'event_type' => 'sometimes|string',
    'data' => 'sometimes|array',
    'reference' => 'sometimes|string',
    'status' => 'sometimes|string',
    'paymentStatus' => 'sometimes|string',
    'payment_status' => 'sometimes|string',
]
```

#### Authorization

The `authorize()` method:
- Checks if signature verification is enabled
- Gets the provider from route
- Calls driver's `validateWebhook()` method
- Returns `true` if signature is valid, `false` otherwise

## Jobs

### ProcessWebhook

Queued job for processing webhooks asynchronously.

#### Namespace
```php
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
```

#### Properties

```php
public readonly string $provider;  // Provider name
public readonly array $payload;    // Webhook payload
public int $tries = 3;             // Retry attempts
public int $backoff = 60;          // Backoff seconds
```

#### Usage

```php
ProcessWebhook::dispatch('paystack', $webhookPayload);
```

The job automatically:
- Extracts reference from payload
- Updates transaction in database
- Dispatches WebhookReceived event
- Logs processing

---

## Console Commands

### InstallCommand

Artisan command for installing the package.

#### Command
```bash
php artisan payzephyr:install
php artisan payzephyr:install --force  # Overwrite existing files
```

#### What it does:
1. Publishes configuration file (`config/payments.php`)
2. Publishes migration files
3. Optionally runs migrations
4. Displays setup instructions

#### Usage

```bash
# Install package
php artisan payzephyr:install

# Force overwrite existing files
php artisan payzephyr:install --force
```

## Helper Function

### `payment(): Payment`

Global helper function that returns a Payment instance.

```php
use function KenDeNigerian\PayZephyr\payment;

return payment()
    ->amount(10000)
    ->email('customer@example.com')
    ->redirect();
```

**Note:** This is equivalent to `Payment::` facade but can be used without importing the facade.

---

## Type Definitions

### Response Types

#### ChargeResponseDTO
```php
object {
    reference: string
    authorizationUrl: string
    accessCode: string
    status: string
    metadata: array
    provider?: string
}
```

#### VerificationResponseDTO
```php
object {
    reference: string
    status: string
    amount: float
    currency: string
    paidAt?: string|null
    metadata: array
    provider?: string
    channel?: string|null
    cardType?: string|null
    bank?: string|null
    customer?: array|null
}
```

---

## Examples

### Complete Payment Flow

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// 1. Create payment
return Payment::amount(50000)
    ->currency('NGN')
    ->email('customer@example.com')
    ->reference('ORDER_' . time())
    ->callback(route('payment.callback'))
    ->metadata(['order_id' => 12345])
    ->with(['paystack', 'stripe'])
    ->redirect();

// 2. Verify payment (in callback route)
public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            Order::where('reference', $reference)
                ->update(['status' => 'paid', 'paid_at' => $verification->paidAt]);
            
            return view('payment.success', [
                'amount' => $verification->amount,
                'currency' => $verification->currency,
            ]);
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

// Get supported currencies
$currencies = $driver->getSupportedCurrencies();
```

### Custom Driver Registration

```php
use KenDeNigerian\PayZephyr\Services\DriverFactory;

$factory = app(DriverFactory::class);
$factory->register('custom', CustomDriver::class);

// Now you can use it
$manager = app(PaymentManager::class);
$driver = $manager->driver('custom');
```

### Custom Status Normalizer

```php
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;

class CustomNormalizer implements StatusNormalizerInterface
{
    public function normalize(string $status, ?string $provider = null): string
    {
        // Custom normalization logic
    }
    
    public function registerProviderMappings(string $provider, array $mappings): self
    {
        // Custom mapping registration
    }
}

// Register in service provider
app()->singleton(StatusNormalizerInterface::class, CustomNormalizer::class);
```

---

## HTTP Endpoints

PayZephyr automatically registers HTTP endpoints for webhooks and health checks.

### Health Check Endpoint

**Route:** `GET /payments/health`

**Middleware:** `api`

**Description:** Returns the health status of all enabled payment providers.

**Response:**
```json
{
  "status": "operational",
  "providers": {
    "paystack": {
      "healthy": true,
      "currencies": ["NGN", "USD", "GHS", "ZAR"]
    },
    "stripe": {
      "healthy": true,
      "currencies": ["USD", "EUR", "GBP", "CAD", "AUD"]
    },
    "flutterwave": {
      "healthy": false,
      "currencies": ["NGN", "USD", "EUR", "GBP"],
      "error": "Connection timeout"
    }
  }
}
```

**Response Fields:**
- `status` (string): Always `"operational"` - indicates the endpoint is working
- `providers` (object): Object keyed by provider name
  - `healthy` (boolean): Whether the provider is currently available
  - `currencies` (array): List of supported currency codes
  - `error` (string, optional): Error message if provider is unhealthy

**Usage:**
```bash
# Using curl
curl https://your-app.com/payments/health

# Using Laravel HTTP client
$response = Http::get(url('/payments/health'));
$data = $response->json();

# Check specific provider
if ($data['providers']['paystack']['healthy']) {
    // Provider is available
}
```

**Caching:**
- Health checks are cached to avoid excessive API calls
- Default cache TTL: 5 minutes (300 seconds)
- Configure via `PAYMENTS_HEALTH_CHECK_CACHE_TTL` environment variable

**Note:** Only enabled providers are included in the response.

### Webhook Endpoint

**Route:** `POST /payments/webhook/{provider}`

**Middleware:** `api`, `throttle:120,1` (120 requests per minute)

**Description:** Receives webhook notifications from payment providers.

**Parameters:**
- `provider` (string): Provider name (e.g., `paystack`, `stripe`, `flutterwave`)

**Example:**
```
POST /payments/webhook/paystack
POST /payments/webhook/stripe
POST /payments/webhook/flutterwave
```

**Configuration:**
- Webhook path can be customized via `PAYMENTS_WEBHOOK_PATH` environment variable
- Rate limiting can be adjusted via `PAYMENTS_WEBHOOK_RATE_LIMIT` environment variable

For complete webhook documentation, see [Webhook Guide](webhooks.md).

---

For more examples and usage patterns, see:
- [Getting Started Guide](GETTING_STARTED.md)
- [Architecture Guide](architecture.md)
- [Webhook Guide](webhooks.md)

