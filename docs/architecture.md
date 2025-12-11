# Architecture Guide

## Overview

This guide explains how PayZephyr is built internally. You don't need to understand this to use the package, but it's helpful if you want to:
- Customize the package
- Add new payment providers
- Debug issues
- Understand how everything works together

The package follows clean architecture principles with clear separation of concerns, dependency injection, and SOLID principles.

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Facades & Helpers                          │
│              (Payment::, payment())                          │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                  Payment (Fluent API)                        │
│         Builds ChargeRequestDTO & calls Manager               │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                  PaymentManager                               │
│   - Manages driver instances (via DriverFactory)             │
│   - Handles fallback logic                                    │
│   - Coordinates health checks                                  │
│   - Resolves verification context                              │
│   - Logs transactions                                          │
└──────────────────────┬──────────────────────────────────────┘
                       │
        ┌──────────────┼──────────────┐
        │              │              │
┌───────▼──────┐ ┌─────▼──────┐ ┌────▼──────┐
│ DriverFactory│ │  Services  │ │   DTOs     │
│              │ │            │ │            │
│ - Creates    │ │ - Status   │ │ - Charge   │
│   drivers    │ │   Normalizer│ │   Request  │
│ - Registers  │ │ - Channel   │ │ - Charge   │
│   custom     │ │   Mapper    │ │   Response │
│   drivers    │ │ - Provider  │ │ - Verif.   │
│              │ │   Detector  │ │   Response │
└───────┬──────┘ └─────────────┘ └────────────┘
        │
┌───────▼──────────────────────────────────────┐
│           Drivers Layer                      │
│  AbstractDriver ← Implements DriverInterface │
│         ├─ PaystackDriver                    │
│         ├─ FlutterwaveDriver                 │
│         ├─ MonnifyDriver                     │
│         ├─ StripeDriver                      │
│         ├─ PayPalDriver                      │
│         ├─ SquareDriver                      │
│         └─ OPayDriver                        │
└──────────────────────┬──────────────────────┘
                       │
┌──────────────────────▼──────────────────────┐
│      External Payment APIs                   │
│   (Paystack, Stripe, etc.)                   │
└──────────────────────────────────────────────┘
```

## Core Components

### 1. Contracts (Interfaces)

Interfaces define contracts that classes must implement, enabling dependency injection and testability.

#### DriverInterface
Every payment provider driver must implement this interface. It defines the core payment operations:

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

**Why interfaces?** They allow:
- Easy testing (mock the interface)
- Dependency injection (swap implementations)
- Type safety (enforce contracts)
- Extensibility (add new drivers without changing core code)

#### StatusNormalizerInterface
Defines how payment statuses are normalized across providers:

```php
interface StatusNormalizerInterface
{
    public function normalize(string $status, ?string $provider = null): string;
    public function registerProviderMappings(string $provider, array $mappings): self;
}
```

#### ChannelMapperInterface
Maps unified payment channels to provider-specific formats:

```php
interface ChannelMapperInterface
{
    public function mapChannels(?array $channels, string $provider): ?array;
    public function supportsChannels(string $provider): bool;
    public function getDefaultChannels(string $provider): ?array;
}
```

#### ProviderDetectorInterface
Detects which provider processed a payment based on reference format:

```php
interface ProviderDetectorInterface
{
    public function detectFromReference(string $reference): ?string;
    public function registerPrefix(string $prefix, string $provider): self;
}
```

### 2. Data Transfer Objects (DTOs)

DTOs are immutable, type-safe data containers that ensure consistent data structures across the system.

#### ChargeRequestDTO
Holds payment request data with validation:

```php
final readonly class ChargeRequestDTO
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $email,
        public ?string $reference = null,
        public ?string $callbackUrl = null,
        public array $metadata = [],
        // ... more fields
    ) {
        $this->validate(); // Validates on construction
    }
    
    public function getAmountInMinorUnits(): int; // Converts to cents/kobo
    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

**Key Features:**
- **Readonly**: Immutable after creation
- **Validation**: Ensures data integrity
- **Type Safety**: Strict typing throughout
- **Conversion**: Handles amount conversion (major to minor units)

