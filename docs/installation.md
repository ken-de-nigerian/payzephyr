# Installation

This chapter walks through getting PayZephyr into a Laravel app, step by step, and explains what each step actually does — not just what to type.

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

This downloads PayZephyr and its dependencies (an HTTP client for talking to payment providers, and Stripe's official SDK, which PayZephyr uses internally for the Stripe driver) into your `vendor/` directory. Laravel's package auto-discovery picks up PayZephyr's service provider automatically — you don't need to register anything by hand.

## Step 2: Run the install command

```bash
php artisan payzephyr:install
```

Here's exactly what this does, in order:

1. **Publishes the configuration file** to `config/payments.php`. This is your copy — PayZephyr ships with sensible defaults baked into the package, but publishing the file lets you see every option and change what you need to. Nothing works without this file existing in your app; PayZephyr reads its settings from `config/payments.php`, not from inside the package itself.
2. **Publishes the database migrations** PayZephyr needs. Three tables get created: `payment_transactions` (a log of every payment you initiate), `subscription_transactions` (the same, for subscriptions), and `webhook_events` (used internally to make sure a webhook delivered twice by a provider only gets processed once — more on that in [Webhooks](webhooks.md)).
3. **Asks if you want to run the migrations now.** Say yes unless you have a reason to run them separately later (for example, if your deployment pipeline runs migrations as its own step).

> **Tip:** Pass `--force` to overwrite an already-published `config/payments.php` — useful if you're upgrading PayZephyr and want the latest default config structure to diff against your customized one.

If you'd rather run each piece yourself instead of using the install command, here's the manual equivalent:

```bash
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

## Step 3: Configure a provider

`php artisan payzephyr:install` doesn't ask you for API keys — it just sets up the files. You still need to tell PayZephyr which provider(s) to use and give it credentials. Open your `.env` file and add:

```env
PAYMENTS_DEFAULT_PROVIDER=paystack

PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true
```

Paystack is used here as an example because it's the default provider out of the box, but you can use any of the eight supported providers instead — see [Configuration](configuration.md) for every provider's required keys, and [Multiple Providers](providers.md) for the full list.

> **Where do I get these keys?** From your payment provider's own dashboard, under something like "API Keys" or "Developers." Always start with *test* or *sandbox* keys while you're building — they let you simulate payments without moving real money. Switch to live keys only when you're ready to go to production (see the [Production Checklist](production-checklist.md)).

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

> **Note:** This endpoint is unauthenticated by default, which is fine for local development but should be locked down before you deploy — see [Security](security.md#health-endpoint) and the [Production Checklist](production-checklist.md).

## What just happened, structurally

After installation, your app has:

```
your-app/
├── config/
│   └── payments.php          ← your copy, safe to edit
├── database/
│   └── migrations/
│       ├── ..._create_payment_transactions_table.php
│       ├── ..._create_subscription_transactions_table.php
│       └── ..._create_webhook_events_table.php
└── .env                       ← your provider credentials
```

PayZephyr itself lives in `vendor/kendenigerian/payzephyr` and you never edit it directly — everything you need to customize is either in `config/payments.php` or your `.env` file.

## Next steps

Now that PayZephyr is installed, head to [Configuration](configuration.md) to understand every option in `config/payments.php`, or skip ahead to [Your First Payment](first-payment.md) if you'd rather learn by building something first and come back to the configuration reference later.
