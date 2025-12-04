# Changelog

All notable changes to `payzephyr` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-04

### Added
- Initial release
- Support for Paystack payment provider
- Support for Flutterwave payment provider  
- Support for Monnify payment provider
- Support for Stripe payment provider
- Support for PayPal payment provider
- Fluent API for payment operations
- Automatic fallback between providers
- Webhook signature verification for all providers
- Health check system for provider availability
- Multi-currency support with automatic conversion
- Transaction logging to database
- Comprehensive test suite with Pest PHP
- Full documentation and examples
- Laravel 10 and 11 support
- PHP 8.2+ support

### Features
- `Payment::amount()->email()->redirect()` - Simple payment initialization
- `Payment::with(['provider1', 'provider2'])` - Provider fallback chain
- `Payment::verify($reference)` - Payment verification across all providers
- Automatic webhook route registration
- Event dispatching for webhooks
- Configurable health checks with caching
- PSR-4 autoloading
- Laravel auto-discovery
- Publishable config and migrations

[1.0.0]: https://github.com/ken-de-nigerian/payzephyr/releases/tag/v1.0.0