#### ChargeResponseDTO
Holds the response after creating a payment:

```php
final readonly class ChargeResponseDTO
{
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public string $accessCode,
        public string $status,
        public array $metadata = [],
        public ?string $provider = null,
    ) {}
    
    public function isSuccessful(): bool;
    public function isPending(): bool;
    public function toArray(): array;
}
```

#### VerificationResponseDTO
Holds the result of checking a payment status:

```php
final readonly class VerificationResponseDTO
{
    public function __construct(
        public string $reference,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $paidAt = null,
        public array $metadata = [],
        public ?string $provider = null,
        public ?string $channel = null,
        // ... more fields
    ) {}
    
    public function isSuccessful(): bool;
    public function isFailed(): bool;
    public function isPending(): bool;
    protected function getNormalizedStatus(): string; // Uses StatusNormalizer
}
```

**Why DTOs?** 
- **Consistency**: Same data format regardless of provider
- **Type Safety**: Prevents runtime errors
- **Immutability**: Data can't be accidentally modified
- **Validation**: Ensures data integrity

### 3. Services

Services handle cross-cutting concerns and provide reusable functionality.

#### StatusNormalizer
Normalizes payment statuses from different providers to a standard format:

```php
final class StatusNormalizer implements StatusNormalizerInterface
{
    protected array $providerMappings = [];
    protected array $defaultMappings = [
        'success' => ['SUCCESS', 'SUCCEEDED', 'COMPLETED', ...],
        'failed' => ['FAILED', 'REJECTED', 'CANCELLED', ...],
        'pending' => ['PENDING', 'PROCESSING', ...],
    ];
    
    public function normalize(string $status, ?string $provider = null): string;
    public function registerProviderMappings(string $provider, array $mappings): self;
    public static function normalizeStatic(string $status): string; // Fallback when container unavailable
}
```

**How it works:**
1. Checks provider-specific mappings first
2. Falls back to default mappings
3. Returns lowercase version if no mapping found
4. Supports static normalization (for use outside Laravel container)

#### ChannelMapper
Maps unified payment channels to provider-specific formats using Convention over Configuration with dynamic method checking:

```php
final class ChannelMapper implements ChannelMapperInterface
{
    public function mapChannels(?array $channels, string $provider): ?array
    {
        // Uses dynamic method checking: mapTo{Provider}
        // Example: 'paystack' → mapToPaystack()
        // Example: 'flutterwave' → mapToFlutterwave()
        // Falls back to returning channels as-is if method doesn't exist
    }
    
    public function supportsChannels(string $provider): bool;
    public function getDefaultChannels(string $provider): ?array;
    
    // Provider-specific mapping methods (dynamically called)
    protected function mapToPaystack(array $channels): array;
    protected function mapToMonnify(array $channels): array;
    protected function mapToFlutterwave(array $channels): array;
    protected function mapToStripe(array $channels): array;
    protected function mapToPayPal(array $channels): ?array; // Returns null (not supported)
}
```

**How it works:**
- **Dynamic Method Resolution**: Automatically calls `mapTo{Provider}()` method based on provider name
- **Convention-based**: Provider name is converted to PascalCase (e.g., `'paystack'` → `mapToPaystack()`)
- **Extensible**: Adding new provider mapping requires only adding a new `mapTo{Provider}()` method
- **Fallback**: Returns channels as-is if no provider-specific method exists
- **Null handling**: Returns `null` for providers that don't support channel filtering (e.g., PayPal)

**Benefits:**
- **No hardcoded provider lists**: Automatically supports new providers via method naming convention
- **Maintainable**: Each provider's mapping logic is isolated in its own method
- **Testable**: Easy to test individual provider mappings

**Unified Channels:**
- `card` - Credit/Debit cards
- `bank_transfer` - Bank transfers
- `ussd` - USSD payments
- `mobile_money` - Mobile money
- `qr_code` - QR code payments

