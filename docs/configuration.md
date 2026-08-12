# Configuration

Everything PayZephyr does is controlled from one file: `config/payments.php` (published during [installation](installation.md)). This chapter goes through every section of it: not just what each key does, but why it's there.

Most values in the config file are read from environment variables via `env(...)`, so day-to-day you'll mostly be editing `.env`, not the config file itself. The config file is where you'd change *structural* things: like which providers exist at all, or their currency lists.

## Default and fallback providers

```php
'default' => env('PAYMENTS_DEFAULT_PROVIDER', 'paystack'),
'fallback' => env('PAYMENTS_FALLBACK_PROVIDER', 'stripe'),
```

`default` is which provider gets used when you don't explicitly pick one: for example, plain `Payment::amount(100)->email(...)->charge()` with no `->with('stripe')` call uses this provider.

`fallback` is what PayZephyr tries if the default provider's request fails (network error, the provider's API being down, and similar). This is the "automatic fallback" feature mentioned in the README. You don't have to write any retry logic yourself. Set it to `null` if you'd rather a failure just fail, with no fallback attempt.

```env
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe
```

You can also override which provider(s) to use for a single call, without touching config at all: see [Multiple Providers](providers.md#fallback-chains-per-call).

## Provider credentials

Each provider has its own block under `'providers'`. Every provider needs `enabled` set to `true` before PayZephyr will use it; this exists so you can keep credentials for a provider in `.env` (for example, ready for later) without PayZephyr trying to route traffic to it yet.

Here's exactly what each provider requires: these are the keys PayZephyr's own validation checks for; if any are missing, you'll get an `InvalidConfigurationException` the moment PayZephyr tries to use that provider, with a message telling you which key is missing.

| Provider | `.env` prefix | Required keys | Enable flag |
|---|---|---|---|
| Paystack | `PAYSTACK_` | `SECRET_KEY` | `PAYSTACK_ENABLED` |
| Stripe | `STRIPE_` | `SECRET_KEY` | `STRIPE_ENABLED` |
| PayPal | `PAYPAL_` | `CLIENT_ID`, `CLIENT_SECRET` | `PAYPAL_ENABLED` |
| Flutterwave | `FLUTTERWAVE_` | `SECRET_KEY` | `FLUTTERWAVE_ENABLED` |
| Square | `SQUARE_` | `ACCESS_TOKEN`, `LOCATION_ID` | `SQUARE_ENABLED` |
| Monnify | `MONNIFY_` | `API_KEY`, `SECRET_KEY`, `CONTRACT_CODE` | `MONNIFY_ENABLED` |
| OPay | `OPAY_` | `MERCHANT_ID`, `PUBLIC_KEY` | `OPAY_ENABLED` |
| Mollie | `MOLLIE_` | `API_KEY` | `MOLLIE_ENABLED` |

A couple of things worth calling out that aren't obvious from the table:

- **Webhook secrets aren't in the "required" list above** because PayZephyr will still *work* without them, but it can't verify that an incoming webhook is genuinely from your provider without one. Set them anyway; see [Security](security.md) for exactly what goes wrong if you don't.
- **PayPal needs a `webhook_id`** (`PAYPAL_WEBHOOK_ID`) specifically for webhook validation: PayPal's webhook verification works differently from every other provider (it calls PayPal's own verification API rather than checking an HMAC signature locally), and that API call needs to know which webhook subscription to check against.
- **`base_url` is provided with sensible defaults for every provider** (pointing at each provider's sandbox by default, where the provider has one), so you generally don't need to touch it unless you're pointing at a specific region or switching to a provider's live endpoint manually.

Example `.env` block for Paystack and Stripe both enabled (Paystack as default, Stripe as fallback):

```env
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe

PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_ENABLED=true

STRIPE_SECRET_KEY=sk_test_xxxxx
STRIPE_PUBLIC_KEY=pk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx
STRIPE_ENABLED=true
```

Full per-provider setup, including currencies each one supports, is in [Multiple Providers](providers.md).

## Currency

```php
'currency' => [
    'default' => env('PAYMENTS_DEFAULT_CURRENCY', 'NGN'),
],
```

The currency used when you don't specify one on a charge (`->currency('USD')`). If you don't set `PAYMENTS_DEFAULT_CURRENCY`, PayZephyr defaults to Nigerian Naira; change this if your app's primary currency is something else.

## Webhook settings

```php
'webhook' => [
    'path' => env('PAYMENTS_WEBHOOK_PATH', '/payments/webhook'),
    'verify_signature' => env('PAYMENTS_WEBHOOK_VERIFY_SIGNATURE', true),
    'rate_limit' => env('PAYMENTS_WEBHOOK_RATE_LIMIT', '120,1'),
    'max_payload_size' => env('PAYMENTS_WEBHOOK_MAX_PAYLOAD_SIZE', 1048576),
    'max_retries' => env('PAYMENTS_WEBHOOK_MAX_RETRIES', 3),
    'retry_backoff' => env('PAYMENTS_WEBHOOK_RETRY_BACKOFF', 60),
    'events' => [
        'table' => env('PAYMENTS_WEBHOOK_EVENTS_TABLE', 'webhook_events'),
    ],
],
```

These control the endpoint PayZephyr registers to receive webhook deliveries from your providers (`/payments/webhook/{provider}` by default: `path` is the base, the provider name is appended automatically). The full explanation of what a webhook even is and why you need one lives in [Webhooks](webhooks.md); this section is just the dial-by-dial reference:

- **`verify_signature`**: whether PayZephyr checks that an incoming webhook is genuinely signed by your provider before trusting it. Leave this `true` in every environment that has real credentials. It exists as a toggle mainly for local development against providers whose sandbox doesn't send correctly-signed test webhooks.
- **`rate_limit`**: protects the webhook endpoint from being flooded; format is `"attempts,minutes"`.
- **`max_payload_size`**: webhooks larger than this (in bytes) are rejected before PayZephyr even tries to parse them, as a defense against oversized payloads.
- **`max_retries` / `retry_backoff`**: if processing a webhook fails (a transient database error, for instance), PayZephyr retries it this many times, waiting `retry_backoff` seconds between attempts, using Laravel's queue retry mechanism.
- **`events.table`**: the database table PayZephyr uses to remember which webhook deliveries it's already processed, so a provider re-sending the same webhook (which every provider does as normal behavior, not a bug) doesn't get handled twice. See [Webhooks](webhooks.md#duplicate-deliveries) for why this matters.

## Health check

```php
'health_check' => [
    'cache_ttl' => env('PAYMENTS_HEALTH_CHECK_CACHE_TTL', 300),
    'require_auth' => env('PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH', false),
    'allowed_ips' => env('PAYMENTS_HEALTH_CHECK_ALLOWED_IPS') ? explode(',', env('PAYMENTS_HEALTH_CHECK_ALLOWED_IPS')) : [],
    'allowed_tokens' => env('PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS') ? explode(',', env('PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS')) : [],
],
```

PayZephyr exposes `/payments/health`, which reports whether it can currently reach each enabled provider, useful for uptime monitoring. Checking a provider's health means making a real HTTP request to it, which is slow to do on every hit, so results are cached for `cache_ttl` seconds.

`require_auth` is `false` by default so the endpoint works immediately in local development with zero setup. **You should turn this on before deploying**, along with either `allowed_ips` (a comma-separated allowlist) or `allowed_tokens` (bearer tokens callers must present); otherwise anyone on the internet can hit this endpoint. See the [Production Checklist](production-checklist.md) and [Security](security.md#health-endpoint).

## Transaction logging

```php
'logging' => [
    'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),
    'table' => 'payment_transactions',
    'channel' => env('PAYMENTS_LOG_CHANNEL', 'payments'),
],
```

Every charge you initiate through PayZephyr gets a row written to `payment_transactions` (the table created by the migration you ran during installation): this is how you query "what did this customer pay for" later, without keeping your own duplicate bookkeeping. `channel` is which [Laravel log channel](https://laravel.com/docs/logging) PayZephyr's own diagnostic logging (API errors, webhook validation failures, and so on) writes to, pointing it at its own channel to keep payment-related log noise separate from your app's general logs.

## Subscriptions

```php
'subscriptions' => [
    'prevent_duplicates' => env('PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES', false),
    'validation' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED', true),
    ],
    'logging' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED', true),
        'table' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE', 'subscription_transactions'),
    ],
    // ...
],
```

Covered in full in [Subscriptions](subscriptions.md), since it needs the context of how subscriptions actually work to make sense. Quick summary: `prevent_duplicates` stops the same customer from being subscribed to the same plan twice by mistake; `validation.enabled` runs sanity checks (does this plan exist, is this customer eligible) before making an API call rather than after; `logging` mirrors the payment-transaction logging above, but for subscriptions.

## Refunds

```php
'refunds' => [
    'prevent_duplicates' => env('PAYMENTS_REFUNDS_PREVENT_DUPLICATES', true),
    'validation' => [
        'enabled' => env('PAYMENTS_REFUNDS_VALIDATION_ENABLED', true),
    ],
    'logging' => [
        'enabled' => env('PAYMENTS_REFUNDS_LOGGING_ENABLED', true),
        'table' => env('PAYMENTS_REFUNDS_LOGGING_TABLE', 'refund_transactions'),
    ],
    // ...
],
```

Covered in full in [Refunds](refunds.md). Quick summary: when `validation.enabled`, PayZephyr checks (both best-effort, gated by `validation.enabled` as a whole) that a refund's amount doesn't exceed the original transaction's remaining refundable balance, and - separately, gated by `prevent_duplicates` - rejects a second refund attempt while an earlier one on the same transaction is still pending/processing, to guard against accidental double-submission without blocking legitimate sequential partial refunds once the earlier one resolves (see [Preventing over-refunds and duplicates](refunds.md#preventing-over-refunds-and-duplicates)); `logging` mirrors the payment-transaction logging above, but for refunds.

## Cache

```php
'cache' => [
    'session_ttl' => env('PAYMENTS_CACHE_SESSION_TTL', 3600),
],
```

Some providers (PayPal, notably) require an OAuth-style access token that PayZephyr fetches and reuses rather than requesting on every single API call. This controls how long that token is cached before PayZephyr fetches a fresh one.

## Security

```php
'security' => [
    'webhook_timestamp_tolerance' => env('PAYMENTS_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
    'rate_limit' => [
        'enabled' => env('PAYMENTS_RATE_LIMIT_ENABLED', true),
        'max_attempts' => env('PAYMENTS_RATE_LIMIT_ATTEMPTS', 10),
        'decay_seconds' => env('PAYMENTS_RATE_LIMIT_DECAY', 60),
    ],
    'sanitize_logs' => env('PAYMENTS_SANITIZE_LOGS', true),
    'cache_isolation' => env('PAYMENTS_CACHE_ISOLATION', true),
],
```

Explained fully, with the reasoning behind each default, in [Security](security.md). The short version: `webhook_timestamp_tolerance` (seconds) is how old a webhook's timestamp can be before PayZephyr rejects it as a possible replay attack; `sanitize_logs` strips things that look like API keys or tokens out of log output before they're written, so a stray `Log::debug()` somewhere can't leak a secret into your logs.

## Next steps

With configuration in place, [Your First Payment](first-payment.md) walks through building a complete, working checkout flow using what you've just configured.
