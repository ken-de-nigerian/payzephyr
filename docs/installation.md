# Installation

This chapter walks through getting PayZephyr into a Laravel app, step by step, and explains what each step actually does, not just what to type.

## Before you start

| Requirement | Version |
|---|---|
| PHP | 8.2 or higher |
| Laravel | 12.x or 13.x |
| Composer | Any recent version |

If you're not sure which Laravel version your app is on, run `php artisan --version`.

## Step 1: Install the package

```bash
composer require kendenigerian/payzephyr
```

This downloads PayZephyr and its dependencies (an HTTP client for talking to payment providers, and Stripe's official SDK, which PayZephyr uses internally for the Stripe driver) into your `vendor/` directory. Laravel's package auto-discovery picks up PayZephyr's service provider automatically, so you don't need to register anything by hand.

## Step 2: Run the install command

```bash
php artisan payzephyr:install
```

Here's exactly what this does, in order:

1. **Publishes the configuration file** to `config/payments.php`. This is your copy: PayZephyr ships with sensible defaults baked into the package, but publishing the file lets you see every option and change what you need to. Nothing works without this file existing in your app; PayZephyr reads its settings from `config/payments.php`, not from inside the package itself.
2. **Publishes the core database migrations.** `payment_transactions` (a log of every payment you initiate) and `webhook_events` (used internally to make sure a webhook delivered twice by a provider only gets processed once; more on that in [Webhooks](webhooks.md)) are created unconditionally - see [Core vs. optional features](#core-vs-optional-features) for why.
3. **Asks whether you want Subscriptions and/or Refunds too**, one confirmation each. Neither is installed unless you say yes - PayZephyr doesn't create a table your app doesn't use.
4. **Asks if you want to run the migrations now.** Say yes unless you have a reason to run them separately later (for example, if your deployment pipeline runs migrations as its own step).

> **Tip:** Pass `--force` to overwrite already-published files, useful if you're upgrading PayZephyr and want the latest default config structure to diff against your customized one.

If you'd rather run each piece yourself instead of using the install command, here's the manual equivalent for core-only:

```bash
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payzephyr-migrations-core
php artisan migrate
```

## Core vs. optional features

PayZephyr's install command distinguishes between what your app needs no matter what, and what it only needs if you're actually using that capability:

| | Tables | Why |
|---|---|---|
| **Core** (always installed) | `payment_transactions`, `webhook_events` | `Payment::charge()`/`verify()` log to `payment_transactions` by default (`payments.logging.enabled`), and every webhook from every provider - regardless of which PayZephyr feature triggered it - is deduplicated through `webhook_events`. Both are load-bearing for the package's basic charge/verify/webhook flow, not specific to any optional feature. |
| **Subscriptions** (optional) | `subscription_transactions` | Only needed if you call `Payment::subscription()`. |
| **Refunds** (optional) | `refund_transactions` | Only needed if you call `Payment::refund()`. |

Subscriptions and Refunds don't depend on each other or on anything beyond core - selecting one never pulls in the other.

## Selecting features non-interactively

For CI/CD or scripted deployments, skip the prompts entirely:

```bash
# Install every optional feature
php artisan payzephyr:install --no-interaction --all

# Install specific features (comma-separated, case-insensitive)
php artisan payzephyr:install --no-interaction --features=subscriptions,refunds

# Core only - the default when neither flag is given non-interactively
php artisan payzephyr:install --no-interaction
```

An unknown feature name in `--features=` fails the command with a clear error naming exactly which value it didn't recognize, rather than silently ignoring it or installing everything.

**`--no-interaction` alone, with neither `--all` nor `--features=`, installs core only.** This is deliberate: a non-interactive run has no way to ask you what you want, so it never guesses "everything" on your behalf.

## Adding a feature later

You don't need to reinstall the package to turn on a feature you skipped the first time:

```bash
php artisan payzephyr:install --features=refunds
```

Re-running the installer is always safe: it detects what's already installed (by checking which migration files already exist) and only publishes what's genuinely new. Already-installed features are left completely alone - PayZephyr never re-copies, modifies, or removes an existing feature's migration or data just because you ran the installer again, and answering "no" to a feature you'd previously enabled does not uninstall it. There's currently no `payzephyr:uninstall` command; removing a feature's table is a manual, deliberate operation (drop the table and remove its config), the same as it would be for any other package.

Each newly enabled feature is also recorded in `.env` (`PAYZEPHYR_FEATURE_SUBSCRIPTIONS=true` / `PAYZEPHYR_FEATURE_REFUNDS=true`), surfaced back through `config('payments.features')`. This is informational bookkeeping for your own code to check if you want to - it does **not** gate `Payment::subscription()`/`Payment::refund()` at runtime; those work based on which driver the provider you called `->with()` actually supports, independent of what the installer has run.

## Step 3: Configure a provider

`php artisan payzephyr:install` doesn't ask you for API keys; it just sets up the files. You still need to tell PayZephyr which provider(s) to use and give it credentials. Open your `.env` file and add:

```env
PAYMENTS_DEFAULT_PROVIDER=paystack

PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true
```

Paystack is used here as an example because it's the default provider out of the box, but you can use any of the eight supported providers instead; see [Configuration](configuration.md) for every provider's required keys, and [Multiple Providers](providers.md) for the full list.

> **Where do I get these keys?** From your payment provider's own dashboard, under something like "API Keys" or "Developers." Always start with *test* or *sandbox* keys while you're building: they let you simulate payments without moving real money. Switch to live keys only when you're ready to go to production (see the [Production Checklist](production-checklist.md)).

## Step 4: Verify it worked

The quickest way to confirm everything is wired up correctly is to check PayZephyr's health endpoint, which PayZephyr registers automatically at `/payments/health`:

```bash
php artisan serve
curl http://localhost:8000/payments/health
```

You should get back JSON listing each enabled provider and whether PayZephyr can reach it. If Paystack shows `"healthy": true`, installation worked.

```json
{
    "status": "healthy",
    "providers": {
        "paystack": { "healthy": true }
    }
}
```

> **Note:** This endpoint is unauthenticated by default, which is fine for local development but should be locked down before you deploy; see [Security](security.md#health-endpoint) and the [Production Checklist](production-checklist.md).

## What just happened, structurally

After a core-only installation, your app has:

```
your-app/
├── config/
│   └── payments.php          ← your copy, safe to edit
├── database/
│   └── migrations/
│       ├── ..._create_payment_transactions_table.php
│       └── ..._create_webhook_events_table.php
└── .env                       ← your provider credentials
```

If you also selected Subscriptions and/or Refunds, their migration files (`..._create_subscription_transactions_table.php`, `..._create_refund_transactions_table.php`) appear alongside the core ones - nothing else changes.

PayZephyr itself lives in `vendor/kendenigerian/payzephyr` and you never edit it directly; everything you need to customize is either in `config/payments.php` or your `.env` file.

## Existing installations upgrading to feature-selective install

If you installed PayZephyr before this command distinguished core from optional features, your app already has all four tables from a single prior `vendor:publish --tag=payments-migrations` run - and that's fine, nothing here retroactively changes your existing database. `config('payments.features')` will read as `false` for both Subscriptions and Refunds until you either set `PAYZEPHYR_FEATURE_SUBSCRIPTIONS`/`PAYZEPHYR_FEATURE_REFUNDS` in `.env` yourself (since the installer only writes them when it's the one publishing a feature for the first time) or run `php artisan payzephyr:install --features=subscriptions,refunds` once, which will detect the migration files already exist and simply record the flags without touching your schema. The old `payments-migrations` tag (publishing all four tables at once) still works exactly as before, unchanged, for any script or documentation that references it directly.

## Next steps

Now that PayZephyr is installed, head to [Configuration](configuration.md) to understand every option in `config/payments.php`, or skip ahead to [Your First Payment](first-payment.md) if you'd rather learn by building something first and come back to the configuration reference later.