**Provider Formats:**
- Paystack: `['card', 'bank_transfer', 'ussd', 'qr', 'mobile_money']`
- Monnify: `['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER']`
- Stripe: `['card']` (payment method types)

#### ProviderDetector
Detects which provider processed a payment based on reference prefix. Uses Convention over Configuration to dynamically build prefix list from enabled providers in config:

```php
final class ProviderDetector implements ProviderDetectorInterface
{
    protected array $prefixes = []; // Dynamically loaded from config
    
    public function __construct()
    {
        $this->prefixes = $this->loadPrefixesFromConfig();
    }
    
    protected function loadPrefixesFromConfig(): array
    {
        // Loads all providers from config (not just enabled ones)
        // Uses reference_prefix from config, or defaults to UPPERCASE(provider_name)
        // Example: 'flutterwave' → 'FLW' (if reference_prefix set) or 'FLUTTERWAVE'
    }
    
    public function detectFromReference(string $reference): ?string;
    public function registerPrefix(string $prefix, string $provider): self;
    public function getPrefixes(): array;
}
```

**How it works:**
- **Dynamic Loading**: Automatically loads prefixes from all providers in config
- **Convention**: Defaults to `UPPERCASE(provider_name)` if `reference_prefix` not set
- **Configuration**: Respects `reference_prefix` setting in provider config (e.g., `FLW` for Flutterwave, `MON` for Monnify)
- **Detection**: Checks reference prefix (e.g., `PAYSTACK_123456` → `paystack`)
- **Case-insensitive**: Matching works regardless of case
- **Requires underscore**: Prefix must be followed by underscore
- **Custom registration**: Supports runtime prefix registration via `registerPrefix()`

**Benefits:**
- **No hardcoded lists**: Automatically detects new providers added to config
- **Flexible**: Supports custom prefixes via config
- **Maintainable**: Adding new providers requires no code changes

#### DriverFactory
Creates driver instances following the Factory pattern. Uses Convention over Configuration to automatically resolve driver classes:

```php
final class DriverFactory
{
    protected array $drivers = []; // Custom registered drivers
    
    public function create(string $name, array $config): DriverInterface;
    public function register(string $name, string $class): self;
    public function getRegisteredDrivers(): array;
    public function isRegistered(string $name): bool;
    
    protected function resolveDriverClass(string $name): string
    {
        // Priority: Registered → Config → Convention → Direct class name
        // Convention: 'paystack' → 'KenDeNigerian\PayZephyr\Drivers\PaystackDriver'
        // Special case: 'paypal' → 'PayPalDriver' (handles case differences)
    }
}
```

**Resolution Priority:**
1. **Registered drivers** (custom drivers registered at runtime via `register()`)
2. **Config drivers** (from `config['providers'][$name]['driver_class']`)
3. **Convention-based** (automatically resolves `{Provider}Driver` class)
   - Converts provider name to PascalCase: `'paystack'` → `'Paystack'` → `'PaystackDriver'`
   - Handles special cases (e.g., `'paypal'` → `'PayPalDriver'`)
4. **Direct class name** (assumes fully qualified class name if convention fails)

**Convention Examples:**
- `'paystack'` → `PaystackDriver`
- `'flutterwave'` → `FlutterwaveDriver`
- `'monnify'` → `MonnifyDriver`
- `'stripe'` → `StripeDriver`
- `'paypal'` → `PayPalDriver` (special case)
- `'square'` → `SquareDriver`
- `'opay'` → `OpayDriver`

**Benefits:**
- **OCP Compliance**: Add drivers without modifying core code
- **Convention over Configuration**: No hardcoded provider lists
- **Flexibility**: Supports custom drivers via registration or config
- **Extensibility**: New providers automatically work if they follow naming convention
- **Testability**: Easy to mock and test

### 4. Drivers

Drivers are classes that communicate with specific payment providers. Each provider has its own driver.

#### AbstractDriver (Base Class)

All drivers extend this abstract class, which provides common functionality:

