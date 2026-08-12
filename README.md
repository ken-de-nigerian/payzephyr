# PayZephyr

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Total Downloads](https://img.shields.io/packagist/dt/kendenigerian/payzephyr.svg?style=flat-square)](https://packagist.org/packages/kendenigerian/payzephyr)
[![Tests](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml/badge.svg)](https://github.com/ken-de-nigerian/payzephyr/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## What is PayZephyr?

If you've ever had to accept payments in a Laravel app, you've probably run into this problem: every payment provider (Stripe, PayPal, Paystack, and the rest) has its own SDK, its own way of creating a charge, its own webhook format, and its own quirks. Wire your app up to one provider, and you're locked into rewriting a chunk of it if you ever need to add a second, or switch.

PayZephyr solves that by giving you **one API that works the same way no matter which provider is behind it.** You write:

```php
Payment::amount(100.00)->email('customer@example.com')->redirect();
```

and PayZephyr handles the fact that, underneath, this might be talking to Paystack today and Stripe tomorrow. You don't write provider-specific code, and you don't have to think about it again until you actually need to: for example, if a provider goes down and you want to fail over to another one automatically, which PayZephyr also does for you.

**Currently supported providers:** Paystack, Stripe, PayPal, Flutterwave, Square, Monnify, OPay, and Mollie.

## Is this for you?

PayZephyr is a good fit if:

- You're building a Laravel app that needs to accept one-time payments, recurring subscriptions, or both.
- You want to support more than one payment provider (or might in the future) without duplicating your checkout logic.
- You want webhook signature verification, replay-attack protection, and transaction logging handled for you instead of hand-rolled per provider.

It's **not** trying to be a full accounting or invoicing system; it's a payment abstraction layer. Refunds are supported (see [Refunds](docs/refunds.md)), but full accounting/ledger reconciliation is out of scope.

## How it fits together

```mermaid
flowchart LR
    A[Your Controller] -->|Payment::amount...->redirect| B[PayZephyr]
    B --> C{Which provider?}
    C -->|paystack| D[Paystack API]
    C -->|stripe| E[Stripe API]
    C -->|"...or any of the 8"| F[...]
    D & E & F --> G[Customer pays on\nthe provider's page]
    G --> H[Provider redirects back\nto your callback URL]
    G -.->|webhook, in parallel| I[Your queue worker]
    H --> J[Payment::verify]
    I --> K[WebhookReceived event]
```

Two things happen when a customer pays: they get redirected back to your app (so you can show a "thank you" page), *and* the provider sends your app a webhook in the background (so your database stays correct even if the customer closes their browser before the redirect completes). PayZephyr handles both paths: the [Understanding Payment Flow](docs/payment-flow.md) chapter walks through exactly what happens at each step.

## Quick Start

This gets you from zero to a working payment in about five minutes. For the full walkthrough with explanations of *why* each step matters, see [Your First Payment](docs/first-payment.md).

**1. Install the package and run the setup command:**

```bash
composer require kendenigerian/payzephyr
php artisan payzephyr:install
```

`payzephyr:install` copies PayZephyr's configuration file into your app (so you can edit it), copies the database migrations it needs (for transaction logging), and offers to run them for you. See [Installation](docs/installation.md) if you'd rather do each step by hand.

**2. Add your provider's credentials to `.env`.** Paystack is enabled by default. Grab your test keys from your Paystack dashboard:

```env
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true
```

**3. Start a payment:**

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

Route::get('/checkout', function () {
    return Payment::amount(500.00)
        ->email('customer@example.com')
        ->callback(route('payment.callback'))
        ->redirect();
});
```

**4. Verify it when the customer comes back:**

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

Route::get('/payment/callback', function (\Illuminate\Http\Request $request) {
    $verification = Payment::verify($request->query('reference'));

    if ($verification->isSuccessful()) {
        return 'Payment succeeded! Reference: '.$verification->reference;
    }

    return 'Payment did not go through.';
})->name('payment.callback');
```

That's a working payment flow. It's also incomplete on its own: webhooks are what make it *reliable* (a customer closing their browser tab shouldn't mean you never find out they paid). Read on.

## Documentation

The chapters below are written to be read roughly in order if you're new to PayZephyr; each one builds on the last. If you already know what you're looking for, jump straight there.

**Getting started**

1. [Installation](docs/installation.md): every way to install PayZephyr, explained
2. [Configuration](docs/configuration.md): what every config option does and when to change it
3. [Your First Payment](docs/first-payment.md): a complete, working example built step by step
4. [Understanding Payment Flow](docs/payment-flow.md): what actually happens between "customer clicks pay" and "money in your account"
5. [Payment Verification](docs/verification.md): confirming a payment actually succeeded, correctly

**Core features**

6. [Subscriptions](docs/subscriptions.md): recurring billing, supported on 6 of the 8 providers
7. [Refunds](docs/refunds.md): full and partial refunds, supported on all 8 providers
8. [Webhooks](docs/webhooks.md): why they exist and how to handle them
9. [Events](docs/events.md): every event PayZephyr fires and how to listen for it
10. [Testing](docs/testing.md): testing code that charges money, without charging money
11. [Error Handling](docs/error-handling.md): what can go wrong and how PayZephyr tells you
12. [Security](docs/security.md): webhook verification, replay protection, and what PayZephyr does *not* protect you from
13. [Queues](docs/queues.md): why a queue worker is required, not optional

**Going further**

14. [Multiple Providers](docs/providers.md): per-provider setup, currencies, and feature support
15. [Custom Drivers](docs/custom-drivers.md): adding a provider PayZephyr doesn't support yet
16. [Advanced Usage](docs/advanced-usage.md): direct driver access, health checks, idempotency patterns

**Shipping it**

17. [Production Checklist](docs/production-checklist.md): what to double-check before going live
18. [Deployment](docs/deployment.md): migrations, environment variables, monitoring
19. [Upgrade Guide](docs/upgrade-guide.md): moving between major versions

**When things go wrong**

20. [Troubleshooting](docs/troubleshooting.md): common problems, their causes, and their fixes
21. [FAQ](docs/faq.md)

**Reference**

22. [API Reference](docs/api-reference.md): every public method, documented
23. [Architecture](docs/architecture.md): how the package is put together internally
24. [Contributing](docs/contributing.md)

The full table of contents, if you'd rather browse than read linearly, is in [docs/INDEX.md](docs/INDEX.md).

## Changelog

See [CHANGELOG.md](docs/CHANGELOG.md) for version history. **v2.0.0 contains breaking changes**: read the upgrade notes before updating a production app.

## License

MIT. See [LICENSE](LICENSE).

## Support

If PayZephyr is useful to you, starring the repository helps other people find it. Contributions (code, documentation, bug reports) are welcome; see [Contributing](docs/contributing.md).

---

**Built for the Laravel community by [Ken De Nigerian](https://github.com/ken-de-nigerian)**
