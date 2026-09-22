# Tracing

## The question logging can't answer

`payment_transactions` tells you where a payment *ended up*: succeeded, via Stripe, ₦5,000. That's the right thing to store, and for most days it's all you need.

It's the wrong shape for one particular question, though, and it's the question that arrives at 3am: **why did this happen?**

Consider a payment PayZephyr recovered for you. Paystack was down, so the fallback chain moved on and Stripe took the charge. The transaction row says `provider: stripe`, `status: success` - which is true, and complete, and tells you nothing about the fact that another provider was tried first and lost. That attempt left no durable record at all. Neither did the reason it lost.

Tracing fills that in. Where logging keeps a payment's current **state**, tracing keeps the **sequence** that produced it: one append-only row per step, keyed by the same reference you already have.

```
14:22:07.104 • payment.initiated
14:22:07.106 • provider.skipped (paystack)
14:22:07.241 → provider.request.sent (stripe)
        POST https://api.stripe.com/v1/checkout/sessions
14:22:08.402 ← provider.response.received (stripe)
        HTTP 200
        1161ms
14:22:08.404 • payment.completed (stripe)
```

That's the whole story, in order, with the gaps visible.

## Turning it on

Tracing is off by default and installs as an optional feature, alongside Subscriptions and Refunds:

```bash
php artisan payzephyr:install --features=trace
```

That publishes the `payment_trace_events` migration and writes `PAYZEPHYR_FEATURE_TRACE=true` to your `.env`. Both matter - the migration creates somewhere to write, and the flag is what makes PayZephyr write there.