```php
abstract class AbstractDriver implements DriverInterface
{
    protected Client $client;
    protected array $config;
    protected string $name;
    protected ?ChargeRequestDTO $currentRequest = null;
    protected ?StatusNormalizer $statusNormalizer = null;
    protected ?ChannelMapper $channelMapper = null;
    
    // Abstract methods (must be implemented by each driver)
    abstract protected function validateConfig(): void;
    abstract protected function getDefaultHeaders(): array;
    
    // Common functionality
    protected function makeRequest(string $method, string $uri, array $options = []): ResponseInterface;
    protected function parseResponse(ResponseInterface $response): array;
    protected function generateReference(?string $prefix = null): string;
    public function healthCheck(): bool;
    public function isCurrencySupported(string $currency): bool;
    public function getCachedHealthCheck(): bool;
    protected function log(string $level, string $message, array $context = []): void;
    protected function getIdempotencyHeader(string $key): array;
    protected function mapChannels(?ChargeRequestDTO $request): ?array;
    protected function normalizeStatus(string $status): string;
}
```

**Key Features:**
- **HTTP Client**: Pre-configured Guzzle client with base URL, timeout, SSL settings
- **Idempotency**: Automatically injects idempotency keys when available
- **Health Checks**: Cached provider availability checks
- **Logging**: Configurable logging with context
- **Currency Validation**: Checks if provider supports requested currency
- **Reference Generation**: Creates unique transaction references
- **Status Normalization**: Uses StatusNormalizer service
- **Channel Mapping**: Uses ChannelMapper service

**Injection Points:**
- `setStatusNormalizer()` - Inject custom normalizer for testing
- `setChannelMapper()` - Inject custom mapper for testing
- `setClient()` - Inject mock HTTP client for testing

#### Individual Drivers

Each provider has a concrete driver class:

**PaystackDriver:**
- Nigerian-focused payment provider
- Supports: Card, Bank Transfer, USSD, QR Code, Mobile Money
- Currencies: NGN, GHS, ZAR, USD
- Webhook validation: HMAC SHA512

**FlutterwaveDriver:**
- African-focused payment provider
- Supports: Card, Bank Transfer, USSD, Mobile Money (multiple countries)
- Currencies: NGN, USD, EUR, GBP, KES, UGX, TZS, and more
- Webhook validation: HMAC SHA512

**MonnifyDriver:**
- Nigerian payment provider with OAuth2 authentication
- Supports: Card, Bank Transfer, USSD, Phone Number
- Currencies: NGN
- Token-based authentication with caching

**StripeDriver:**
- Global payment provider using official SDK
- Supports: Card, Apple Pay, Google Pay
- Currencies: 135+ currencies
- Webhook validation: HMAC SHA256

**PayPalDriver:**
- Global payment provider using REST API
- Supports: PayPal Balance, Credit Cards
- Currencies: USD, EUR, GBP, CAD, AUD
- OAuth2 authentication with token caching

**SquareDriver:**
- US/Canada-focused provider
- Supports: Online Checkout, Card Payments
- Currencies: USD, CAD, GBP, AUD
- Webhook validation: HMAC SHA256

**OPayDriver:**
- Dual authentication support
- Create Payment API: Bearer token (Public Key)
- Status API: HMAC-SHA512 (Private Key + Merchant ID)
- Supports: Card, Bank Transfer, USSD, Mobile Money

**Driver Responsibilities:**
1. **Format requests** for provider's API
2. **Parse responses** from provider
3. **Validate webhooks** using provider-specific signature validation
4. **Extract webhook data** (reference, status, channel)
5. **Resolve verification IDs** (some use reference, others use access codes)
6. **Check health** by making lightweight API calls

### 5. PaymentManager

The PaymentManager coordinates all payment operations:

