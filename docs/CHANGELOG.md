# Changelog

All notable changes to `payzephyr` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---
## [1.1.9] - 2025-12-11

### Fixed
- **PaystackDriver Health Check**: Fixed incorrect interpretation of 400 Bad Request responses
  - A 400 Bad Request from Paystack when checking `/transaction/verify/invalid_ref_test` now correctly indicates the API is working
  - The health check now properly traverses the exception chain to find `ClientException` with 400/404 status codes
  - Previously, the health check incorrectly returned `false` for expected 400 responses
  - **Impact**: Paystack health checks now correctly report API availability

### Improved
- **Exception Chain Traversal**: Improved exception handling in `PaystackDriver::healthCheck()` to properly traverse exception chains
  - More robust detection of `ClientException` within wrapped exceptions
  - Better logging with exception class information for debugging

### Tests
- Updated `PaystackDriverCoverageTest` to correctly expect `true` for 400 ClientException responses
- All 716 tests passing

---
## [1.1.8] - 2025-12-11

### Added
- **Application-Originating Payment Events**: New events for payment lifecycle hooks
  - `PaymentInitiated`: Dispatched after successful `charge()` operation
    - Provides clean hooks for business logic (e.g., sending email confirmations, updating inventory)
    - Event contains `ChargeRequestDTO`, `ChargeResponseDTO`, and provider name
  - `PaymentVerificationSuccess`: Dispatched after successful verification with success status
    - Triggered when payment verification results in a successful state
    - Event contains reference, `VerificationResponseDTO`, and provider name
  - `PaymentVerificationFailed`: Dispatched after successful verification with failed status
    - Triggered when payment verification results in a failed state
    - Event contains reference, `VerificationResponseDTO`, and provider name

### Changed
- **Centralized Idempotency Key Generation**: Idempotency keys are now automatically generated
  - `ChargeRequestDTO::fromArray()` now automatically generates a UUID v4 idempotency key if not provided
  - Ensures every payment request always has a unique idempotency key
  - Uses Laravel's `Str::uuid()` for consistent UUID v4 format
  - Removed manual idempotency key generation from `SquareDriver` (now handled centrally)
  - **Benefit**: Simplifies driver logic and ensures consistent key formatting across all providers

### Improved
- **PaymentManager Cache Cleanup**: Explicit cache deletion after successful verification
  - Cache entries are now explicitly deleted after successful verification instead of relying solely on expiration
  - Reduces unnecessary data accumulation in cache for already-verified payments
  - Improves cache efficiency and reduces memory usage

### Documentation
- Updated idempotency key documentation to reflect automatic generation
- Added documentation for new payment events
- Updated examples to show that idempotency keys are optional (auto-generated if not provided)

### Tests
- All 716 tests passing (1,447 assertions)
- Verified backward compatibility with existing idempotency key usage
- All events properly dispatched and testable

---
## [1.1.7] - 2025-12-11

### Changed
- **Convention over Configuration**: Refactored core services to eliminate hardcoded provider lists
  - **DriverFactory**: Now uses Convention over Configuration to automatically resolve driver classes
    - Converts provider name to `{Provider}Driver` class name (e.g., `'paystack'` → `PaystackDriver`)
    - Handles special cases (e.g., `'paypal'` → `PayPalDriver`)
    - No longer requires hardcoded provider-to-class mappings
    - Maintains backward compatibility with registered drivers and config `driver_class` settings
  - **ProviderDetector**: Dynamically builds prefix list from all providers in configuration
    - Automatically loads prefixes from `config('payments.providers')`
    - Uses `reference_prefix` from config if set, otherwise defaults to `UPPERCASE(provider_name)`
    - Loads all providers (not just enabled ones) for detection purposes
    - Supports custom prefixes via `reference_prefix` config option
  - **ChannelMapper**: Uses dynamic method checking instead of hardcoded provider list
    - Automatically calls `mapTo{Provider}()` methods based on provider name
    - No hardcoded provider checks required
    - Easier to extend with new provider mappings

### Improved
- **Maintainability**: Adding new providers no longer requires updating multiple hardcoded lists
- **Extensibility**: New providers automatically work if they follow naming conventions
- **Code Quality**: Reduced code duplication and improved adherence to DRY principles

### Configuration
- Added `reference_prefix` configuration option for providers that need custom prefixes:
  - Flutterwave: `'reference_prefix' => 'FLW'` (instead of default `'FLUTTERWAVE'`)
  - Monnify: `'reference_prefix' => 'MON'` (instead of default `'MONNIFY'`)

