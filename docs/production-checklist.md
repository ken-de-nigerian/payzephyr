# Production Checklist

A concrete list to work through before you point PayZephyr at real payment providers with real money moving through it. Each item links back to the chapter that explains the *why*, if you want more context than the checklist itself gives.

## Credentials

- [ ] Live (not test/sandbox) API keys are set for every provider you're actually using, and test keys are removed from your production `.env`.
- [ ] `PAYPAL_MODE=live` if you're using PayPal (easy to forget — it's separate from swapping the keys themselves). See [Multiple Providers](providers.md#paypal).
- [ ] Every enabled provider's `webhook_secret` (or PayPal's `webhook_id`) is set. Without it, PayZephyr can't verify that incoming webhooks are genuine. See [Security](security.md#webhook-signature-verification).

## Webhooks

- [ ] `PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true` (this is the default — confirm it hasn't been overridden anywhere). Never `false` with real credentials. See [Security](security.md#never-disable-this-with-real-credentials).
- [ ] Each provider's dashboard has the correct live webhook URL configured — see the table in [Webhooks](webhooks.md#setting-it-up-in-your-providers-dashboard).
- [ ] A queue worker is actually running in production, supervised so it restarts if it crashes (Supervisor, systemd, or your platform's equivalent). This is the single most common thing people forget — see [Queues](queues.md) for exactly what breaks if you skip it.
- [ ] Migrations have been run in production, so `payment_transactions`, `subscription_transactions`, and `webhook_events` all exist.

## Health endpoint

- [ ] `PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true`, with either `PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS` or `PAYMENTS_HEALTH_CHECK_ALLOWED_IPS` set. The default (`false`) is fine for local development but leaves `/payments/health` open to anyone in production. See [Security](security.md#health-endpoint).

## Database

- [ ] You're running MySQL or PostgreSQL, not SQLite, if you expect genuinely concurrent traffic. PayZephyr's concurrency-safety (preventing two simultaneous webhook deliveries from corrupting the same transaction record) relies on row-level locking, which SQLite doesn't support the same way — SQLite's own whole-database locking still prevents silent data corruption, but under real concurrent load you're more likely to see transient "database is locked" errors than a smooth queue. SQLite is fine for development and testing; for production, especially with webhook traffic, use MySQL or PostgreSQL.

## Logging

- [ ] `PAYMENTS_SANITIZE_LOGS=true` (default). Confirm your own application code isn't separately logging raw request data that might contain sensitive fields PayZephyr's own sanitization doesn't reach — see [Security](security.md#log-sanitization).
- [ ] Your `payments` log channel (`PAYMENTS_LOG_CHANNEL`, see [Configuration](configuration.md#transaction-logging)) is actually configured in `config/logging.php` and going somewhere you'll notice if errors start appearing — not silently discarded.

## Your own application code

- [ ] Your callback route calls `Payment::verify()` and checks the amount, not just success — see [Payment Verification](verification.md).
- [ ] You have a `WebhookReceived` listener registered (or subscription-specific listeners), so your database stays correct even when a customer never makes it back to your callback route — see [Webhooks](webhooks.md) and [Events](events.md).
- [ ] Error handling exists around `charge()`/`redirect()`/`verify()` calls — see [Error Handling](error-handling.md) — so a provider outage shows the customer a reasonable message instead of an unhandled exception page.

## Rate limiting

- [ ] `security.rate_limit` and `webhook.rate_limit` (see [Configuration](configuration.md#security)) are appropriate for your expected traffic — the defaults are reasonable starting points, but a high-volume integration may need them raised.

## One more time, before you flip the switch

Run through a real test transaction end to end in your production environment with live (small, real) money, if the provider and your business context allow it — a $1 charge you refund manually afterward, or your provider's smallest allowed amount. Nothing catches a misconfigured webhook URL or an untested edge case in your callback logic like watching a real transaction happen from start to finish.

## Next steps

- [Deployment](deployment.md) — the mechanics of actually shipping (migrations, environment variables, monitoring setup)
- [Troubleshooting](troubleshooting.md) — if something on this list turns up broken