```php
class PaymentManager
{
    protected array $drivers = [];
    protected array $config;
    protected ProviderDetectorInterface $providerDetector;
    protected DriverFactory $driverFactory;
    
    public function driver(?string $name = null): DriverInterface;
    public function chargeWithFallback(ChargeRequestDTO $request, ?array $providers = null): ChargeResponseDTO;
    public function verify(string $reference, ?string $provider = null): VerificationResponseDTO;
    public function getDefaultDriver(): string;
    public function getFallbackChain(): array;
    public function getEnabledProviders(): array;
    
    // Internal methods
    protected function logTransaction(ChargeRequestDTO $request, ChargeResponseDTO $response, string $provider): void;
    protected function updateTransactionFromVerification(string $reference, VerificationResponseDTO $response): void;
    protected function resolveVerificationContext(string $reference, ?string $explicitProvider): array;
    protected function cacheSessionData(string $reference, string $provider, string $providerId): void;
}
```

**Key Responsibilities:**

1. **Driver Management:**
   - Creates drivers via DriverFactory
   - Caches driver instances (singleton pattern)
   - Resolves driver classes from config

2. **Fallback Logic:**
   - Tries providers in sequence
   - Skips unhealthy providers (if health checks enabled)
   - Skips providers that don't support currency
   - Aggregates errors from all failed providers

3. **Verification Context Resolution:**
   - **Cache First**: Fastest, works without database
   - **Database Second**: If logging enabled, checks transaction table
   - **Heuristic Fallback**: Uses ProviderDetector to guess from reference format
   - Uses driver's `resolveVerificationId()` to get correct ID

4. **Transaction Logging:**
   - Automatically logs all charges (if enabled)
   - Updates transactions from verification
   - Updates transactions from webhooks (via ProcessWebhook job)

5. **Session Caching:**
   - Caches provider and provider ID for each reference
   - Speeds up verification lookups
   - 1-hour TTL

### 6. Payment (Fluent API)

The Payment class provides a clean, chainable interface:

```php
final class Payment
{
    protected PaymentManager $manager;
    protected array $data = [];
    protected array $providers = [];
    
    // Builder methods (chainable in any order)
    public function amount(float $amount): Payment;
    public function currency(string $currency): Payment;
    public function email(string $email): Payment;
    public function reference(string $reference): Payment;
    public function callback(string $url): Payment;
    public function metadata(array $metadata): Payment;
    public function idempotency(string $key): Payment;
    public function description(string $description): Payment;
    public function customer(array $customer): Payment;
    public function channels(array $channels): Payment;
    public function with(string|array $providers): Payment;
    public function using(string|array $providers): Payment; // Alias
    
    // Action methods (must be called last)
    public function charge(): ChargeResponseDTO;
    public function redirect(): RedirectResponse;
    
    // Verification (standalone, not chainable)
    public function verify(string $reference, ?string $provider = null): VerificationResponseDTO;
}
```

**How it works:**
1. Builder methods accumulate data in `$data` array
2. `charge()` or `redirect()` builds `ChargeRequestDTO` from accumulated data
3. Sends request to `PaymentManager`
4. Returns response or redirects user

**Method Categories:**
- **Builder Methods**: Can be called in any order, return `$this` for chaining
- **Action Methods**: Must be called last, execute the payment
- **Verification Method**: Standalone, cannot be chained

### 7. Service Provider

The `PaymentServiceProvider` connects the package to Laravel:

```php
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register config as singleton (avoids breaking config caching)
        $this->app->singleton('payments.config', fn () => config('payments'));
        
        // Register services using interfaces
        $this->app->singleton(StatusNormalizerInterface::class, StatusNormalizer::class);
        $this->app->singleton(ProviderDetectorInterface::class, ProviderDetector::class);
        $this->app->singleton(ChannelMapperInterface::class, ChannelMapper::class);
        
        // Also bind concrete classes for backward compatibility
        $this->app->singleton(StatusNormalizer::class);
        $this->app->singleton(ProviderDetector::class);
        $this->app->singleton(ChannelMapper::class);
        
        // Register DriverFactory
        $this->app->singleton(DriverFactory::class);
        
        // Register PaymentManager with dependency injection
        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager(
                $app->make(ProviderDetectorInterface::class),
                $app->make(DriverFactory::class)
            );
        });
        
        // Register Payment builder
        $this->app->bind(Payment::class, function ($app) {
            return new Payment($app->make(PaymentManager::class));
        });
    }
    
    public function boot(): void
    {
        // Publish config and migrations
        // Register webhook routes
        // Configure PaymentTransaction model table name
        // Register webhook status mappings
    }
}
```