### Documentation
- Updated `docs/architecture.md` to reflect Convention over Configuration approach
- Documented dynamic prefix loading in ProviderDetector
- Documented Convention-based driver resolution in DriverFactory
- Documented dynamic method checking in ChannelMapper

### Tests
- All 716 tests passing
- Updated ProviderDetector tests to set up providers with correct `reference_prefix` values
- Verified backward compatibility with existing functionality

---
## [1.1.6] - 2025-12-11

### Added
- **Install Command**: New `payzephyr:install` artisan command for streamlined package setup
  - Automatically publishes configuration file
  - Publishes migration files
  - Optionally runs migrations with user confirmation
  - Displays setup instructions and example environment variables
  - Supports `--force` flag to overwrite existing files

### Changed
- **Documentation**: Updated installation instructions across all documentation files
  - README.md now uses `payzephyr:install` as the primary installation method
  - GETTING_STARTED.md updated with new install command workflow
  - DOCUMENTATION.md updated to reflect simplified installation process
  - Manual installation steps retained as alternative option for advanced users

### Improved
- **Developer Experience**: Simplified package installation from 3 manual steps to 1 command
  - Reduces setup time and potential for errors
  - Provides better onboarding experience for new users
  - Maintains backward compatibility with manual setup option

### Documentation
- Updated all installation guides to feature `payzephyr:install` command
- Added clear examples and expected output for install command
- Documented `--force` flag usage for overwriting existing files
- Maintained comprehensive documentation for manual setup alternative

---
## [1.1.5] - 2025-12-10

### Added
- **OPay Driver**: New payment driver with dual authentication support
  - Create Payment API: Bearer token authentication using Public Key
  - Status API: HMAC-SHA512 signature authentication using Private Key (Secret Key) and Merchant ID
  - Support for card payments, bank transfer, USSD, and mobile money
  - Comprehensive test coverage with integration and coverage tests

### Changed
- **OPay Driver**: Improved authentication implementation
  - Implemented HMAC-SHA512 signature generation for status API
  - Signature uses private key (secret_key) concatenated with merchant ID
  - Maintains backward compatibility for create payment API
  - Updated documentation to reflect dual authentication requirements

### Documentation
- Added comprehensive OPay driver documentation with authentication details
- Updated README and provider docs with new authentication requirements
- Clarified secret_key requirement for OPay status API

### Tests
- Added comprehensive test coverage for OPayDriver
- Fixed OPayDriverIntegrationTest to include secret_key in config
- All tests passing (700+ tests)

## [1.1.4] - 2025-12-09

### Fixed
- **Square Driver**: Fixed payment verification flow and improved code quality
  - Added missing `location_ids` parameter to order search API request (fixes "Must provide at least 1 location_id" error)
  - Fixed verification to handle `payment_link_id` (providerId) in addition to `reference_id`
  - Added payment link lookup as a verification strategy before order search fallback
  - Verification now supports three strategies: payment ID → payment link ID → reference ID order search

### Changed
- **Square Driver**: Refactored `verify()` method for better maintainability
  - Extracted verification logic into focused helper methods:
    - `verifyByPaymentId()` - handles direct payment ID lookup
    - `verifyByPaymentLinkId()` - handles payment link ID lookup
    - `verifyByReferenceId()` - handles reference ID order search
    - `searchOrders()` - encapsulates order search API call
    - `getOrderById()` - retrieves order by ID
    - `getPaymentFromOrder()` - extracts payment ID from order tenders
    - `getPaymentDetails()` - retrieves payment details by ID
  - Reduced main `verify()` method from ~135 lines to ~27 lines
  - Eliminated code duplication and improved testability
  - All 659 tests passing (1,336 assertions)

## [1.1.3] - 2025-12-09

### Changed
- **Core Classes**: Marked all core classes as `final` for better OCP compliance
  - All driver classes (PayPalDriver, StripeDriver, SquareDriver, PaystackDriver, FlutterwaveDriver, MonnifyDriver)
  - Core service classes (PaymentManager, DriverFactory, StatusNormalizer, ProviderDetector, ChannelMapper)
  - Controller and model classes (WebhookController, PaymentTransaction, Payment, PaymentServiceProvider)
  - All exception classes
  - This prevents inheritance and enforces composition, improving code maintainability

