# Contributing to PayZephyr - Beginner's Guide

A step-by-step guide for novice developers who want to contribute to PayZephyr.

---

## 🎯 Who This Guide Is For

- ✅ Developers new to open source
- ✅ First-time contributors
- ✅ Developers who want to add features
- ✅ Anyone who wants to help improve PayZephyr

**Don't worry if you're a beginner!** This guide will walk you through everything step by step.

---

## 🤔 What Can I Contribute?

You don't need to be an expert to contribute! Here are ways you can help:

### For Complete Beginners

1. **📝 Documentation**
   - Fix typos
   - Improve explanations
   - Add examples
   - Translate documentation

2. **🐛 Bug Reports**
   - Report issues you find
   - Help reproduce bugs
   - Test bug fixes

3. **💡 Suggestions**
   - Suggest new features
   - Share use cases
   - Provide feedback

### For Developers

1. **🔧 Code Contributions**
   - Fix bugs
   - Add new features
   - Improve code quality
   - Add tests

2. **🏦 New Providers**
   - Add support for new payment providers
   - Improve existing provider implementations

---

## 🚀 Your First Contribution (Step-by-Step)

### Step 1: Set Up Your Development Environment

#### Install Prerequisites

1. **PHP 8.2+**
   ```bash
   php -v  # Check version
   ```

2. **Composer**
   ```bash
   composer --version
   ```

3. **Git**
   ```bash
   git --version
   ```

4. **A Code Editor**
   - VS Code (recommended)
   - PHPStorm
   - Any editor you're comfortable with

#### Fork the Repository