**Key Features:**
- **Config Singleton**: Prevents breaking Laravel's config caching
- **Interface Binding**: Enables dependency injection and testing
- **Backward Compatibility**: Also binds concrete classes
- **Route Registration**: Registers webhook endpoints
- **Model Configuration**: Sets dynamic table name from config

### 8. Models

#### PaymentTransaction

Eloquent model for transaction logging:

```php
final class PaymentTransaction extends Model
{
    protected $fillable = [
        'reference', 'provider', 'status', 'amount', 'currency',
        'email', 'channel', 'metadata', 'customer', 'paid_at',
    ];
    
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => AsArrayObject::class,
            'customer' => AsArrayObject::class,
            'paid_at' => 'datetime',
        ];
    }
    
    public function getTable() // Dynamic table name from config
    public function isSuccessful(): bool // Uses StatusNormalizer
    public function isFailed(): bool
    public function isPending(): bool
    
    // Scopes
    public function scopeSuccessful(Builder $query): Builder;
    public function scopeFailed(Builder $query): Builder;
    public function scopePending(Builder $query): Builder;
}
```

**Key Features:**
- **Dynamic Table Name**: Reads from config (`payments.logging.table`)
- **Status Methods**: Use StatusNormalizer for consistent status checking
- **Type Casting**: Proper casting for amounts, metadata, customer data
- **Scopes**: Convenient query scopes for filtering by status

### 9. Jobs

#### ProcessWebhook

Queued job for processing webhooks asynchronously:

```php
class ProcessWebhook implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 60;
    
    public function __construct(
        public readonly string $provider,
        public readonly array $payload
    ) {}
    
    public function handle(PaymentManager $manager, StatusNormalizerInterface $statusNormalizer): void
    {
        // Extract reference from payload
        // Update transaction if logging enabled
        // Dispatch WebhookReceived event
        // Log processing
    }
    
    protected function extractReference(PaymentManager $manager): ?string;
    protected function updateTransactionFromWebhook(...): void;
    protected function determineStatus(...): string;
}
```

**Key Features:**
- **Async Processing**: Webhooks processed in background
- **Retry Logic**: 3 attempts with 60-second backoff
- **Transaction Safety**: Uses database transactions with row locking
- **Status Normalization**: Uses StatusNormalizer service
- **Event Dispatching**: Fires WebhookReceived event

### 10. Events

#### WebhookReceived

Event fired when a webhook is processed:

```php
class WebhookReceived
{
    use Dispatchable, SerializesModels;
    
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
        public readonly ?string $reference = null
    ) {}
}
```

**Usage:**
```php
// Listen to provider-specific events
Event::listen('payments.webhook.paystack', function (WebhookReceived $event) {
    // Handle Paystack webhook
});

// Listen to all webhooks
Event::listen('payments.webhook', function (WebhookReceived $event) {
    // Handle any provider webhook
});
```

### 11. Enums

#### PaymentStatus

Type-safe payment status enum:

```php
enum PaymentStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';
    
    public function isSuccessful(): bool;
    public function isFailed(): bool;
    public function isPending(): bool;
    public static function tryFromString(string $value): ?self;
    public static function fromString(string $value): self;
    public static function isValid(string $value): bool;
}
```

#### PaymentChannel

Type-safe payment channel enum:

```php
enum PaymentChannel: string
{
    case CARD = 'card';
    case BANK_TRANSFER = 'bank_transfer';
    case USSD = 'ussd';
    case MOBILE_MONEY = 'mobile_money';
    case QR_CODE = 'qr_code';
    
    public function label(): string;
    public static function values(): array;
}
```

## Data Flow

### Charge Flow (Creating a Payment)

1. **User calls Payment API:**
   ```php
   Payment::amount(1000)->email('user@example.com')->redirect()
   ```

2. **Payment builds ChargeRequestDTO:**
   - Validates data (amount > 0, valid email, etc.)
   - Converts amount to minor units if needed
   - Merges default currency from config