### Fixed
- **Square Driver**: Updated API version and cleaned up logging
  - Updated Square API version from `2024-01-18` to `2024-10-18`
  - Removed debug logging added for troubleshooting 401 authentication errors
  - Cleaned up unnecessary logs while maintaining essential operational logging
  - Updated SquareDriverCoverageTest to reflect new API version

- **Tests**: Refactored all test files to work with final classes
  - Replaced partial mocks of final driver classes with real instances and HTTP client mocking via `setClient()` method
  - Updated PaymentManager tests to use real instances with reflection-based driver injection into internal cache
  - Replaced DriverFactory mocks with direct driver injection into PaymentManager
  - Fixed status normalizer expectations in WebhookControllerCoverageTest to match actual driver behavior
  - Updated PayPalDriverWebhookTest to properly mock StreamInterface for HTTP response bodies
  - All 659 tests now pass successfully (1,336 assertions)

### Technical Details
- Tests now use composition (injecting mocks via public setters/reflection) instead of inheritance
- PaymentManager tests inject mock drivers directly into the internal `$drivers` cache using reflection
- Driver tests mock HTTP clients instead of extending final driver classes
- Maintains full test coverage while respecting final class constraints (OCP compliance)
- Improved test isolation by using real instances where possible

## [1.1.2] - 2025-12-09

### Feature

- Integrated Square driver providing:
- Comprehensive test coverage (41 tests, 68 assertions)
- Complete documentation updates across all docs
- Full integration with existing test suites
- Verification of all OCP methods (extractWebhookReference, extractWebhookStatus, extractWebhookChannel, resolveVerificationId)
- The Square driver is now fully tested, documented, and ready for production use.


## [1.0.9] - 2025-12-08

### Fixed

- **Stripe Webhook Validation**: Enhanced webhook signature validation with improved error messages and troubleshooting hints. Fixed validation failures by ensuring proper webhook secret configuration.
- **Flutterwave Webhook Validation**: Improved webhook validation with better error handling and logging. Added support for `FLUTTERWAVE_WEBHOOK_SECRET` configuration option.
- **SQLite Database Locks**: Increased webhook throttle limit from 60 to 120 requests per minute to reduce concurrent database lock issues when using SQLite cache driver. Added documentation note recommending `file` or `array` cache drivers for webhook routes.

### Improved

- **Webhook Error Messages**: Enhanced error messages for both Stripe and Flutterwave webhook validation failures with specific troubleshooting hints and configuration guidance.
- **Configuration**: Added `webhook_secret` option to Flutterwave configuration for dedicated webhook secret management (falls back to `secret_key` for backward compatibility).

### Changed

- **Webhook Throttling**: Increased throttle limit for webhook routes from 60 to 120 requests per minute to better handle concurrent webhook deliveries from payment providers.

---
## [1.0.8] - 2025-12-08

### Refactor

- **Moved provider-specific logic to drivers**: All webhook data extraction and verification ID resolution logic is now encapsulated in individual driver classes.
- **Eliminated hardcoded match statements**: `WebhookController` and `PaymentManager` no longer contain provider-specific `match ($provider)` statements.
- **New driver methods**: Added four new methods to `DriverInterface`:
  - `extractWebhookReference()` - Extract payment reference from webhook payload
  - `extractWebhookStatus()` - Extract payment status from webhook payload
  - `extractWebhookChannel()` - Extract payment channel from webhook payload
  - `resolveVerificationId()` - Resolve the ID needed for payment verification
- **Benefits**:
  - Adding new providers no longer requires modifying core classes
  - Each driver encapsulates its own data extraction logic
  - Follows SOLID principles (Open/Closed Principle)
  - Easier to test and maintain


## [1.0.7] - 2025-12-07

### Fixed

- Implement cache-first verification to support Unified API without DB logging
- PaymentManager: Now caches 'CustomRef ⇒ ProviderID' mapping for 1 hour during charge().
- PaymentManager: verify() uses Cache → DB → Prefix logic to find the correct Provider and ID.
- StripeDriver: Added support for verification via Checkout Session ID (cs_).
- MonnifyDriver: Fixed verification failure caused by query parameters in reference string.

## [1.0.6] - 2025-12-07

### Fixed

- StripeDriver charge() must use config callbackUrl as fallback to prevent empty success_url error when using →charge().

## [1.0.5] - 2025-12-07

### Fixed

- Implement cache-based provider resolution for verify()
- Ensures fast verification for custom references even if database logging is disabled.
- Resolution Priority: Explicit → Cache → Database → Prefix → Fallback Loop.

## [1.0.4] - 2025-12-07

