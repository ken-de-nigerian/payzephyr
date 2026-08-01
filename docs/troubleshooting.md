# Troubleshooting

Each entry here follows the same shape: what you're seeing, why it happens, how to fix it, and how to confirm the fix actually worked.

## Webhooks not processing

**Symptom:** Payments complete successfully on the provider's side, your callback route works fine, but orders that rely on a webhook (subscription renewals, or orders where the customer never made it back to your callback) never update.

**Likely cause:** No queue worker is running. Webhook processing happens on a queue, not synchronously: see [Queues](queues.md) for why. `php artisan serve` alone does not run a queue worker.

**Fix:**

```bash
php artisan queue:work
```

In production, run this under a process supervisor (see [Deployment](deployment.md#queue-workers)) so it survives crashes and restarts after deploys.

**Verify:** trigger a real webhook (or run `php artisan queue:work --once` after manually dispatching a test job) and confirm your `WebhookReceived` listener actually ran: a log line, a database change, whatever your listener does.

## "Driver [x] not found" / `DriverNotFoundException`

**Likely cause:** the provider isn't enabled, or the name is misspelled somewhere.

**Fix:** in `.env`, the provider needs `_ENABLED=true`:

```env
PAYSTACK_ENABLED=true
```

and double check the provider name you're passing to `->with()` matches exactly one of the keys under `payments.providers` in `config/payments.php` (lowercase, e.g. `'paystack'`, not `'Paystack'`).

**Verify:** `php artisan tinker` then `app(\KenDeNigerian\PayZephyr\PaymentManager::class)->driver('paystack')` should return a driver instance without throwing.

## `InvalidConfigurationException`

**Likely cause:** a required key for that provider is missing from `.env`. The exception message tells you exactly which key.

**Fix:** cross-reference the [Configuration](configuration.md#provider-credentials) required-keys table against your `.env`.

**Verify:** hit `/payments/health`: the provider should show `"healthy": true` once configured correctly.

## Webhook signature validation always fails

**Likely cause:** the webhook secret in your `.env` doesn't match what the provider's dashboard has configured, or you copied a *test* secret while pointing the provider at your *live* webhook URL (or vice versa).

**Fix:** re-copy the webhook secret directly from the provider's dashboard for the specific webhook endpoint you registered, matching test/live mode consistently. For providers where the raw request body matters (most of them, the signature is computed over the exact bytes received), make sure nothing in your middleware stack is modifying the request body before PayZephyr's webhook route processes it.

**Verify:** send a test webhook from the provider's dashboard (most providers have a "send test webhook" button) and confirm it's accepted rather than rejected.

## Health endpoint returns 401/403

**Likely cause:** you followed the [Production Checklist](production-checklist.md#health-endpoint) and enabled authentication: this is correct behavior, not a bug, but you need to actually authenticate your monitor now.

**Fix:** include the configured token, or call from an allow-listed IP:

```env
PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS=your-token-here
```

```bash
curl -H "Authorization: Bearer your-token-here" https://yourdomain.com/payments/health
```

(Check `HealthEndpointMiddleware` in PayZephyr's source, or the response of an unauthenticated request, for the exact expected header format if this doesn't work immediately.)

## "database is locked" errors under load

**Likely cause:** you're running SQLite in production with genuinely concurrent webhook traffic. SQLite serializes writes at the whole-database level rather than per-row, so concurrent writers can transiently collide.

**Fix:** use MySQL or PostgreSQL in production: see [Production Checklist](production-checklist.md#database). SQLite is fine for local development and testing.

## An order gets marked "paid" twice, and my code assumed that couldn't happen

**Likely cause:** both your callback route and your webhook listener can independently mark the same order paid, and one of them isn't written to be safe to run more than once (idempotent).

**Fix:** make "mark paid" an idempotent operation: `$order->update(['status' => 'paid'])` is safe to run any number of times; something like `$wallet->increment('balance', $amount)` triggered from *both* the callback and the webhook is not, and will double-credit. Guard it: only apply the side effect if the order wasn't already marked paid.

**Verify:** manually trigger both the callback and a webhook for the same reference in a test environment and confirm the side effect (balance credited, email sent, whatever it is) only happened once.

## Amounts look 100x too large or too small

**Likely cause:** mixing up major and minor currency units somewhere in your own code. PayZephyr's public API always uses major units (dollars, not cents): `Payment::amount(15.00)` means fifteen dollars. If you're separately reading a provider's *raw* webhook payload directly (bypassing `$verification->amount`, which PayZephyr already normalizes for you), that raw payload is very likely in the provider's own minor-unit format.

**Fix:** always read amounts from `VerificationResponseDTO::$amount` (or `ChargeResponseDTO`) rather than digging through `$event->payload` for a raw amount field, unless you specifically know what unit that field is in for that provider.

## Still stuck?

Check the [FAQ](faq.md) for common questions, or [open an issue](https://github.com/ken-de-nigerian/payzephyr/issues) with: which provider, which PayZephyr version, the exact error message or unexpected behavior, and (if it's webhook-related) whether a queue worker is confirmed to be running.