3. **PaymentManager receives request:**
   - Gets provider list (from `with()` or config fallback chain)
   - For each provider:
     - Gets driver via DriverFactory
     - Checks health (cached)
     - Verifies currency support
     - Attempts charge
     - If successful: logs transaction and returns
     - If failed: tries next provider

4. **Driver processes charge:**
   - Formats request for provider API
   - Adds idempotency header if available
   - Maps channels using ChannelMapper
   - Makes HTTP request
   - Parses response
   - Returns ChargeResponseDTO

5. **Transaction logged:**
   - PaymentTransaction created (if logging enabled)
   - Session data cached (provider + provider ID)

6. **User redirected:**
   - If `redirect()`: redirects to authorization URL
   - If `charge()`: returns response object

### Verification Flow

1. **User calls verify:**
   ```php
   Payment::verify($reference) // Searches all providers
   // OR
   Payment::verify($reference, 'paystack') // Specific provider
   ```

2. **PaymentManager resolves verification context:**
   - **Cache Check**: Fastest, uses cached provider + ID
   - **Database Check**: If logging enabled, checks transaction table
   - **Heuristic**: Uses ProviderDetector to guess from reference
   - Calls driver's `resolveVerificationId()` to get correct ID

3. **PaymentManager searches providers:**
   - If provider specified: only checks that one
   - If not: checks all enabled providers
   - For each provider:
     - Gets driver
     - Calls `verify()` with resolved ID
     - If found: updates transaction and returns
     - If not found: tries next provider

4. **Transaction updated:**
   - Status updated in database
   - `paid_at` set if successful
   - Channel updated if available

5. **VerificationResponseDTO returned:**
   - Contains normalized status
   - Includes amount, currency, payment date, etc.

### Webhook Flow

1. **Provider sends webhook:**
   - POST to `/payments/webhook/{provider}`
   - Contains payment status, reference, etc.

2. **WebhookController receives:**
   - Validates request via WebhookRequest (FormRequest)
   - WebhookRequest calls driver's `validateWebhook()`
   - If valid: queues ProcessWebhook job
   - Returns 202 Accepted

3. **ProcessWebhook job executes:**
   - Extracts reference via driver's `extractWebhookReference()`
   - Determines status via driver's `extractWebhookStatus()` + StatusNormalizer
   - Updates transaction in database (with row locking)
   - Dispatches WebhookReceived event

4. **Event listeners handle webhook:**
   - Update order status
   - Send confirmation emails
   - Process business logic

## Design Patterns

### 1. Factory Pattern
**DriverFactory** creates driver instances without exposing creation logic.

### 2. Strategy Pattern
Each **Driver** is a strategy for processing payments. PaymentManager selects the appropriate strategy.

### 3. Facade Pattern
**Payment facade** provides simplified interface to complex subsystem.

### 4. DTO Pattern
**DTOs** ensure consistent data structures across providers.

### 5. Chain of Responsibility
**Fallback mechanism** tries providers in sequence until one succeeds.

### 6. Dependency Injection
**Interfaces** enable dependency injection and testability.

### 7. Singleton Pattern
**Driver instances** cached in PaymentManager to avoid recreation.

### 8. Template Method Pattern
**AbstractDriver** defines algorithm skeleton, concrete drivers implement steps.

## SOLID Principles

### Single Responsibility Principle (SRP)
- **StatusNormalizer**: Only normalizes statuses
- **ChannelMapper**: Only maps channels
- **ProviderDetector**: Only detects providers
- **DriverFactory**: Only creates drivers
- Each driver: Only handles one provider

### Open/Closed Principle (OCP)
- Add new providers without modifying core code
- Register custom drivers via DriverFactory
- Extend functionality via interfaces

### Liskov Substitution Principle (LSP)
- All drivers implement DriverInterface
- Can swap drivers without breaking code
- AbstractDriver provides common functionality

### Interface Segregation Principle (ISP)
- Small, focused interfaces
- StatusNormalizerInterface, ChannelMapperInterface, etc.
- Clients only depend on methods they use

