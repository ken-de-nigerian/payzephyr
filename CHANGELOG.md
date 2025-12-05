# Changelog

All notable changes to `payzephyr` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.2] - 2025-12-05

### 🐛 Fixed
- **Rounding Precision Handling**:
  - ChargeRequest now automatically rounds amounts to two decimal places
  - Prevents validation exceptions on high-precision inputs (e.g., 100.999)
  - Ensures consistent monetary formatting across all providers

- **Webhook Error Status Codes**:
  - WebhookController now returns HTTP 500 on internal errors
  - Previously returned HTTP 200 even on failures
  - Ensures payment providers trigger automatic retries
  - Improves webhook reliability and event processing

### Added
- Initial release preparation
- Comprehensive test coverage

---

## [1.0.1] - 2025-12-04

### 🚀 Added
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

### 🔒 Security
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

### 🐛 Fixed
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

### 🗑️ Removed
- **Unused Dependencies**:
  - Removed `moneyphp/money` from composer.json
  - Removed unused `CurrencyConverterInterface` contract
  - Cleaned up unused exception classes
  - Reduced package size and complexity

### 📝 Changed
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

### 📚 Documentation
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

### ⚠️ Breaking Changes
None - This release is fully backward compatible.

### 🔄 Migration Guide
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

### From 1.0.x to 1.1.0

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

- 📧 Email: ken.de.nigerian@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)

---

## Links

- [Documentation](https://github.com/ken-de-nigerian/payzephyr/wiki)
- [Contributing Guide](CONTRIBUTING.md)
- [License](LICENSE)

---

**Legend**:
- 🚀 Added—New features
- 🔒 Security—Security improvements
- 🐛 Fixed - Bug fixes
- 🗑️ Removed—Removed features
- 📝 Changed—Changes to existing features
- 📚 Documentation - Documentation updates
- ⚠️ Breaking - Breaking changes
- 🔄 Migration—Migration required