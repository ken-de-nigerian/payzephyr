# Contributing to PayZephyr

First off, thank you for considering contributing to PayZephyr! It's people like you that make PayZephyr such a great tool.

## 📋 Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing Guidelines](#testing-guidelines)
- [Pull Request Process](#pull-request-process)
- [Adding New Payment Providers](#adding-new-payment-providers)

---

## Code of Conduct

Our Code of Conduct governs this project and everyone participating in it.
By participating, you are expected to uphold this code.
Please report unacceptable behavior to ken.de.nigerian@gmail.com.

### Our Standards

**Examples of behavior that contributes to a positive environment:**
- Using welcoming and inclusive language
- Being respectful of differing viewpoints and experiences
- Gracefully accepting constructive criticism
- Focusing on what is best for the community
- Showing empathy towards other community members

**Examples of unacceptable behavior:**
- Trolling, insulting/derogatory comments, and personal or political attacks
- Public or private harassment
- Publishing others' private information without explicit permission
- Other conduct which could reasonably be considered inappropriate

---

## How Can I Contribute?

### 🐛 Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. When creating a bug report, include as many details as possible:

**Bug Report Template:**

```markdown
**Description**
A clear and concise description of the bug.

**Steps to Reproduce**
1. Go to '...'
2. Click on '...'
3. Scroll down to '...'
4. See error

**Expected Behavior**
What you expected to happen.

**Actual Behavior**
What actually happened.

**Environment**
- PHP Version: [e.g., 8.2.0]
- Laravel Version: [e.g., 11.0.0]
- Package Version: [e.g., 1.1.0]
- Provider: [e.g., Paystack, Stripe]

**Additional Context**
Add any other context, logs, or screenshots.

**Possible Solution**
(Optional) Suggest a fix or reason for the bug.
```

### 💡 Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

**Enhancement Request Template:**

```markdown
**Feature Description**
A clear and concise description of the feature.

**Use Case**
Describe the use case and why this feature would be useful.

**Proposed Solution**
How you envision this feature working.

**Alternatives Considered**
Any alternative solutions or features you've considered.

**Additional Context**
Add any other context, mockups, or examples.
```

### 📝 Improving Documentation

Documentation improvements are always welcome! This includes:
- Fixing typos or grammatical errors
- Adding code examples
- Clarifying confusing sections
- Adding missing documentation
- Translating documentation

---

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer
- Git
- A code editor (VS Code, PHPStorm recommended)

### Local Setup

1. **Fork the Repository**

   Click the "Fork" button on GitHub.

2. **Clone Your Fork**

   ```bash
   git clone https://github.com/YOUR-USERNAME/payzephyr.git
   cd payzephyr
   ```

3. **Install Dependencies**

   ```bash
   composer install
   ```

4. **Create a Branch**

   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/your-bug-fix
   ```

5. **Set Up Testing Environment**

   ```bash
   cp phpunit.xml.dist phpunit.xml
   ```

6. **Run Tests**

   ```bash
   composer test
   ```

### Development Workflow

```bash
# Create a feature branch
git checkout -b feature/add-new-provider

# Make your changes
# ... code, code, code ...

# Run tests
composer test

# Run static analysis
composer analyse

# Format code
composer format

# Commit changes
git add .
git commit -m "Add support for new payment provider"

# Push to your fork
git push origin feature/add-new-provider

# Create Pull Request on GitHub
```

---

## Coding Standards

### PHP Standards

We follow **PSR-12** coding standards with some additions:

#### File Structure

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use Exception;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;

/**
 * Class ExampleDriver
 *
 * Description of what this driver does
 */
class ExampleDriver extends AbstractDriver implements DriverInterface
{
    // Class implementation
}
```

#### Key Principles

1. **Strict Types**: Always use `declare(strict_types=1);`
2. **Type Hints**: Use type hints for all parameters and return types
3. **Readonly Properties**: Use `readonly` for immutable DTOs
4. **DocBlocks**: Document all public methods
5. **Single Responsibility**: Each class should have one clear purpose

#### Naming Conventions

```php
// Classes: PascalCase
class PaymentManager {}

// Methods: camelCase
public function chargeWithFallback() {}

// Constants: UPPER_SNAKE_CASE
const MAX_RETRY_ATTEMPTS = 3;

// Variables: camelCase
$paymentReference = 'ref_123';

// Private/Protected: camelCase with no prefix
private string $accessToken;

// Booleans: is/has/can prefix
private bool $isEnabled = true;
```

#### Method Organization

Order methods by visibility and purpose:

```php
class Example
{
    // 1. Constructor
    public function __construct() {}
    
    // 2. Public methods
    public function charge() {}
    public function verify() {}
    
    // 3. Protected methods
    protected function validateConfig() {}
    protected function makeRequest() {}
    
    // 4. Private methods
    private function normalizeStatus() {}
    private function extractReference() {}
}
```

### Code Quality Tools

```bash
# Laravel Pint - Code formatting
composer format

# PHPStan - Static analysis
composer analyse

# Pest - Testing
composer test

# All checks
composer format && composer analyse && composer test
```

---

## Testing Guidelines

### Test Structure

We use **Pest PHP** for testing. Tests should be descriptive and follow this structure:

```php
<?php

use KenDeNigerian\PayZephyr\Drivers\ExampleDriver;

// Descriptive test names
test('driver validates configuration on construction', function () {
    // Arrange
    $config = ['api_key' => 'test'];
    
    // Act
    $driver = new ExampleDriver($config);
    
    // Assert
    expect($driver->getName())->toBe('example');
});

// Test exceptions
test('driver throws exception for missing api key', function () {
    new ExampleDriver([]);
})->throws(InvalidConfigurationException::class);

// Test with beforeEach
beforeEach(function () {
    $this->driver = new ExampleDriver(['api_key' => 'test']);
});

test('driver can check currency support', function () {
    expect($this->driver->isCurrencySupported('USD'))->toBeTrue();
});
```

### Test Categories

1. **Unit Tests** (`tests/Unit/`)
    - Test individual classes/methods in isolation
    - Mock external dependencies
    - Fast execution

2. **Feature Tests** (`tests/Feature/`)
    - Test complete workflows
    - Test facades and helpers
    - Integration between components

3. **Integration Tests**
    - Test actual API calls (with mocking)
    - Test webhook handling
    - Test database operations

### Writing Good Tests

✅ **DO:**
- Use descriptive test names
- Test one thing per test
- Use meaningful assertions
- Mock external services
- Test edge cases
- Test error conditions

❌ **DON'T:**
- Test implementation details
- Have tests depend on each other
- Use actual API calls (except in sandbox tests)
- Leave commented-out code
- Skip writing tests

### Test Coverage

Aim for **80%+ code coverage**:

```bash
# Run with coverage report
composer test-coverage

# View coverage
open build/coverage/index.html
```

### Example Test for New Driver

```php
<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\NewProviderDriver;

test('new provider driver initializes correctly', function () {
    $config = [
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'base_url' => 'https://api.newprovider.com',
        'currencies' => ['USD', 'EUR'],
    ];

    $driver = new NewProviderDriver($config);

    expect($driver->getName())->toBe('new_provider')
        ->and($driver->getSupportedCurrencies())->toBe(['USD', 'EUR'])
        ->and($driver->isCurrencySupported('USD'))->toBeTrue();
});

test('new provider charge succeeds with valid request', function () {
    // Mock HTTP responses
    $driver = createMockDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'reference' => 'ref_123',
                'checkout_url' => 'https://checkout.newprovider.com/pay',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('ref_123')
        ->and($response->authorizationUrl)->toContain('checkout.newprovider.com')
        ->and($response->status)->toBe('pending');
});

test('new provider validates webhook signature', function () {
    $config = ['api_key' => 'test', 'secret_key' => 'secret'];
    $driver = new NewProviderDriver($config);

    $body = '{"event":"payment.success"}';
    $signature = hash_hmac('sha256', $body, 'secret');

    $headers = ['X-Provider-Signature' => [$signature]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});
```

---

## Pull Request Process

### Before Submitting

✅ **Checklist:**
- [ ] Tests pass (`composer test`)
- [ ] Code is formatted (`composer format`)
- [ ] Static analysis passes (`composer analyse`)
- [ ] Documentation is updated (if needed)
- [ ] CHANGELOG.md is updated
- [ ] Commit messages are clear
- [ ] Branch is up to date with `main`

### PR Title Format

Use conventional commits formats:

```
feat: Add support for Square payment provider
fix: Correct webhook signature validation for Stripe
docs: Update installation instructions
test: Add tests for Flutterwave driver
refactor: Simplify PaymentManager fallback logic
perf: Improve health check caching
chore: Update dependencies
```

### PR Description Template

```markdown
## Description
Brief description of changes.

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Changes Made
- Change 1
- Change 2
- Change 3

## Testing
Describe testing performed:
- Unit tests added/updated
- Manual testing steps
- Edge cases covered

## Screenshots (if applicable)
Add screenshots for UI changes.

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex code
- [ ] Documentation updated
- [ ] Tests added/updated
- [ ] All tests passing
- [ ] CHANGELOG.md updated

## Related Issues
Fixes #123
Closes #456
```

### Review Process

1. **Automated Checks**: CI will run tests and code quality checks
2. **Code Review**: Maintainer will review code
3. **Feedback**: Address any requested changes
4. **Approval**: Once approved, maintainer will merge
5. **Release**: Changes will be included in next release

---

## Adding New Payment Providers

### Step 1: Create Driver Class

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;

class NewProviderDriver extends AbstractDriver implements DriverInterface
{
    protected string $name = 'newprovider';

    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new InvalidConfigurationException('API key is required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'Content-Type' => 'application/json',
        ];
    }

    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        // Implementation
    }

    public function verify(string $reference): VerificationResponseDTO
    {
        // Implementation
    }

    public function validateWebhook(array $headers, string $body): bool
    {
        // Implementation
    }

    public function healthCheck(): bool
    {
        // Implementation
    }
}
```

### Step 2: Add Configuration

In `config/payments.php`:

```php
'providers' => [
    // ... existing providers
    
    'newprovider' => [
        'driver' => 'newprovider',
        'api_key' => env('NEWPROVIDER_API_KEY'),
        'secret_key' => env('NEWPROVIDER_SECRET_KEY'),
        'base_url' => env('NEWPROVIDER_BASE_URL', 'https://api.newprovider.com'),
        'currencies' => ['USD', 'EUR', 'GBP'],
        'enabled' => env('NEWPROVIDER_ENABLED', false),
    ],
],
```

### Step 3: Register in PaymentManager

In `src/PaymentManager.php`:

```php
protected function resolveDriverClass(string $driver): string
{
    $map = [
        'paystack' => PaystackDriver::class,
        // ... other drivers
        'newprovider' => NewProviderDriver::class,
    ];

    return $map[$driver] ?? $driver;
}
```

### Step 4: Write Tests

Create `tests/Unit/NewProviderDriverTest.php`:

```php
<?php