1. Go to [https://github.com/ken-de-nigerian/payzephyr](https://github.com/ken-de-nigerian/payzephyr)
2. Click the **"Fork"** button (top right)
3. This creates a copy in your GitHub account

#### Clone Your Fork

```bash
# Replace YOUR-USERNAME with your GitHub username
git clone https://github.com/YOUR-USERNAME/payzephyr.git
cd payzephyr
```

**What this does:** Downloads the code to your computer.

#### Install Dependencies

```bash
composer install
```

**What this does:** Downloads all required packages.

**Expected output:**
```
Loading composer repositories...
Installing dependencies...
...
```

### Step 2: Set Up Testing

```bash
# Copy test configuration
cp phpunit.xml.dist phpunit.xml

# Run tests to make sure everything works
composer test
```

**Expected output:**
```
PASS  Tests\Unit\PaymentTest
PASS  Tests\Unit\DriversTest
...
Tests:    150 passed
```

**✅ If tests pass, you're ready to contribute!**

### Step 3: Create a Branch

```bash
# Create a new branch for your changes
git checkout -b fix/typo-in-readme

# Or for a new feature
git checkout -b feature/add-example-code
```

**Branch naming:**
- `fix/` - For bug fixes
- `feature/` - For new features
- `docs/` - For documentation
- `test/` - For adding tests

**Example branch names:**
- `fix/typo-in-readme`
- `feature/add-square-provider`
- `docs/improve-installation-guide`
- `test/add-paystack-tests`

### Step 4: Make Your Changes

#### Example: Fix a Typo

1. Open `README.md`
2. Find a typo (e.g., "paymnet" → "payment")
3. Fix it
4. Save the file

#### Example: Add Documentation

1. Open `docs/DOCUMENTATION.md`
2. Find a section that needs more explanation
3. Add a helpful example
4. Save the file

#### Example: Fix a Bug

1. Find a bug (or pick one from GitHub Issues)
2. Understand what's wrong
3. Fix the code
4. Add a test to prevent it happening again
5. Run tests: `composer test`

### Step 5: Test Your Changes

```bash
# Run all tests
composer test

# Format your code
composer format

# Check code quality
composer analyse
```

**All checks should pass!** ✅

### Step 6: Commit Your Changes

```bash
# See what you changed
git status

# Add your changes
git add .

# Commit with a clear message
git commit -m "fix: correct typo in README"
```

**Good commit messages:**
- ✅ `fix: correct typo in README`
- ✅ `docs: add example for webhook handling`
- ✅ `feat: add support for Square provider`
- ✅ `test: add tests for PaystackDriver`

**Bad commit messages:**
- ❌ `fix stuff`
- ❌ `update`
- ❌ `changes`

### Step 7: Push to GitHub

```bash
# Push your branch to your fork
git push origin fix/typo-in-readme
```

**What this does:** Uploads your changes to your GitHub fork.

### Step 8: Create a Pull Request

1. Go to your fork on GitHub: `https://github.com/YOUR-USERNAME/payzephyr`
2. You'll see a banner: **"Compare & pull request"** - Click it
3. Fill out the PR form:
   - **Title:** Clear description (e.g., "Fix typo in README")
   - **Description:** Explain what you changed and why
4. Click **"Create Pull Request"**

**That's it!** 🎉 Your contribution is submitted!

---

## 📝 Pull Request Template

Use this template for your PR description:

```markdown
## Description
Brief description of what you changed.

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Documentation update
- [ ] Test addition

## Changes Made
- Change 1
- Change 2

## Testing
- [ ] Tests pass locally
- [ ] Code is formatted
- [ ] Static analysis passes

## Screenshots (if applicable)
Add screenshots for UI changes.

## Related Issues
Fixes #123
```

---

## 🏦 Adding a New Payment Provider (Advanced)

This is a great way to contribute if you're comfortable with PHP!

### Step 1: Understand the Structure

Look at an existing driver:
- `src/Drivers/PaystackDriver.php` - Good example to follow

### Step 2: Create Your Driver

Create `src/Drivers/SquareDriver.php`:

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;

class SquareDriver extends AbstractDriver
{
    protected string $name = 'square';

    protected function validateConfig(): void
    {
        if (empty($this->config['access_token'])) {
            throw new InvalidConfigurationException('Square access token is required');
        }
        if (empty($this->config['location_id'])) {
            throw new InvalidConfigurationException('Square location ID is required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config['access_token'],
            'Content-Type' => 'application/json',
            'Square-Version' => '2024-10-18',
        ];
    }

    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        // Implement Square's payment API
        // Look at PaystackDriver.php for reference
    }

    public function verify(string $reference): VerificationResponseDTO
    {
        // Implement Square's verification API
    }

    public function validateWebhook(array $headers, string $body): bool
    {
        // Implement Square's webhook validation
    }

    public function healthCheck(): bool
    {
        // Check if Square API is accessible
    }

    /**
     * Extract the transaction reference from Square's webhook payload.
     * Each provider structures webhooks differently - this method handles Square's format.
     */
    public function extractWebhookReference(array $payload): ?string
    {
        return $payload['data']['id'] ?? $payload['data']['object']['id'] ?? null;
    }

    /**
     * Extract the payment status from Square's webhook payload.
     * Returns the raw status - it will be normalized by StatusNormalizer.
     */
    public function extractWebhookStatus(array $payload): string
    {
        return $payload['data']['status'] ?? $payload['type'] ?? 'unknown';
    }

    /**
     * Extract the payment channel from Square's webhook payload.
     */
    public function extractWebhookChannel(array $payload): ?string
    {
        return $payload['data']['payment_method'] ?? null;
    }

    /**
     * Resolve the actual ID needed for verification.
     * Some providers use the reference directly, others need a different ID.
     */
    public function resolveVerificationId(string $reference, string $providerId): string
    {
        // Square uses the provider ID (payment ID) for verification
        return $providerId;
    }
}
```

**Important:** The four new methods (`extractWebhookReference`, `extractWebhookStatus`, `extractWebhookChannel`, and `resolveVerificationId`) are required by the `DriverInterface`. They allow the system to handle webhooks and verification without hardcoding provider-specific logic in the core classes.

### Step 3: Add Configuration

In `config/payments.php`, add:

```php
'square' => [
    'driver' => 'square',
    'access_token' => env('SQUARE_ACCESS_TOKEN'),
    'location_id' => env('SQUARE_LOCATION_ID'),
    'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
    'base_url' => env('SQUARE_BASE_URL', 'https://connect.squareup.com'),
    'currencies' => ['USD', 'CAD', 'GBP', 'AUD'],
    'enabled' => env('SQUARE_ENABLED', false),
],
```

### Step 4: Register the Driver

The `DriverFactory` will automatically pick it up from config, but you can also register it in `PaymentServiceProvider::boot()`:

```php
$factory = app(DriverFactory::class);
$factory->register('square', SquareDriver::class);
```

### Step 5: Write Tests

Create `tests/Unit/SquareDriverTest.php`:

```php
<?php

use KenDeNigerian\PayZephyr\Drivers\SquareDriver;

test('square driver initializes correctly', function () {
    $config = [
        'access_token' => 'test_token',
        'location_id' => 'test_location',
        'currencies' => ['USD'],
    ];

    $driver = new SquareDriver($config);

    expect($driver->getName())->toBe('square');
});

// Add more tests...
```

### Step 6: Update Documentation

Add Square to:
- `README.md` - Provider list
- `docs/providers.md` - Provider details
- `docs/DOCUMENTATION.md` - Configuration examples

---

## 🧪 Testing Guidelines

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/pest tests/Unit/PaystackDriverTest.php

# Run with coverage
composer test-coverage
```

### Writing Good Tests

```php
test('descriptive test name explains what is being tested', function () {
    // Arrange - Set up test data
    $config = ['api_key' => 'test'];
    
    // Act - Perform the action
    $driver = new ExampleDriver($config);
    
    // Assert - Check the result
    expect($driver->getName())->toBe('example');
});
```

**Test Checklist:**
- ✅ Test name describes what it tests
- ✅ Test one thing at a time
- ✅ Test both success and failure cases
- ✅ Test edge cases (empty values, null, etc.)

---

## 📋 Code Style

### Format Your Code

```bash
composer format
```

This automatically formats your code to match the project style.

### Check Code Quality

```bash
composer analyse
```

This checks for potential bugs and code quality issues.

### Manual Style Guide

1. **Use strict types:**
   ```php
   <?php
   declare(strict_types=1);
   ```

2. **Type hints everywhere:**
   ```php
   public function process(string $reference): bool
   ```

3. **Descriptive names:**
   ```php
   // ✅ Good
   $paymentReference = 'ref_123';
   
   // ❌ Bad
   $ref = 'ref_123';
   ```

4. **Document public methods:**
   ```php
   /**
    * Process a payment charge.
    *
    * @param ChargeRequestDTO $request Payment request details
    * @return ChargeResponseDTO Payment response
    * @throws ChargeException If charge fails
    */
   public function charge(ChargeRequestDTO $request): ChargeResponseDTO
   ```

---

## 🐛 Finding Issues to Fix

### Good First Issues

Look for issues labeled:
- `good first issue` - Perfect for beginners
- `documentation` - Easy to fix
- `bug` - Clear problems to solve
- `help wanted` - Community needs help

### How to Pick an Issue

1. Read the issue description carefully
2. Make sure you understand what's needed
3. Ask questions if unclear
4. Comment "I'll work on this" to claim it
5. Start working!

---

## 💬 Getting Help

### Before Asking

1. ✅ Read the documentation
2. ✅ Search existing issues
3. ✅ Check if someone already asked

### Where to Ask

- **GitHub Discussions** - For questions and ideas
- **GitHub Issues** - For bugs and feature requests
- **Email** - ken.de.nigerian@gmail.com

### How to Ask

**Good question:**
```markdown
I'm trying to add Square provider support. I've created the driver class
following the PaystackDriver example, but I'm getting an error when
testing the charge method. Here's my code: [code snippet] and the error:
[error message]. I've checked [what you tried]. Any suggestions?
```

**Bad question:**
```
It doesn't work. Help!
```

---

## ✅ Contribution Checklist

Before submitting your PR:

- [ ] Code follows style guidelines (`composer format`)
- [ ] All tests pass (`composer test`)
- [ ] Static analysis passes (`composer analyse`)
- [ ] Documentation is updated (if needed)
- [ ] Commit messages are clear
- [ ] PR description is complete
- [ ] Related issues are linked

---

## 🎉 Recognition

Contributors are recognized in:
- README.md contributors section
- GitHub contributors page
- Release notes

**Thank you for contributing!** Every contribution, no matter how small, makes PayZephyr better! 🚀

---

## 📚 Additional Resources

- [Full Contributing Guide](CONTRIBUTING.md) - Detailed technical guide
- [Architecture Guide](architecture.md) - Understand the codebase
- [Testing Guide](CONTRIBUTING.md#testing-guidelines) - Learn to write tests
- [Code Style Guide](CONTRIBUTING.md#coding-standards) - Detailed style rules

---

**Questions?** Don't hesitate to ask! We're here to help. 💪
