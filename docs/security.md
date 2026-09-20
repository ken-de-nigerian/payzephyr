# Security

This chapter is about what PayZephyr protects you from automatically, and (just as important) what it doesn't, so you know where your own responsibility starts.

## Webhook signature verification

### Why this matters

Your webhook endpoint is a public URL. Anyone who discovers it (and provider webhook URL patterns are predictable, so assume someone eventually will) can send a `POST` request to it that *looks* exactly like a real "payment succeeded" webhook. If PayZephyr trusted every incoming request at face value, anyone could mark any order as paid for free by guessing (or brute-forcing) a reference and sending a fake webhook.

### How PayZephyr prevents it

Every provider signs its real webhook requests using a secret that only your app and the provider know: the details differ per provider (most use an HMAC signature in a header; PayPal instead calls back to its own verification API), but the effect is the same: PayZephyr can confirm a request's authenticity before trusting anything in it. An incorrectly-signed or unsigned request is rejected before your application code (including your `WebhookReceived` listener) ever sees it.

This is why every provider's `webhook_secret` (or equivalent; PayPal uses a `webhook_id`) in your `.env` matters, even though it's not in the strict "required to make PayZephyr work" list in [Configuration](configuration.md#provider-credentials). Without it, PayZephyr has nothing to check the signature against.

**PayZephyr's signature comparisons use constant-time comparison** (`hash_equals()`, not `==` or `===`), specifically to avoid timing attacks: a naive string comparison can leak information about how much of a guessed signature is correct based on how long the comparison takes, letting an attacker guess a valid signature byte-by-byte. This is invisible to you day-to-day, but it's part of why you shouldn't hand-roll your own webhook signature checking outside of PayZephyr.

### Never disable this with real credentials

```env
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=false
```

This exists purely as an escape hatch for local development against a sandbox that doesn't sign its test webhooks correctly. If this is `false` in any environment with real API keys, your webhook endpoint will accept and act on forged requests. Double-check this setting specifically as part of your [production checklist](production-checklist.md) before going live.

## Replay-attack protection

Even a *correctly signed* webhook can be a problem if it's a genuine, previously-valid request being resent later by someone who intercepted it, a "replay attack." PayZephyr guards against this by checking the timestamp embedded in each webhook payload and rejecting anything older than a configurable tolerance window:

```env
PAYMENTS_WEBHOOK_TIMESTAMP_TOLERANCE=300
```

(Default: 300 seconds, five minutes.) A webhook whose own timestamp is older than that gets rejected, even if its signature is otherwise valid: the assumption being that a legitimate webhook delivery from a provider arrives within seconds of the event happening, not minutes later.

This is separate from (and in addition to) the [duplicate-delivery deduplication](webhooks.md#duplicate-deliveries) covered in the Webhooks chapter. That mechanism stops the *same* webhook a provider sends twice from being processed twice (normal provider behavior); this one stops an *old* webhook from being resent by someone else and treated as new.

**Razorpay is the exception.** Its webhook `created_at` is when the Payment Link or refund was created, not when the event happened, so a link paid ten minutes after it was created would fail the window. Razorpay webhooks are therefore checked by signature only, and a replayed one is stopped by duplicate-delivery deduplication: a replay is byte-identical to the original, so it is recognised and skipped. See [ADR-0014](architecture/adr/0014-razorpay-driver.md).

## Metadata sanitization

Anything you pass into `->metadata([...])` on a charge, subscription, or refund gets stored in PayZephyr's own `payment_transactions`/`subscription_transactions`/`refund_transactions` tables, and depending on your app, some of that metadata might ultimately come from something a customer typed (a note field, for instance). Before it's persisted, PayZephyr strips HTML tags, `javascript:`/`data:text/html:` URIs, and inline event handlers (`onerror=`, and similar) out of string values, and caps how deep nested arrays and how long individual strings can be. Refunds get the same treatment on their `->reason(...)` value too - free text is exactly the kind of field a customer-facing "why are you refunding this" form would feed straight into, and several providers echo a reason-like field back in their own response, so it gets sanitized before storage the same way metadata does.

This matters if you (or anyone building an admin panel against PayZephyr's transaction tables later) ever render metadata values directly into HTML: without this sanitization, a customer-controlled metadata value containing `<script>` could execute in an admin's browser when they view the transaction. PayZephyr's sanitization is defense-in-depth here, not a substitute for your own output escaping (Blade's `{{ }}` already escapes by default, keep using it); it means the *stored* value is already clean, rather than relying entirely on every future place that renders it to remember to escape correctly.

## Log sanitization

PayZephyr's internal logging (API errors, webhook validation failures, and so on) automatically redacts values that look like they might be secrets: anything under a key like `password`, `secret`, `token`, `api_key`, `authorization`, or similar, plus any string value that looks like it starts with a recognizable API key prefix (`sk_`, `pk_`, `whsec_`, a `Bearer ` token) regardless of what key it's stored under. This is on by default:

```env
PAYMENTS_SANITIZE_LOGS=true
```

Leave it on. The only reason to disable it is deep local debugging where you specifically need to see a raw value PayZephyr would otherwise redact; never in an environment whose logs anyone else might read.

## What tracing stores

Only relevant if you've enabled the optional [Tracing](tracing.md) feature - it's off by default, and with it off nothing below applies.

Tracing stores considerably more than the rest of PayZephyr does: by default it keeps **provider request and response bodies, and webhook bodies**, in the `payment_trace_events` table. That's where most of its value lives, and it's also the part worth a deliberate decision.

Sensitive fields are redacted before anything is written, and both free-form columns - `payload` and `metadata` - go through the same pass. The field list at `payments.trace.redact_fields` covers card numbers, CVVs, tokens, API keys and authorization headers out of the box. Matching is on substrings and case-insensitive, deliberately: providers name the same secret a dozen different ways, and catching `stripe_api_key` matters more than the cost of also redacting a benign `tokenization_enabled`.

If provider bodies in your integration carry more customer data than you want sitting at rest, turn body capture off and keep everything else:

```env
PAYZEPHYR_TRACE_RECORD_HTTP_BODIES=false
```

You keep every event, timestamp, status code and timing; you lose only the bodies.

Two other things worth knowing:

- **Redaction happens before queueing.** With `PAYZEPHYR_TRACE_ASYNC=true`, payloads are scrubbed *before* the job is dispatched, so nothing sensitive sits in your queue backend waiting to be written.
- **A rejected webhook can still write a row.** `webhook.validation_failed` is keyed by the reference read out of an unverified payload, so an attacker who knows a reference can cause rows attributed to it. The webhook route is rate-limited and payload-capped, and the forensic value of seeing "someone is sending bad-signature webhooks for this payment" is the reason it's recorded at all - but it is an unauthenticated write path, and worth knowing about.

## Health endpoint

`/payments/health` (see [Configuration](configuration.md#health-check)) is registered **without authentication by default**, which is a deliberate trade-off, not an oversight: it means the endpoint works immediately for local development and for uptime monitors that don't support custom headers, with zero setup. But an unauthenticated endpoint that reports which payment providers you have configured and whether they're currently reachable is information you probably don't want handed to anyone who requests it in production.

Before deploying, turn on authentication for it:

```env
PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true
PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS=your-secret-monitoring-token
```

or restrict it by IP if your monitoring tool has a fixed address:

```env
PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true
PAYMENTS_HEALTH_CHECK_ALLOWED_IPS=203.0.113.10,203.0.113.11
```

PayZephyr will log a warning (rate-limited, so it won't flood your logs) if it detects the health endpoint is enabled without authentication, as a nudge before you deploy. See the [Production Checklist](production-checklist.md) for the full pre-launch review.

## Rate limiting

The webhook endpoint has request rate limiting applied automatically (`webhook.rate_limit` in config, default 120 requests/minute), as a basic defense against the endpoint being flooded. Separately, `security.rate_limit` governs rate limiting PayZephyr applies to certain provider-facing operations. Both are on by default and rarely need adjusting unless you have an unusually high-volume integration.

## What PayZephyr does *not* protect you from

Being direct about the boundary matters as much as documenting what's covered:

- **PayZephyr doesn't validate that a charge amount matches what you actually intended to charge.** If your own application logic has a bug that charges the wrong amount, PayZephyr will faithfully process that charge; it has no way to know what you *meant* to charge. This is why [Payment Verification](verification.md) recommends checking `$verification->amount` against your own expected amount, not just checking success.
- **PayZephyr doesn't protect your callback route from being visited with an arbitrary reference.** Anyone can hit `/checkout/callback?reference=anything`: that's fine, because your code should always call `Payment::verify()` rather than trusting anything in the URL (see [Understanding Payment Flow](payment-flow.md#why-verify-instead-of-trusting-the-redirect)). If your own code skips verification and trusts the query string, that's a gap in your app, not something PayZephyr introduced.
- **PayZephyr doesn't manage PCI compliance for you.** Because every provider's checkout page handles the actual card entry (PayZephyr redirects to a provider-hosted page, or uses the provider's own tokenization for saved cards), raw card numbers never pass through your server or PayZephyr, but you're still responsible for following each provider's own integration requirements around this.
- **PayZephyr doesn't prevent you from logging sensitive data yourself.** Log sanitization (above) covers PayZephyr's *own* logging calls; if your own application code does `Log::info('charge', $request->all())` somewhere and that array happens to contain something sensitive, that's outside PayZephyr's reach.

## Next steps

- [Production Checklist](production-checklist.md): a concrete pre-launch review, including everything in this chapter
- [Webhooks](webhooks.md): the mechanism this chapter's signature verification protects