use KenDeNigerian\PayZephyr\Drivers\NewProviderDriver;

test('new provider driver initializes', function () {
    // Test implementation
});

test('new provider charge works', function () {
    // Test implementation
});

test('new provider verify works', function () {
    // Test implementation
});
```

### Step 5: Document

Add to README.md:
- Provider features
- Configuration example
- Usage example
- Webhook setup

---

## Style Guide

### Error Messages

✅ Good:
```php
throw new InvalidConfigurationException('Stripe secret key is required');
```

❌ Bad:
```php
throw new Exception('Error!');
```

### Comments

```php
// ✅ Good: Explains WHY
// Convert to kobo (Paystack uses minor units)
$amountInKobo = $request->amount * 100;

// ❌ Bad: Explains WHAT (obvious)
// Multiply amount by 100
$amountInKobo = $request->amount * 100;
```

### Logging

```php
// ✅ Good: Structured logging
$this->log('error', 'Payment charge failed', [
    'provider' => $this->getName(),
    'reference' => $reference,
    'error' => $e->getMessage(),
]);

// ❌ Bad: Unstructured logging
logger('Error: ' . $e->getMessage());
```

---

## Questions?

- 📧 Email: ken.de.nigerian@gmail.com
- 💬 Discussions: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 🐛 Issues: [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues)

---

## Recognition

Contributors will be added to:
- README.md contributors section
- GitHub contributors page
- Release notes

---

**Thank you for contributing to PayZephyr! 🎉**