### Fixed
- Standardize callback URL query parameters across all drivers
- AbstractDriver: Added appendQueryParam helper for safe URL construction.
- Drivers (Flutterwave, Monnify, PayPal, Stripe): Updated charge methods to explicitly append the 'reference' query parameter to the callback URL.
- This ensures a unified developer experience where Payment::verify(\$request→reference) works consistently for all providers.

## [1.0.3] - 2025-12-07

### Changed
- **PayPal:** Updated the default checkout flow to use `landing_page => GUEST_CHECKOUT`. This ensures users see the "Pay with Debit/Credit Card" option immediately instead of being forced to log in, significantly improving conversion rates.

## [1.0.2] - 2025-12-07

### Fixed
- **Flutterwave:** Fixed `404 Not Found` error caused by incorrect URL path resolution. Removed leading slashes in `FlutterwaveDriver` methods to ensure endpoints correctly append to the configured versioned base URL (`/v3/`).
- **PayPal:** Fixed `422 Unprocessable Entity` error by refactoring the payload to use the modern `experience_context` structure instead of the deprecated `application_context`.
- **PayPal:** Fixed "Cannot redirect to an empty URL" crash. The driver now correctly identifies the `payer-action` link type returned by the API v2, which replaced the previous `approve` link type.
- **Monnify:** Fixed a syntax error (missing comma) in the published `config/payments.php` file that caused application crashes during boot.

### Documentation
- **Monnify:** Added inline documentation in the configuration file to clarify the correct Base URLs for Sandbox (`https://sandbox.monnify.com`) vs. Live (`https://api.monnify.com`) environments.

## [1.0.1] - 2025-12-04

### Added
- **PaymentTransaction Model**: Full Eloquent model for transaction management
  - Mass assignment protection with explicit `$fillable` array
  - Convenient scopes: `successful()`, `failed()`, `pending()`
  - Helper methods: `isSuccessful()`, `isFailed()`, `isPending()`
  - Automatic JSON casting for metadata and customer fields
  - Configurable table name via config

- **Automatic Transaction Logging**:
  - All charges automatically logged to database on initialization
  - Webhook events automatically update transaction status
  - Verification events update transaction records
  - Graceful fallback if database logging fails

- **PayPal Zero-Decimal Currency Support**:
  - Intelligent currency precision detection
  - Supports 16 zero-decimal currencies (JPY, KRW, etc.)
  - Automatic formatting based on currency type

- **Enhanced Security Audit Documentation**:
  - Comprehensive security review document
  - Production deployment checklist
  - Incident response guidelines
  - GDPR and PCI-DSS compliance notes

- **Rounding Precision Handling**:
  - ChargeRequest now automatically rounds amounts to two decimal places
  - Prevents validation exceptions on high-precision inputs (e.g., 100.999)
  - Ensures consistent monetary formatting across all providers

- **Webhook Error Status Codes**:
  - WebhookController now returns HTTP 500 on internal errors
  - Previously returned HTTP 200 even on failures
  - Ensures payment providers trigger automatic retries
  - Improves webhook reliability and event processing

### Security
- **CRITICAL: Webhook Signature Validation Fix**
  - Fixed webhook signature bypass vulnerability
  - Now uses raw request body for signature verification
  - Prevents forged webhook attacks
  - **Impact**: HIGH - All users should update immediately

- **Input Validation Enhancements**:
  - Added maximum amount validation (999,999,999.99)
  - Strict decimal precision validation (max 2 places)
  - Protected against floating-point overflow
  - Enhanced email validation

- **Mass Assignment Protection**:
  - PaymentTransaction model properly guarded
  - Only necessary fields are marked as fillable
  - Prevents unauthorized field modification

### Fixed
- **Floating-Point Precision Issues**:
  - Improved `getAmountInMinorUnits()` with proper rounding
  - Uses `PHP_ROUND_HALF_UP` for consistent banker's rounding
  - Added validation for unreasonable decimal precision
  - Documented monetary value handling best practices

- **Stripe Driver** (Already Correct):
  - Confirmed Checkout Sessions implementation
  - Proper URL generation for `redirect()` method
  - No changes needed - working as intended

- **Database Migration Usage**:
  - Migration is now actively used by transaction logging
  - Webhook controller updates records automatically
  - Verification updates records on success

### Removed
- **Unused Dependencies**:
  - Removed `moneyphp/money` from composer.json
  - Removed unused `CurrencyConverterInterface` contract
  - Cleaned up unused exception classes
  - Reduced package size and complexity