### Dependency Inversion Principle (DIP)
- High-level modules depend on interfaces
- PaymentManager depends on DriverInterface, not concrete drivers
- Services injected via interfaces

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
    └── ProviderException (all providers failed)
```

Each exception:
- Can carry context via `setContext()`
- Preserves previous exception
- Provides user-friendly messages

## Configuration Management

**Config Singleton Pattern:**
- Config stored as singleton (`payments.config`)
- Prevents breaking Laravel's config caching
- Accessed via `app('payments.config')`

**Hierarchical Configuration:**
1. Package defaults (`config/payments.php`)
2. Environment variables (`.env`)
3. Runtime overrides

**Dynamic Configuration:**
- Table name from config
- Provider-specific settings
- Feature flags (logging, health checks, etc.)

## Security Features

- **Webhook Signatures**: HMAC validation for all providers
- **API Key Protection**: Never exposed in logs
- **HTTPS Enforcement**: Required (except testing mode)
- **Input Validation**: DTOs validate all input
- **SQL Injection Prevention**: Parameterized queries
- **XSS Prevention**: Proper output escaping
- **Rate Limiting**: Built-in webhook rate limiting

## Performance Optimizations

- **Driver Caching**: Drivers cached after first creation
- **Health Check Caching**: Configurable TTL (default: 5 minutes)
- **Session Caching**: Provider info cached for 1 hour
- **Lazy Loading**: Drivers created only when needed
- **HTTP Client Reuse**: Single client instance per driver
- **Database Transactions**: Efficient transaction handling
- **Queue Processing**: Webhooks processed asynchronously

## Extensibility

### Adding New Providers

1. **Create driver class:**
   ```php
   class MyProviderDriver extends AbstractDriver
   {
       protected string $name = 'myprovider';
       
       protected function validateConfig(): void { /* ... */ }
       protected function getDefaultHeaders(): array { /* ... */ }
       public function charge(ChargeRequestDTO $request): ChargeResponseDTO { /* ... */ }
       public function verify(string $reference): VerificationResponseDTO { /* ... */ }
       public function validateWebhook(array $headers, string $body): bool { /* ... */ }
       public function extractWebhookReference(array $payload): ?string { /* ... */ }
       public function extractWebhookStatus(array $payload): string { /* ... */ }
       public function extractWebhookChannel(array $payload): ?string { /* ... */ }
       public function resolveVerificationId(string $reference, string $providerId): string { /* ... */ }
   }
   ```

2. **Register driver:**
   ```php
   app(DriverFactory::class)->register('myprovider', MyProviderDriver::class);
   ```

3. **Add configuration:**
   ```php
   // config/payments.php
   'providers' => [
       'myprovider' => [
           'driver' => 'myprovider',
           'api_key' => env('MYPROVIDER_API_KEY'),
           // ... more config
       ],
   ],
   ```

**That's it!** No core code modification needed.

### Custom Services

You can replace any service by binding your own implementation:

```php
// In a service provider
app()->singleton(StatusNormalizerInterface::class, MyCustomNormalizer::class);
```

## Testing Strategy

- **Unit Tests**: Each component tested in isolation
- **Integration Tests**: Components tested together
- **Feature Tests**: End-to-end payment flows
- **Mocking**: External APIs mocked using Mockery
- **Test Coverage**: 90%+ coverage target
- **Pest PHP**: Modern testing framework

## Best Practices

1. **Always use interfaces** for dependency injection
2. **Validate input** in DTOs
3. **Cache expensive operations** (health checks, driver creation)
4. **Log errors** with context for debugging
5. **Use transactions** for database operations
6. **Handle exceptions** gracefully with fallbacks
7. **Test thoroughly** with mocks and real scenarios
8. **Document code** with clear docblocks

---

**For more details, see:**
- [API Reference](API_REFERENCE.md) - Complete API documentation
- [Contributing Guide](CONTRIBUTING.md) - How to add new providers
- [Testing Guide](TESTING.md) - Testing strategies and examples