Unlike the other two feature flags, **this one is read on every request**. With it off, PayZephyr resolves a do-nothing recorder that touches no database, no queue and no config. Turning tracing off therefore takes effect on the next request: no deploy, no migration to roll back. That matters more here than elsewhere, for reasons covered under [Retention](#retention-is-your-job) below.

## What gets recorded

Every step PayZephyr takes on the charge, verification and webhook paths.

**Deciding who to charge.** This is the part that has no equivalent anywhere else in the package:

| Event | Recorded when |
|---|---|
| `payment.initiated` | A charge starts, carrying the provider chain it intends to try |
| `provider.skipped` | A provider was passed over without being contacted, with the reason (`failed_health_check` or `unsupported_currency`) |
| `provider.error` | A provider was tried and failed, and the chain moved on |
| `charge.ambiguous` | The outcome is genuinely unknown - see below |
| `charge.duplicate_rejected` | The in-flight claim turned away a resubmission |
| `payment.completed` / `payment.failed` | The chain finished |

**Talking to the provider.** Recorded once in `AbstractDriver::makeRequest()`, which every bundled driver already routes through:

| Event | Recorded when |
|---|---|
| `provider.request.sent` | An HTTP request goes out, with method and URL |
| `provider.response.received` | A reply comes back, with status code and elapsed milliseconds |
| `provider.timeout` | The request actually timed out |
| `provider.error` | The provider answered with an error status |
| `provider.exception` | Anything else went wrong on the wire |

**Verification and webhooks:** `verification.started`, `verification.completed`, `verification.failed`, `verification.not_persisted`, `webhook.received`, `webhook.duplicate`, `webhook.validation_failed`, `webhook.queue_failed`, `webhook.processing_failed`, `retry.scheduled`, `retry.abandoned`.

Two of those are worth calling out because nothing else in PayZephyr records them:

- **`webhook.duplicate` keeps the body.** `webhook_events` stores a provider and a dedup key and nothing else, so before tracing you could tell *that* a duplicate arrived but not whether it agreed with the first one. Now you can compare them.
- **`verification.not_persisted`** means the provider confirmed the payment but the local update failed. Your database says pending, the provider says paid. That's a reconciliation bug, and it used to be a log line.

### Ambiguous outcomes

`charge.ambiguous` is the most important event in the set. It means PayZephyr sent a charge, never got an answer, and **cannot tell whether the customer was charged**. The request reached the provider; the response was lost.

PayZephyr refuses to fail that over to another provider, because doing so could take the money twice. It also refuses to guess on the timeline: an ambiguous charge is terminal and counts as an error, but makes neither `succeeded()` nor `failed()` true. Nobody knows yet, and a timeline that pretended otherwise would be worse than one that says so.

### One reference, however many providers

Every event above is keyed by the **same reference for the whole fallback chain** - the string `Payment::charge()` hands back and `Payment::verify()` takes. A payment that failed on one provider and succeeded on another reads as one timeline, not two unrelated halves.

A `correlation_id` groups the events of a *single* provider attempt within that. One reference, one correlation group per provider tried - which is what turns a fallback chain into something readable rather than a flat pile.

## Reading a timeline

```bash
php artisan payzephyr:trace PZ_1755000000_a1b2c3d4
```

Errors print red, the final outcome green, everything else plain, so a long timeline can be skimmed.

Add `--detailed` and PayZephyr will also tell you what it thinks is worth attention:

```bash
php artisan payzephyr:trace PZ_1755000000_a1b2c3d4 --detailed
```

```
Worth a look
  [critical] Charge outcome unknown - the provider may have taken the money. Verify before retrying.
  [high] A request to stripe was sent and no response was ever recorded.
  [medium] A provider took 8213ms to respond (over the 5000ms threshold).
```

That list is deliberately short. It reports problems and open questions, not ordinary events - a findings list that flags everything teaches people to skim past it. Tune the latency threshold with `payments.trace.slow_response_ms`.

Other options:

```bash
# Only the steps involving one provider
php artisan payzephyr:trace PZ_1755... --provider=stripe

# Machine-readable, for piping somewhere else
php artisan payzephyr:trace PZ_1755... --json
```

### From PHP

```php
use KenDeNigerian\PayZephyr\Services\TraceTimelineBuilder;

$timeline = app(TraceTimelineBuilder::class)->build($reference);

$timeline->succeeded();          // bool
$timeline->failed();             // bool
$timeline->duration();           // milliseconds, or null
$timeline->errors();             // Collection of error events
$timeline->terminal();           // the event that ended it, or null
$timeline->forProvider('stripe');
$timeline->analyze();            // the findings --detailed prints
```

### Recording your own steps

The taxonomy is broader than what PayZephyr emits: `payment.cancelled`, `payment.refunded`, `auth.required`, `auth.completed`, `auth.failed`, `retry.executed` and `custom` are defined and unused, and are there for you.

```php
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Facades\Trace;

Trace::record(new TraceEventDTO(
    reference: $reference,
    event: TraceEvent::AUTH_COMPLETED,
    direction: TraceDirection::INTERNAL,
    payload: ['method' => '3ds2'],
));
```

`Trace` is not registered as a global alias - the name is far too generic to claim in your application - so import it, or resolve `TraceRecorderInterface` from the container.

Recording never throws. If the table is missing, the database is unreachable or the queue is down, the event is dropped and reported to your payment log channel, and whatever you were doing carries on.

## Privacy

By default, tracing stores provider **request and response bodies**, and webhook bodies. That is where most of its forensic value lives, and it is also the part worth a decision rather than a default.

Sensitive fields are redacted before anything is written - both `payload` and `metadata` go through the same pass. The field list lives at `payments.trace.redact_fields` and covers card numbers, CVVs, tokens, API keys and authorization headers out of the box.

Matching is on **substrings and case-insensitive**, deliberately: providers name the same secret a dozen different ways, and catching `stripe_api_key` matters more than the cost of also redacting a benign `tokenization_enabled`. That trade is asserted in the test suite so a future change to it breaks a test rather than quietly leaking.

If provider bodies in your integration carry more customer data than you want at rest, turn body capture off:

```dotenv
PAYZEPHYR_TRACE_RECORD_HTTP_BODIES=false
```

You keep every event, every timestamp, every status code and every timing. You lose only the bodies.

See [Security](security.md) for how this sits alongside PayZephyr's other data-handling.

## Retention is your job

**This is the only PayZephyr table that grows per step rather than per payment** - roughly six to ten rows where the rest of the package writes one. Nothing prunes it on its own.

Schedule this:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('payzephyr:trace:prune')->daily();
```

It deletes events past `payments.trace.retention_days` (90 by default), in chunks, and it is safe to run unattended - the non-interactive path is explicit rather than relying on a prompt defaulting its way through.

Before you trust it, look:

```bash
php artisan payzephyr:trace:prune --dry-run
```

```
[dry run] 48,215 trace events are older than 90 days and would be deleted.
  Spanning 2026-03-02 09:14:22 to 2026-06-18 23:51:07
  Run again without --dry-run to delete them.
```

Leaving this unscheduled is the most likely way tracing becomes a problem for you. It is also why the feature flag is read at runtime: if the table does get away from you, `PAYZEPHYR_FEATURE_TRACE=false` stops the writing immediately, without a deploy, while you catch up.

## Cost

Tracing writes on the hot path of every charge, verification and webhook. Two things reduce that:

**Write off the request.** Recommended in production:

```dotenv
PAYZEPHYR_TRACE_ASYNC=true
PAYZEPHYR_TRACE_QUEUE_NAME=traces
```

Payloads are redacted *before* the job is queued, so nothing sensitive sits in the queue backend waiting to be written.

**Write somewhere else.** Trace is the highest-volume table PayZephyr produces, and pointing it at its own connection is a reasonable thing to want under load:

```dotenv
PAYZEPHYR_TRACE_CONNECTION=analytics
```

Timestamps are millisecond-precision, unlike PayZephyr's other tables. Several steps of one payment routinely land inside the same second, and the gap between them is the thing a timeline is read for.

## Every trace setting

| Variable | Default | What it does |
|---|---|---|
| `PAYZEPHYR_FEATURE_TRACE` | `false` | The kill switch. Off means nothing is recorded at all |
| `PAYZEPHYR_TRACE_ASYNC` | `false` | Write trace rows from a queued job instead of in the request |
| `PAYZEPHYR_TRACE_QUEUE_NAME` | default queue | Which queue those jobs go on |
| `PAYZEPHYR_TRACE_QUEUE_CONNECTION` | default connection | Which queue *connection* those jobs go on, when it differs from the application's |
| `PAYZEPHYR_TRACE_CONNECTION` | default connection | Which database connection trace rows are written to |
| `PAYZEPHYR_TRACE_TABLE` | `payment_trace_events` | The table name, if it collides with something you already have |
| `PAYZEPHYR_TRACE_RECORD_HTTP_BODIES` | `true` | Whether provider request and response bodies are stored, after redaction |
| `PAYZEPHYR_TRACE_REDACTION_MAX_DEPTH` | `10` | How deep redaction walks a nested payload. Bounded on purpose: trace payloads are attacker-influenced, and unbounded recursion over hostile JSON is a memory-exhaustion vector |
| `PAYZEPHYR_TRACE_SLOW_RESPONSE_MS` | `5000` | The threshold, in milliseconds, above which a provider response is marked slow on its timeline |
| `PAYZEPHYR_TRACE_RETENTION_DAYS` | `90` | How much history `payzephyr:prune-trace-events` keeps |

## What isn't traced yet

Worth knowing so an empty stretch doesn't read as a bug:

- **Refunds and subscriptions.** Both are separate optional features with their own tables, and neither is instrumented yet.
- **Synchronous signature failures.** For most providers a bad webhook signature is rejected with a 403 in `WebhookRequest::authorize()`, before the controller or the queued job runs - so it leaves no trace row. `webhook.validation_failed` only fires for providers that defer verification into the job (Mollie and PayPal).
- **Custom drivers that don't extend `AbstractDriver`.** Implementing `DriverInterface` directly is fully supported and always will be; such a driver simply records no HTTP-level steps. Everything the manager decides around it is still recorded.

## Next steps

- [Troubleshooting](troubleshooting.md) - where you'll actually reach for this
- [Security](security.md) - what PayZephyr stores and what it doesn't
- [Deployment](deployment.md) - the prune schedule, alongside your queue workers
- [Queues](queues.md) - if you turn async recording on