### Changed
- **WebhookController**:
  - Now uses raw request body for signature validation
  - Extracts reference intelligently per provider
  - Updates transaction status automatically
  - Normalizes status across all providers
  - Enhanced error logging with context

- **PaymentManager**:
  - Added `logTransaction()` method for database logging
  - Added `updateTransactionFromVerification()` method
  - Improved error handling with context
  - Better exception aggregation on failure

- **ChargeRequest**:
  - Enhanced validation with security in mind
  - Better error messages for invalid inputs
  - Documented floating-point handling
  - Added overflow protection

### Documentation
- **New README.md**:
  - Professional formatting with badges
  - Comprehensive usage examples
  - Webhook setup guide with code samples
  - Security best practices section
  - API reference
  - Contributing guidelines

- **New SECURITY_AUDIT.md**:
  - Complete security review findings
  - Production deployment checklist
  - Monitoring and logging recommendations
  - Compliance notes (PCI-DSS, GDPR)
  - Incident response procedures

### Breaking Changes
None - This release is fully backward compatible.

### Migration Guide
No migration needed. Simply update via composer:

```bash
composer update kendenigerian/payzephyr
php artisan migrate  # Run new migration if not already run
```

---

## [1.0.0] - 2025-12-04

### 🎉 Initial Release

#### Added
- **Multi-Provider Support**:
  - Paystack integration
  - Flutterwave integration
  - Monnify integration
  - Stripe integration
  - PayPal integration

- **Core Features**:
  - Fluent payment API with chainable methods
  - Automatic provider fallback
  - Health check system with caching
  - Webhook signature verification
  - Currency support validation
  - Transaction reference generation

- **Developer Experience**:
  - Facade support (`Payment::charge()`)
  - Helper function (`payment()->charge()`)
  - Clean exception hierarchy
  - Comprehensive test suite (Pest PHP)
  - PSR-4 autoloading
  - Laravel auto-discovery

- **Configuration**:
  - Environment-based configuration
  - Per-provider settings
  - Webhook path customization
  - Health check configuration
  - Logging options

- **Data Transfer Objects**:
  - `ChargeRequest` - Standardized payment request
  - `ChargeResponse` - Standardized charge response
  - `VerificationResponse` - Standardized verification

- **Driver Architecture**:
  - `AbstractDriver` base class
  - `DriverInterface` contract
  - Individual driver implementations
  - HTTP client abstraction
  - Automatic header management

- **Testing**:
  - 70+ comprehensive tests
  - Unit tests for all drivers
  - Integration tests for workflows
  - Feature tests for facades
  - Mock support for external APIs

- **Documentation**:
  - Installation guide
  - Configuration examples
  - Usage documentation
  - Provider-specific guides
  - Webhook setup instructions

#### Provider-Specific Features

**Paystack**:
- Support for NGN, GHS, ZAR, USD
- Bank transfer support
- USSD payment support
- Custom channels selection
- Split payment configuration

**Flutterwave**:
- Support for 7+ currencies
- Mobile money integration
- Card payment support
- Customizable payment page

**Monnify**:
- Nigerian Naira (NGN) support
- Dynamic account generation
- Bank transfer support
- OAuth2 authentication

**Stripe**:
- Support for 135+ currencies
- Checkout Sessions
- Payment Intents API
- Apple Pay / Google Pay ready
- SCA compliance

**PayPal**:
- Support for major currencies
- PayPal balance payments
- Credit card via PayPal
- Sandbox mode support

---

## Release Schedule

- **Major versions** (x.0.0): Breaking changes, new architecture
- **Minor versions** (1.x.0): New features, backward compatible
- **Patch versions** (1.0.x): Bug fixes, security patches

---

## Upgrade Guide

### From 1.0.x to 1.0.9

**No breaking changes** - Simply update:

```bash
composer update kendenigerian/payzephyr
```

**New features available**:
1. Transaction logging - run migration:
   ```bash
   php artisan migrate
   ```

2. Query transactions:
   ```php
   use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
   
   $transactions = PaymentTransaction::successful()->get();
   ```

3. Enhanced security—ensure webhook verification is enabled:
   ```env
   PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
   ```

---

## Support

- 📧 Email: ken.de.nigerian@payzephyr.dev
- 💬 Discussions: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)

---

## Links

- [Documentation](https://github.com/ken-de-nigerian/payzephyr/wiki)
- [Contributing Guide](CONTRIBUTING.md)
- [License](/LICENSE)