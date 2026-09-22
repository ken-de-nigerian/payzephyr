# Changelog

All notable changes to `payzephyr` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---
## [Unreleased]

### Added

- **Payment tracing has moved into PayZephyr**, folded in from the separate `payzephyr-trace`
  package, which is being retired. Where `logging` keeps a payment's current state, tracing
  keeps the sequence that produced it: one append-only row per step, so a payment's whole
  lifecycle can be replayed afterwards.

  The charge, webhook and verification paths are all instrumented, and `payzephyr:trace` reads a
  timeline back. The feature is off unless `PAYZEPHYR_FEATURE_TRACE=true`.

  New: a `trace` block in `config/payments.php`, a `payment_trace_events` migration,
  `Models\PaymentTraceEvent`, `DataObjects\TraceEventDTO`, `Enums\TraceEvent`,
  `Enums\TraceDirection`, `Services\TraceRecorder`, `Services\PayloadRedactor`,
  `Services\Timeline`, `Services\TraceTimelineBuilder`, `Jobs\RecordTraceEvent`, and a
  `Facades\Trace` for recording your own steps. `TraceRecorderInterface` is bound in the
  container, so a custom recorder can be swapped in.

  The trace table's timestamps are millisecond-precision (`timestamps(3)`), unlike PayZephyr's
  other tables. Several steps of one payment routinely land inside the same second, and the gap
  between them is the thing a timeline is read for.

- **The charge path now records what it did and why.** This is the part that earns the feature.
  PayZephyr can silently route a payment to a provider the caller never chose, and until now the
  only durable record of that was a single `payment_transactions` row saying which provider
  eventually won. A failed first attempt left nothing behind at all.

  A charge now records: `payment.initiated` with the provider chain it intends to try;
  `provider.skipped` with a reason whenever a provider is passed over without being contacted
  (failed health check, or unsupported currency); `provider.request.sent` and
  `provider.response.received` for each HTTP round trip, with method, URL, status code and
  elapsed milliseconds; `provider.error` when a provider fails and the chain moves on;
  `charge.ambiguous` when the outcome is genuinely unknown; `charge.duplicate_rejected` when the
  in-flight claim turns a resubmission away; and `payment.completed` or `payment.failed` at the
  end.

  Every one of those is keyed by the single reference the chain shares, so a payment that failed
  on one provider and succeeded on another reads as one timeline rather than two unrelated
  halves.

  Two classification decisions worth knowing about, because getting them wrong would make
  timelines lie:

  - A single provider failing inside a fallback chain is `provider.error`, **not**
    `payment.failed`. `Timeline::terminal()` returns the *first* terminal event, so marking an
    individual provider's failure terminal would report a payment that was successfully
    recovered by the next provider as having failed. `payment.failed` is reserved for the chain
    giving up.
  - `charge.ambiguous` is terminal and counts as an error, but makes neither
    `Timeline::succeeded()` nor `Timeline::failed()` true. Nobody knows yet whether the customer
    was charged, and a timeline that guessed would be worse than one that says so.

- **HTTP tracing is instrumented once, in `AbstractDriver::makeRequest()`**, which is the single
  chokepoint every one of the eight bundled drivers already routes through. A driver that
  implements `DriverInterface` directly rather than extending `AbstractDriver` remains entirely
  valid and simply records no HTTP-level steps.

  Reading a response body for the timeline never costs the charge: the stream is rewound
  afterwards, a non-seekable body is left untouched rather than drained, and a body that cannot
  be read at all costs the timeline its payload and nothing else.

  Guzzle reports connection refused, DNS failure and connect timeouts all as the same
  `ConnectException`, so PayZephyr records `provider.timeout` only when the underlying error
  actually says it timed out. Everything else is `provider.exception` - otherwise whoever reads
  the timeline goes hunting for a slow provider when the real answer is a bad host.

  `payments.trace.record_http_bodies` (default `true`) turns body capture off on its own,
  keeping the timing, status codes and event sequence. Worth turning off if provider bodies in
  your integration carry more customer data than you want at rest.

- **The verification path is recorded too, which is what finally answers the redirect-versus-webhook
  question.** A verification records `verification.started` with the providers it is about to ask,
  then `verification.completed` with the status the provider gave, or `verification.failed` once
  nobody could answer. Individual providers that cannot answer are `provider.error` - the same
  split the charge chain uses, so one provider being unreachable is never mistaken for the
  verification itself having failed.

  "Completed" means PayZephyr got a definitive answer, not that the payment succeeded. A provider
  replying "this payment failed" is a verification that worked, and the status in the payload says
  which.

  Because a charge and its later verification share one reference, they now share one timeline -
  with millisecond timestamps. Which of the redirect and the webhook actually arrived first stops
  being a guess.

- **`verification.not_persisted` is new, for the quietest failure in the package.** The provider
  confirms a payment, the local `payment_transactions` update then fails, and the caller is told
  the payment succeeded while the database still says otherwise. That divergence used to surface
  weeks later as a reconciliation mismatch with nothing anywhere to explain it. It is an error but
  not terminal, and it carries the status the provider actually reported.

- **Provider round trips made during a verification are now recorded.** A verification carries no
  `ChargeRequestDTO`, so `AbstractDriver` could not recover the reference from `$currentRequest`
  the way it does during a charge, and these calls went unrecorded. `setTraceCorrelationId()` has
  been replaced by `setTraceContext(?string $reference, ?string $correlationId)`, and both the
  charge and verification paths go through one `withTraceContext()` helper that clears it in a
  `finally`. A driver implementing only `DriverInterface` is unaffected and simply records no
  HTTP-level steps.

- **`php artisan payzephyr:trace <reference>`** reads a timeline back, which is the point of
  recording one. Keyed by reference - the same string `Payment::charge()` returned and
  `Payment::verify()` takes - so there is nothing new to look up first. `--provider` narrows it to
  one provider, `--json` gives a machine-readable version for piping elsewhere, and `--detailed`
  adds the analysis below.

  An unknown reference is not an error. A payment made before tracing was switched on has no
  events and never will, so the command says which of those it is - including telling you that
  tracing is off, and how to turn it on - rather than implying the payment does not exist.

- **One analyser, one vocabulary.** The package this was folded in from shipped two overlapping
  ones, `analyze()` and `detectAnomalies()`, which both reported slow responses and both reported
  missing responses, using different thresholds and different wording for the same finding.
  `Timeline::analyze()` is now the only one.

  It reports, in severity order: an ambiguous charge outcome, a verification the provider
  confirmed but the database did not record, provider timeouts, abandoned webhook retries,
  webhooks that were never queued, duplicate deliveries, failed signature checks, requests that
  were sent and never answered, and provider responses slower than
  `payments.trace.slow_response_ms`. Repeated problems are counted rather than listed one by one,
  and a clean timeline says so - a report that flags ordinary events teaches people to skim past
  it.

  Orphaned requests are matched within a correlation group, which is what that identifier is for:
  one group is one provider round trip, so a group holding a request and no reply is a call that
  went out and vanished.

- **`php artisan payzephyr:trace:prune`** deletes trace events past
  `payments.trace.retention_days` (90 by default). **Nothing prunes on its own.** This is the only
  PayZephyr table that grows per *step* rather than per payment - six to ten rows where there used
  to be one - so it is the one that needs scheduling:

  ```php
  Schedule::command('payzephyr:trace:prune')->daily();
  ```

  `--dry-run` reports the count and the span it would remove without touching anything, `--days`
  overrides the window for a one-off, `--chunk` sets the batch size, and `--force` skips the
  confirmation.

  The unattended path is an explicit branch rather than a prompt falling through to its default
  value - this command exists to run on a schedule, and "it proceeds because `confirm()` returns
  its default when there is no TTY" is a behaviour nobody chose. Interactively it asks, and
  defaults to no. It also refuses a window of less than a day, and rejects a non-numeric `--days`
  instead of quietly pruning against the configured window.

  Deletion selects ids and deletes by primary key, re-running the filter on each pass, rather than
  chunking a cursor over the rows being deleted underneath it. That also keeps it portable:
  `LIMIT` on a `DELETE` is a MySQL extension SQLite refuses unless it was compiled to allow it.

- **Both commands are registered whether or not tracing is on**, and explain themselves when it
  is not. Registering them conditionally would mean someone who has not enabled the feature gets
  "command not found", which tells them nothing - and would have made the command's own "tracing
  is switched off, here is how to turn it on" message unreachable. A missing table is reported
  with the one command that fixes it, rather than as a driver-level complaint.

- **`Traits\RecordsTraceEvents`** is how every call site records. `TraceRecorder::record()`
  already promised not to throw, but that promise started too late: building a `TraceEventDTO`
  happens at the call site, and the DTO refuses a reference that cannot key a timeline. On the
  webhook path that reference comes out of a provider payload, so the wrapper closes the gap
  where a malformed body could otherwise have taken down payment handling through the tracing
  code.

- **The webhook path now records what arrived, including what a duplicate said.** `webhook_events`
  stores a provider and a dedup key and nothing else, so until now you could tell that a duplicate
  delivery had happened but had no way to see whether it agreed with the first one. That content
  is kept now, under the same reference the charge used, so the redirect and the webhook land on
  one timeline instead of two.

  A delivery records `webhook.received` once it passes deduplication, `webhook.duplicate` when it
  does not, `webhook.validation_failed` when a deferred signature check rejects it,
  `webhook.processing_failed` with the attempt number when the job throws, `retry.scheduled` when
  another attempt is genuinely coming, and `retry.abandoned` once PayZephyr gives up. Bodies are
  governed by the same `payments.trace.record_http_bodies` switch as provider request and response
  bodies, since it is the same category of data.

  `retry.abandoned` is recorded from the job's `failed()` hook rather than inferred in the catch
  block, because that is the only point at which "abandoned" is a fact rather than a guess.
  `retry.scheduled` is claimed only when the job is really running on a queue - `attempts()`
  reports `0` off one, which would otherwise promise a retry that never arrives.

- **A webhook that is accepted but never queued is recorded as `webhook.queue_failed`.** It is the
  one failure the job cannot report on its own, because the job never runs - and unlike a
  processing failure, no retry is coming. The controller stays ignorant of the payload on the
  happy path and only resolves the reference inside its catch block, so this costs nothing until
  something has already gone wrong.

  **Two limits worth knowing.** `webhook.validation_failed` only fires for drivers that defer
  verification (Mollie and PayPal). Every other driver's signature is checked in
  `WebhookRequest::authorize()`, which returns `403` before the controller or the job runs, so
  those rejections leave no trace row at all.

  And because the reference is now read before the deferred signature check - it has to be, or a
  rejected delivery could not be attributed to anything - a `webhook.validation_failed` row is
  keyed by a reference taken from an **unverified** payload. Someone sending forged webhooks could
  therefore write rows into a timeline they do not own. It is bounded by the route's rate limit,
  the payload size cap and trace retention, and the forensic value is real, but it is an
  unauthenticated write path and you should decide whether you want it.

- **`payzephyr:install --features=trace`** installs it, and `payzephyr:uninstall --features=trace`
  removes it, alongside `subscriptions` and `refunds`. Interactive installs list it as a third
  checkbox, and `--all` includes it. Nothing in `InstallCommand` or `UninstallCommand` needed
  changing: both iterate the `Features` registry, so trace is one entry rather than a special
  case.

  The switch lives at `payments.features.trace` - one key, one meaning. An earlier draft of
  this release had a second `payments.trace.enabled`, which would have let a published config
  file disagree with itself about whether tracing was on.

- **`PAYZEPHYR_FEATURE_TRACE` is a real kill switch.** Tracing is the only PayZephyr feature
  that writes on the hot path of every charge, verification and webhook, so switching it off
  has to mean *nothing runs* - not "a disabled check runs first". With the flag off the
  container hands out `Services\NullTraceRecorder`, which touches no database, no queue, no
  config and no container. It takes effect on the next request: no deploy, and no migration to
  roll back.

  This makes `trace` the first `PAYZEPHYR_FEATURE_*` flag that is read at runtime rather than
  only by the installer. `subscriptions` and `refunds` remain installer bookkeeping.

- **Recording a trace event can never fail a payment.** A trace event describes something that
  has already happened; if storing that description fails, the payment is still exactly as true
  as it was, and the caller must hear about the payment rather than about PayZephyr's
  bookkeeping. `TraceRecorder::record()` is now guaranteed not to throw - a missing table, an
  unreachable database, a dead queue backend and a broken log channel all end in a log line and
  a `null` return. It takes the same position, for the same reason, as
  `PaymentManager::completeSuccessfulCharge()`.

  The most likely version of this in practice is the flag being switched on before
  `payzephyr:install --features=trace` has been run, so the table does not exist yet. That
  degrades to "no timeline", not "no payments".

- **`TraceEventDTO` now normalizes what it is given instead of refusing it.** Several of its
  fields arrive from outside PayZephyr's control: the webhook route is `POST /{provider}` with
  no constraint on the segment, and payloads are provider response bodies that are not
  guaranteed to be valid UTF-8. An over-long provider slug is trimmed to the column width, an
  unrecognised HTTP method or an out-of-range status code is dropped, and a payload or metadata
  array that is too large or not encodable is replaced by a `_payzephyr_dropped` marker
  explaining why. In every case the event survives with its timestamp, event type and timing
  intact, which is most of what a timeline is for.

  The single exception is the reference. It is the key the whole timeline hangs off, and a
  coerced one would file this payment's history under a different payment, so that still
  throws - and `TraceRecorder` catches it. The payload ceiling matches
  `payments.webhook.max_payload_size` rather than being a number of its own.

- **Razorpay support** as a tenth provider: `RazorpayDriver` with charge, verify, webhook
  signature validation, health check, and refunds. Enable it with `RAZORPAY_ENABLED=true` plus
  `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, and `RAZORPAY_WEBHOOK_SECRET`; see
  [Providers](providers.md#razorpay) for what is different about it, and
  [ADR-0014](architecture/adr/0014-razorpay-driver.md) for why.

  - **A charge is a Payment Link.** PayZephyr redirects to the link's `short_url` and sends
    your reference as its `reference_id`, which Razorpay limits to 40 characters. A longer
    reference fails with `ChargeException` before any request is sent, so fallback to another
    provider still applies.
  - **Amounts use each currency's exponent.** Zero-decimal (JPY, KRW, ...) and three-decimal
    (KWD, BHD, OMR, ...) currencies are converted correctly rather than multiplied by 100.
  - **Refunds find the payment behind your reference**, take the currency from Razorpay
    rather than from config, always send an explicit amount (the unrefunded remainder for a
    full refund), and settle through the `refund.processed` / `refund.failed` webhooks.
  - **Subscriptions are not wrapped yet.** Razorpay has a subscriptions API; it is left for a
    follow-up.

  Webhooks are verified as HMAC-SHA256 of the raw body (`X-Razorpay-Signature`) with the
  webhook secret. No timestamp window is applied: Razorpay's `created_at` is when the link or
  refund was created, not when the event happened, so replays are stopped by the existing
  duplicate-delivery deduplication instead. With no `RAZORPAY_WEBHOOK_SECRET`, webhooks are
  rejected. Only `payment_link.*` events update `payment_transactions`.

  Checked against a Razorpay test account as well as mocked HTTP; ADR-0014 lists what was
  and was not verified.

### Fixed

- **Razorpay could refund a hundred times too much.** The refund path took the currency from
  the payment with `?? ''`, and that currency chooses the exponent the outbound amount is
  multiplied by. A payment whose `currency` was absent fell through to two decimals, so a
  refund of JPY 500 was sent as 50,000 minor units - a real, hundredfold over-refund that
  Razorpay has no reason to reject. The currency is now required, by name.

- **Razorpay could report a verified payment worth nothing.** `verify()` read the amount with
  `?? 0` and the currency with `?? ''`, so a Payment Link response missing either was reported
  as a *successful verified payment* of 0.00, or in an unnamed currency at a hundredth of its
  value. `fetchRefund()` had the same pair of defaults, and `refund()` fell through to zero when
  Razorpay did not echo the amount back.

  All four now fail by name through the same `require*` guards the other nine drivers use. Where
  a refund response omits an amount PayZephyr itself sent, that amount is kept as a defensible
  inference - only the fall-through to zero is gone. Razorpay is now covered by the cross-driver
  test that proves no driver turns a provider's silence into a number.

  This contribution predates those guards; it was written against a base where `?? 0` was the
  house style throughout.

- **Razorpay refunds could go out without the idempotency protection the caller asked for.** An
  idempotency key that did not match Razorpay's format (at least 10 characters of letters,
  digits, hyphens or underscores) was dropped and the refund sent anyway. Nothing would then
  deduplicate a retry, while the caller had every reason to believe otherwise. Such a key is now
  refused before anything is sent. A valid key is still sent as `X-Refund-Idempotency`.

- **A fetched Razorpay refund with no status was reported as `unknown`**, which maps to no
  `RefundStatus` at all and dropped the refund out of the over-refund guard's accounting. It is
  now treated as still in flight.

- **Paddle refunds could report an amount and currency Paddle never sent.** The adjustment
  mapper read `currency_code` with a `?? 'USD'` default and the refund total with `?? 0`. That
  currency is not cosmetic: it decides whether the amount is divided by 100, so a JPY refund
  read as USD was recorded at a hundredth of its value, and a refund whose total was absent was
  stored as a completed refund of 0.00. Both now fail by name through the same `require*`
  guards every other driver uses, and Paddle is covered by the cross-driver test that proves no
  driver turns a provider's silence into a number.

  The adjustment's own `id` is required too. An empty refund reference persists happily, and
  `fetchRefund('')` would later query the list endpoint rather than look anything up.

- **A refund whose Paddle response was lost said nothing about what had happened to the money.**
  Paddle Billing accepts no client-supplied idempotency key, and its own guidance for a create
  whose response never arrived is to list the entity and check. PayZephyr now does that listing
  for you: when a refund's outcome is ambiguous, the exception names the refund adjustments
  Paddle currently holds against the transaction, with their amounts and statuses.

  It deliberately stops there rather than deduplicating. An adjustment carries no `custom_data`
  and no idempotency field, so a retry of one $10 refund and a deliberate second $10 refund are
  indistinguishable; collapsing them automatically would be its own money bug, with the merchant
  believing two refunds went out and the customer receiving one. The exception says plainly that
  PayZephyr will not retry and that the adjustments above need reconciling first. If the lookup
  itself fails, the message says so rather than replacing the original error.

- **Paddle mishandled currencies with no minor unit.** `ZERO_DECIMAL_CURRENCIES` listed four
  entries where PayPal's equivalent list has seventeen. A merchant billing in KRW, ISK, XOF or
  any of the other thirteen had every amount sent to Paddle a hundred times too large, and every
  amount read back a hundred times too small. The two lists now match.

- **A non-transaction Paddle notification could overwrite a pending payment's status.**
  `PaddleDriver` correctly reports `unknown` for subscription and adjustment events, but
  `ProcessWebhook` then wrote that string into the transaction row. The result matched no
  `PaymentStatus`, so `paid_at` was never set and reconciliation saw a payment that was neither
  pending nor complete. A webhook carrying no recognisable payment status now leaves the
  transaction untouched and logs that it did.

- **A provider response missing its amount was reported as a payment worth nothing.** Every
  driver mapped provider responses with defaults - `?? 0` for amounts, `?? 'USD'` or `?? 'NGN'`
  for currencies. On any installation that does not promote PHP warnings to exceptions, a
  response without an amount became `(float) null`, and PayZephyr reported a *verified* payment of
  0.00 as fact. A defaulted currency was worse in its own way: a merchant trading in anything but
  the guessed currency had the payment silently mis-denominated.

  Absence now fails loudly and by name, through new `require*` helpers on `AbstractDriver`, on
  every path where a wrong number is a wrong answer about money: every `verify()` mapping, and
  every refund create and fetch mapping, across all eight drivers. The exception says what the
  provider left out and warns that the provider may still have accepted the request - the
  conservative reading, since the call has already happened. A genuine zero still passes; the
  guard is against absence, not against zero. Where a refund response omits its amount, the
  amount you requested is kept as a defensible inference; only the fall-through to zero is gone.

  The OPay test suite had been passing with this exact bug in it. A fixture sent `amount` as a
  scalar where OPay actually returns `{total, currency}`, the driver reported 0.0, and the test
  passed because it only asserted the status. It now uses the real shape and asserts the amount.

- **A plan update could bill customers twelve times as often as intended.** `updatePlan()`
  takes a raw array, so none of the validation `createPlan()` applies ever ran on it. An
  interval outside the four PayZephyr understands - `yearly`, `quarterly`, or Stripe's own
  `year` - fell through Stripe's, Square's, Mollie's and PayPal's interval mappers to a monthly
  default: a plan meant to bill once a year was moved to billing every month, silently. An amount
  of zero, which creation refuses, produced a free plan just as silently.

  Every driver's `updatePlan()` now checks the update against the same rules as creation, through
  `SubscriptionPlanDTO::assertValidUpdates()`, *before* contacting the provider - so a rejected
  update changes nothing anywhere. The valid intervals live in one place,
  `SubscriptionPlanDTO::INTERVALS`, shared by creation and update. As a second line of defence,
  every driver's outbound interval mapper now names all four intervals explicitly and refuses
  anything else; `monthly` had been working in four of them only because it was the fallback.

- **Changing a Stripe plan's interval could turn it into a free plan, or change how it bills.**
  Stripe prices are immutable, so PayZephyr changes an amount or interval by cloning the price.
  A tiered price has no `unit_amount`, and changing only its interval cloned it with
  `(int) null` - a free fixed-price plan. A metered price was cloned without its `usage_type`,
  so Stripe created a licensed one and every subscriber moved onto it was billed a flat amount
  rather than for what they used. Both now refuse with an explanation and create nothing. A
  tiered price can still be given a fixed amount when you pass one explicitly.

- **A Stripe refund whose response could not be read escaped as a `ChargeException`.** The
  mapping runs after `refunds->create()` has already succeeded, and it can throw - a missing
  amount raises `ChargeException` from the new guards, a missing status is a `TypeError` - but
  Stripe's `refund()` and `fetchRefund()` caught only `ApiErrorException`. A caller catching
  `RefundException` missed it entirely, and could retry past it: without an idempotency key, that
  retry is a second refund. Every other driver already rewrapped `Throwable`; Stripe now does
  too, and says the refund may already exist.

- **Flutterwave and Mollie still read the verified amount unguarded.** Both used a bare
  `(float) $result['amount']` rather than a `?? 0` default, so a search for defaults missed them,
  and they kept reporting a missing amount as 0.00 after every other driver was fixed. A test now
  puts the same missing-amount and missing-currency response through all six HTTP drivers, which
  is the test whose absence let this happen. PayPal's verify also read `status` unguarded.

- **A broken log channel could fail a charge.** `log()` on `LogsToPaymentChannel` and on
  `AbstractDriver` resolved its config outside its `try`, and its `InvalidArgumentException`
  fallback could itself throw. Drivers log from inside their catch blocks while mapping provider
  responses, and `PaymentManager::getCacheContext()` logs before the in-flight claim is taken -
  so a log channel failing for any reason other than a bad channel name (a full disk on a file
  driver would do it) surfaced as a failed payment for a customer whose card was fine. Both are
  now guaranteed not to throw. The trade is that a genuinely broken log channel goes unreported
  by PayZephyr, which is the right side of it: a lost log line is recoverable, a payment reported
  as failed after the money moved is not.

- **Paystack read the payment reference back out of the provider's response** rather than
  returning the one it had sent, unlike the other seven drivers. Paystack echoes what it is sent,
  so this was latent - but it was the one place a provider could split a payment's timeline in
  two, and the read was unguarded, so a response without `data.reference` was a `TypeError`
  rewrapped as a charge failure.

- **`payzephyr:uninstall --features=…` refused to remove anything whose migration file was
  gone.** It decided what to act on by globbing `database/migrations`, which is a poor proxy
  for what an app actually has: deleting a migration file by hand does not drop the table it
  created, and the table would then survive every subsequent uninstall. Naming features
  explicitly is now treated as an instruction rather than a question, and the command acts on
  exactly what you asked for. `removeResource()` was already idempotent, so a resource that
  turns out to be absent costs nothing.

  A bare `payzephyr:uninstall` still reports "nothing to do" when there is genuinely nothing
  installed - that check is what the glob was for.

  This matters more for trace than for the other two features, because
  `PAYZEPHYR_FEATURE_TRACE` is read at runtime: an app left with the flag on and no table
  would keep attempting a trace write on every payment. It degrades to a log line rather than
  a failed payment, but "uninstall" should mean it stops.

- **A payment that failed over to another provider had no single reference.** Every driver
  resolved its own reference inside `charge()`, as
  `$request->reference ?? $this->generateReference(...)`. That is fine for one provider and
  wrong for a chain: when the caller supplied no reference, each fallback attempt invented its
  own. A payment that failed on Paystack and succeeded on Stripe left two attempts with nothing
  in common, and the failed attempt's reference - never stored, never returned - could not be
  recovered at all. Anything trying to reconstruct what happened to that payment had two
  unrelated halves and no way to know they belonged together.

  `chargeWithFallback()` now resolves the reference once, before the fallback loop and before
  the in-flight claim. Every provider in the chain is handed the same reference, and it is the
  reference you get back.

  A knock-on effect: `claimChargeInFlight()` returns early when the request carries no
  reference, so charges without a caller-supplied one previously took no double-submission
  claim at all and released none. They do now. This does **not** make two independent
  auto-referenced submissions deduplicable - each still mints its own reference, and nothing
  ties them together - but re-submitting the reference PayZephyr just handed back is now
  rejected instead of charging the customer a second time.

- **Paddle Billing support** as a ninth provider: `PaddleDriver` with charge, verify, webhook
  signature validation, health check, and refunds. Enable it with `PADDLE_ENABLED=true` plus
  `PADDLE_API_KEY` and `PADDLE_WEBHOOK_SECRET`; see
  [Providers](providers.md#paddle) for the full list of what's different about it.

  This is **Paddle Billing**, not Paddle Classic. The two are separate products with
  incompatible APIs, and Classic credentials will not work here.

  Four things about Paddle genuinely do not fit the shape of the other eight drivers, and
  they are worth knowing before you enable it rather than discovering them in production:

  - **A charge is a transaction, and the checkout URL is not guaranteed.** PayZephyr creates a
    transaction with a single non-catalog item priced at the requested amount and returns
    Paddle's `checkout.url`. Paddle only populates that URL once you have set, and had
    approved, a default payment link under **Paddle > Checkout > Checkout settings**. Without
    it the charge raises `ChargeException` naming that setting, rather than returning a
    response with an empty redirect.

  - **Refunds are adjustments, and "pending" is the normal outcome.** Paddle has no refunds
    resource; a refund is an adjustment with `action: refund`. On live accounts most start as
    `pending_approval` until Paddle reviews them, so a successful `Refund::create()` normally
    reports `pending` with nothing wrong. The terminal status arrives on the
    `adjustment.updated` webhook, the same asynchronous pattern Paystack, Square, and PayPal
    refunds already use. Paddle's `pending_approval`/`approved`/`rejected`/`reversed`
    vocabulary is mapped explicitly; without that mapping `approved` would have fallen through
    to `RefundStatus::PENDING` and reported an approved refund as still in flight.

  - **Partial refunds need a single-item transaction.** Paddle requires a partial adjustment to
    name the transaction item it applies to. Charges PayZephyr creates always have exactly one
    item, so this is invisible in the normal flow — but a partial refund against a transaction
    created elsewhere (a subscription renewal, a multi-item checkout) is rejected with an
    explanatory `RefundException` instead of PayZephyr picking an item for you. The same lookup
    supplies the transaction's own `currency_code`, which is what decides whether the refund
    amount is multiplied into minor units. Paddle is the only bundled provider where the
    currency changes the number sent, and it accepts any partial amount under the line-item
    total without error, so inferring the currency from configuration would silently
    under-refund whenever a zero-decimal currency sat first in the list.

  - **Subscriptions are deliberately not supported.** Paddle subscriptions cannot be created
    through the API at all: Paddle creates them when a recurring-price checkout completes, and
    only then can they be updated. That is not the same operation as
    `Payment::subscription()->create()`, so `PaddleDriver` does not implement
    `SupportsSubscriptionsInterface` rather than shipping a `create()` that always throws.

  Webhook signatures are the `Paddle-Signature` header (`ts=<unix>;h1=<hmac>`), verified as
  HMAC-SHA256 over `"<ts>:<raw body>"` with the notification destination's endpoint secret
  key. The signed timestamp is checked against `PAYMENTS_WEBHOOK_TIMESTAMP_TOLERANCE`, so a
  validly-signed event cannot be replayed later — and because the timestamp is inside the
  signed payload, swapping it for a fresh one invalidates the signature too. Unlike Mollie,
  there is no API-fallback verification path: with no `PADDLE_WEBHOOK_SECRET` configured,
  webhooks are rejected rather than accepted unverified. Event idempotency uses Paddle's own
  `event_id`.

  Paddle delivers transaction, subscription and adjustment events to the same endpoint, and
  copies a transaction's `custom_data` onto the subscription it creates, so a subscription
  event can resolve to a reference PayZephyr knows. Payment status is therefore read only
  from `transaction.*` events; anything else reports `unknown` rather than writing a
  subscription's `active` or an adjustment's `approved` over a payment's status.

  Paddle Billing has no client-supplied idempotency key, so PayZephyr sends none instead of
  a header Paddle would ignore. The in-flight claim and ambiguous-outcome detection still
  apply unchanged; see [Idempotency](idempotency.md#per-provider-support) for what that
  leaves uncovered.

  `PADDLE_BASE_URL` defaults to the **sandbox** (`https://sandbox-api.paddle.com`), not live,
  so an install that is enabled before it is fully configured cannot charge real cards.

### Changed

- **Documentation no longer states how many providers there are, and a test enforces it.**
  Counts rot: "8 providers" became wrong when Paddle landed and wrong again with Razorpay, and
  the second sweep still left "nine" in two files and "ten" in a third. Prose now names
  providers or links the matrix in [Providers](providers.md), and
  `DocumentationAccuracyTest` fails the build on any sentence that counts them.

  The same test checks the other direction: every bundled driver appears in the matrix, every
  provider in the matrix is a driver that exists, the README names them all, and every
  environment variable the shipped config defines is documented somewhere. Architecture
  decision records and dated release audits are exempt — "5 of 9 providers" was true when that
  decision was made, and rewriting history to match the present would destroy the record.

  Nineteen environment variables were shipped but documented nowhere, including
  `PAYMENTS_HEALTH_CHECK_ENABLED`, every provider's `*_BASE_URL`, and four tracing settings.
  All are now documented.


- **Breaking (config): `refunds.webhook_events` and `refunds.notifications` have been removed**,
  for the same reason as the subscription blocks below: nothing in the package ever read either
  of them. `PAYMENTS_REFUNDS_NOTIFICATIONS_ENABLED` is now inert and can be deleted from your
  `.env`. `refunds.prevent_duplicates`, `refunds.validation` and `refunds.logging` are
  unaffected and still honored.

  These were missed on the first pass because the check matched key names as substrings, and
  `enabled`, `events` and `notifications` all appear elsewhere in the package. The scan is now
  path-aware and strips comments, so a key named only in a doc-block no longer counts as read.

- **Breaking (config): four subscription config blocks that nothing read have been removed.**
  `subscriptions.retry` (`enabled`, `max_attempts`, `delay_hours`), `subscriptions.grace_period`,
  `subscriptions.notifications`, and `subscriptions.webhook_events` were shipped in
  `config/payments.php` but no code in the package ever read any of them.

  That made them worse than dead weight. A merchant who set
  `PAYMENTS_SUBSCRIPTIONS_RETRY_ENABLED=true` with `..._MAX_ATTEMPTS=3` had every reason to
  believe failed renewals were being retried three times. Nothing retried them, and nothing said
  so. The subscription webhook routing has always matched hardcoded event names rather than
  `subscriptions.webhook_events`.

  Removing them changes no runtime behavior, because nothing consulted them. Renewal retry, grace
  periods and renewal notifications remain the application's responsibility: subscribe to
  `SubscriptionRenewed` and `SubscriptionPaymentFailed` and decide there. The corresponding
  `PAYMENTS_SUBSCRIPTIONS_RETRY_*`, `PAYMENTS_SUBSCRIPTIONS_GRACE_PERIOD` and
  `PAYMENTS_SUBSCRIPTIONS_NOTIFICATIONS_ENABLED` environment variables are now inert and can be
  deleted from your `.env`.

  `subscriptions.prevent_duplicates`, `subscriptions.validation` and `subscriptions.logging` are
  unaffected and still honored.

- **Breaking: `PlanResponseDTO::$amount` and `SubscriptionResponseDTO::$amount` are now
  `?float`**, and `PlanResponseDTO::getAmountInMajorUnits()` returns `?float`. Stripe returns a
  null `unit_amount` for tiered, metered and usage-based prices - a plan like that has no single
  price by design - and every subscription mapping collapsed it to `0.0`. That is
  indistinguishable from a plan that is genuinely free, so a customer on metered billing
  appeared, in PayZephyr's own records, to be paying nothing.

  Every subscription and plan mapping across all six subscription-capable drivers now maps an
  absent amount to `null` rather than zero, and `fromArray()` on both DTOs does the same. A
  provider reporting a genuine `0` still comes through as `0.0` - the change is about absence.

  Subscriptions deliberately did not get the fail-loudly treatment verify and refunds received
  above. A metered plan with no fixed price is a legitimate answer, not a malformed response, so
  throwing would have made those plans unusable. Null is what represents it truthfully.

  `subscription_transactions.amount` is now nullable to match, since a metered subscription would
  otherwise have failed on insert. **Existing installations need a one-line migration** - see
  Upgrading below.

  Subscription *currencies* still fall back to a default when a provider omits one. That is the
  same class of problem, left for a separate change because fixing it means making
  `$currency` nullable too, a second break to the same DTOs.

- **Auto-generated references now carry a provider-neutral `PZ_` prefix** rather than the
  fulfilling provider's name (`PAYSTACK_`, `STRIPE_`, and so on). A reference minted before the
  chain runs cannot know which provider will settle it, and naming the wrong one is worse than
  naming none: `ProviderDetector` resolves prefixes confidently, so a `PAYSTACK_` reference
  ultimately settled by Stripe would send a later `verify()` to the wrong provider. The shape is
  otherwise unchanged - `PREFIX_TIMESTAMP_RANDOMHEX`.

  **The trade this accepts:** `ProviderDetector::detectFromReference()` cannot resolve a
  provider from a `PZ_` reference, by design. That matters only on the last-resort branch of
  `verify()` - no cached session, no `payment_transactions` row, and no provider passed
  explicitly - where `verify()` now tries each enabled provider in turn rather than going
  straight to one. Slower in that narrow case, and still correct. Transaction logging is on by
  default and its table is part of the core install, so most applications never reach it.

  References you supply yourself are untouched, and so are references already stored.

- `ChargeRequestDTO::withReference()` is new. It returns a copy of the request carrying the
  given reference, preserving every other field including the idempotency key - the key
  identifies the logical submission, and stamping a reference onto a request must not change
  which submissions a provider treats as duplicates of each other.

- `ProcessWebhook` now also finds a refund nested under `payload.refund.entity`, and reads its
  transaction reference from `notes.payzephyr_reference`, which is where PayZephyr writes it on
  every refund it creates through Razorpay. Both are checked after every existing field.

  A refund created in Razorpay's own dashboard has no notes, so it falls back to `payment_id` -
  but only for Razorpay. Square's refund webhooks carry a `payment_id` too, and reading it there
  would have changed the transaction reference those events dispatch from `''` to a Square
  payment id. That scoping is enforced by a test.

- Removed the explanatory comments from `extractWebhookChannel()` in the PayPal and Square
  drivers. Comment-only: no logic changed, and the behaviour is exactly as before. PayPal
  still reports the real funding instrument, and Square still returns `null` instead of
  inventing `card` when the provider does not say. The reasoning behind that behaviour is
  recorded in the `3.0.0` entry below and in the [Upgrade Guide](upgrade-guide.md).

  **No user-facing impact.** Listed here for completeness rather than because it changes
  anything you can observe.

### Upgrading

**This release contains a breaking change**, so it should ship as a major version. Two things are
required of apps that use subscriptions; everything else is optional.

#### Required, if you use subscriptions

1. **Make `subscription_transactions.amount` nullable.** New installs get this from the published
   migration. Existing ones need a migration of their own, or a metered subscription will fail on
   insert:

   ```php
   Schema::table(config('payments.subscriptions.logging.table', 'subscription_transactions'), function (Blueprint $table) {
       $table->decimal('amount', 15)->nullable()->change();
   });
   ```

2. **Handle a null amount wherever you read one.** `PlanResponseDTO::$amount`,
   `SubscriptionResponseDTO::$amount` and `PlanResponseDTO::getAmountInMajorUnits()` are now
   `?float`. Arithmetic, `number_format()` and typed parameters that received them will need a
   null check. A null means the provider reported no fixed price - it is not zero, and treating it
   as zero reintroduces the bug this fixes.

#### Behaviour you may notice

- **`verify()` and refunds now throw where they used to report zero.** If a provider's response
  is missing its amount or currency, you get a `VerificationException` or `RefundException`
  naming the missing field, where PayZephyr previously returned an amount of 0.00. That exception
  means the provider's answer was incomplete - not that the payment failed. Verify again before
  treating it as a failure.

- **`updatePlan()` now throws `PlanException` for updates it used to accept.** An interval other
  than `daily`, `weekly`, `monthly` or `annually`, an amount of zero or less, or an empty name is
  refused before the provider is contacted. If you pass `yearly`, switch to `annually` - `yearly`
  was being billed monthly. On Stripe, changing the amount or interval of a metered price, or only
  the interval of a tiered price, is also refused: create those prices in Stripe directly.

- **Stripe refunds now throw `RefundException` where they could throw `ChargeException`.** If
  you catch `ChargeException` around refunds, catch `RefundException` instead. When the message
  says the refund may already have been created, check the Stripe dashboard before retrying.

#### Also worth a look before you deploy

1. **If you let PayZephyr generate references**, new ones look like `PZ_1755000000_a1b2c3d4`
   instead of `STRIPE_1755000000_a1b2c3d4`. Anything that parses a provider out of a reference
   you did not supply - dashboards, reconciliation scripts, support tooling - needs updating to
   read the `provider` column on `payment_transactions` instead. Existing references are
   unchanged.

2. **If you charge without a reference and re-submit the returned one**, that second submission
   is now rejected with a `ProviderException` while the first is still in flight, where it
   previously reached a provider. That is the fix working, but it is a new exception on a path
   that used to succeed.

3. **If you supply your own references**, nothing changes.

#### If you want tracing

```bash
php artisan payzephyr:install --features=trace
```

Then schedule the prune, in the same breath - this is the only PayZephyr table that grows per
step rather than per payment, and nothing prunes it on its own:

```php
Schedule::command('payzephyr:trace:prune')->daily();
```

`PAYZEPHYR_TRACE_ASYNC=true` is recommended in production to keep the write off the request path,
and `php artisan payzephyr:trace <reference> --detailed` is the thing you'll actually reach for.
Read [Tracing](tracing.md) before turning it on in production, particularly
[what it stores](security.md#what-tracing-stores) - by default that includes provider request and
response bodies, redacted.

If it ever needs to stop, `PAYZEPHYR_FEATURE_TRACE=false` takes effect on the next request. No
deploy, no migration rollback.

---

## [3.0.3] - 2026-08-18

### Fixed

- **Stripe verify returned internal SDK state instead of your metadata.** The v3.0.2 work
  left Stripe out of the shared metadata helper because it cast with `(array)` rather than a
  bare null-coalesce, so it could not crash. It could still be wrong, and it was.

  `StripeObject` keeps its values in protected internals, so `(array)` does not return the
  metadata. It returns the object's own property table with null-byte-mangled keys
  (`"\0*\0_values"`, `"\0*\0_lastResponse"`, and so on). Every Stripe verify has been handing
  that back as the caller's metadata.

  This never threw, which is why it went unnoticed while the Paystack empty-string crash was
  obvious. A loud bug gets reported; a quiet one just produces wrong data.

  Both Stripe paths, checkout sessions and payment intents, now go through the shared helper.

  **What to check:** if you read `metadata` off a Stripe verification and found it empty or
  full of odd keys, it will now contain what you actually set. Anything reading it defensively
  keeps working.

### Changed

- The metadata helper now understands objects that expose `toArray()`, with a
  `JsonSerializable` fallback. It matches on capability rather than class name, so it handles
  Stripe's objects without the shared trait depending on the Stripe SDK, and any other SDK
  value object gets the same treatment automatically.

### Upgrading

Nothing to do. No API, signature, or configuration changes.

---

## [3.0.2] - 2026-08-15

### Fixed

- **`verify()` crashed whenever a provider returned metadata as an empty string.**
  Reported from production against a live Paystack sandbox: a charge created *with* metadata
  verified fine, while the identical charge created *without* metadata failed every time.

  Metadata was read with a plain null-coalesce, which only catches `null` and missing keys.
  Paystack returns an empty string when a transaction has no metadata, and that string passed
  straight into `VerificationResponseDTO`'s typed `array` parameter, fataling with a
  `TypeError` before the constructor body ran. Because the drivers wrap `Throwable`, it
  surfaced as a `VerificationException`, so every verify of a metadata-less charge failed.

  Affected `verify()` on Paystack, Flutterwave, Mollie, Monnify, and OPay. Stripe was already
  safe because it casts with `(array)`.

- **The same crash was in all nine `fromArray()` DTO factories.** Those are public API, so
  anyone rebuilding a DTO from a provider payload or from stored JSON hit it too.

- **A JSON-string metadata value is now decoded instead of discarded.** Some providers encode
  metadata as a JSON string rather than an object. Treating every non-array as empty would
  have stopped the crash while silently losing the caller's own data, which is a quieter bug
  than the original.

- **`PaymentManager` carried two near-copies of this normalisation, and they had drifted.**
  One decoded JSON strings, the other did not. The second is the path that reads
  `_provider_id` to route verification, so with string-encoded metadata it silently lost the
  provider id and fell back to the raw reference. Both now share one implementation.

### Added

- `Traits\NormalizesMetadata`, one shared helper used by every driver and DTO instead of
  fourteen separate inline guards. Drivers extending `AbstractDriver` inherit it
  automatically, so a new driver gets the correct behaviour without repeating it.

### Upgrading

Nothing to do. No API, signature, or configuration changes. Charges and verifies that already
worked behave identically; ones that crashed now succeed.

---

## [3.0.1] - 2026-08-14

Documentation and packaging only. No code, behaviour, or API changes.

### Fixed

- **The README shipped inside the package pointed at the wrong version.** It said
  "v2.0.0 contains breaking changes: read the upgrade notes", which is what someone who had
  just installed v3.0.0 would read in `vendor/kendenigerian/payzephyr`. It now names the
  current release and its one breaking change.
- **The upgrade guide had no v3.0.0 section**, despite v3.0.0 being the release with the
  breaking change. Added, leading with a decision flowchart, since most applications need to
  do nothing.
- **Two working files were shipping to consumers.** `payzephyr-audit-final-report.md` and
  `PR_BODY.md` were committed by mistake and are not `export-ignore`d, so both landed in every
  consumer's vendor directory. Both removed. Their content remains in git history.
- Fixed "refunds across every providers" in the installer example in the installation guide.
- Fixed the architecture diagram in the README: it used `\n`, which Mermaid does not render as
  a line break, and hardcoded a provider count that goes stale each time one is added.

### Added

- **[Extending a Driver](extending-drivers.md)**: how to add refunds and subscriptions to a
  custom driver. Every method both interfaces require, every field of every DTO, worked
  examples, error-handling rules, webhook wiring for renewals, and what to test. This was
  previously a single paragraph telling readers to go and read the source.
- A section in the README setting out what a driver inherits automatically (fallback,
  double-charge protection, retry safety, webhook verification, logging, events, health
  checks) versus the part the author actually writes.

### Changed

- Documentation now reads consistently throughout: em dashes removed, internal architecture
  decision records no longer referenced from user-facing chapters (the reasoning is written
  inline instead, so each chapter stands on its own), and the remaining ASCII flow diagram
  converted to Mermaid.

---

## [3.0.0] - 2026-08-13

Second and final pass of the production-readiness audit, closing the caller double-submission
gap the first pass identified but deliberately deferred. Entries below are additional to the
first pass's, recorded further down under the same version (nothing from the first pass has
been released yet).

### Added

- **`docs/idempotency.md`** - precise documentation of what PayZephyr does and does not
  guarantee about duplicate submissions, retries, and exactly-once semantics. It states
  plainly that exactly-once payment processing is *not* guaranteed and explains the three
  layers that do exist. Worth reading before relying on any of them.

### Fixed

- **A retry of the same logical payment reached providers under a different idempotency key.**
  `ChargeRequestDTO::fromArray()` minted a fresh random UUID on every call, even when the
  caller supplied the same `reference` both times - so a retry after a lost or ambiguous
  response looked like a brand new request to every provider, defeating provider-side
  idempotency in exactly the case it exists to protect. The idempotency key is now derived
  from the caller's `reference` when no explicit key is given. An explicit
  `->idempotency($key)` still always wins.
- **Concurrent submissions of the same payment could both reach a provider.** Nothing
  serialised two in-flight charges for the same reference. `chargeWithFallback()` now
  atomically claims the reference before contacting any provider, and rejects a second
  submission with a `ProviderException` rather than charging again. The claim is held on
  success and on an ambiguous outcome, and released only on a definitive failure so
  legitimate retries still work. Requires a shared atomic cache store (database/redis/
  memcached) to protect across processes - documented in `docs/idempotency.md`.
- **An unrecognised refund status silently released the entire refundable balance.**
  `RefundResponseDTO::getStatus()` fell back to `FAILED` for a provider status string it did
  not recognise, and `FAILED` is excluded from `sumRefundedAmount()` - so an unknown status
  made a payment look fully refundable again and a second refund could over-spend the
  captured amount. It now falls back to `PENDING`: still never treated as success, but
  counted toward the refunded total and non-terminal so a later webhook can resolve it.
- **An ambiguous refund outcome released the in-flight lock**, letting an immediate retry
  issue a second real refund when the provider may already have processed the first.
  `Refund::refund()` now holds the lock and raises a reconcile-don't-retry error instead.
  Ambiguity detection is now shared between `ChargeException` and `RefundException` via
  `DetectsAmbiguousProviderOutcome`, which walks the full exception chain - refunds wrap the
  underlying network exception one level deeper than charges do, so the previous
  single-level check would not have detected it.

- **A driver implementing only `DriverInterface` broke every charge, and the failure was
  silently misreported.** `chargeWithFallback()` called `isCurrencySupported()` and
  `getCachedHealthCheck()`, neither of which is declared on `DriverInterface` - they exist
  only on `AbstractDriver`. Since `DriverFactory::create()` and `PaymentManager::driver()`
  are both typed to the interface, and `docs/custom-drivers.md` documents the interface as
  the contract, a pure-interface driver is legitimate - and hit
  `Error: Call to undefined method` mid-charge. Worse, the fallback loop's
  `catch (Throwable)` swallowed that into a generic "All payment providers failed", so in a
  multi-provider setup the custom driver was silently skipped and payments quietly routed to
  a different provider. The same call also made `/payments/health` report a perfectly healthy
  custom driver as unhealthy.

  Both call sites now resolve through `PaymentManager::driverIsHealthy()` and
  `driverSupportsCurrency()`, which prefer the driver's own implementation when present and
  otherwise derive the identical answer from `healthCheck()` / `getSupportedCurrencies()` -
  both of which *are* on the interface. No interface change was needed, so third-party
  drivers keep working unchanged.

  This had been actively hidden: `phpstan.neon` carried an
  `ignoreErrors` entry silencing exactly this diagnostic, with no justifying comment (every
  other suppression in that file has one). The suppression has been removed rather than
  updated - and removing it immediately surfaced the second occurrence in
  `PaymentServiceProvider`, which the silencer had also been covering.

- **All five `phpstan.neon` `ignoreErrors` suppressions removed; the `ignoreErrors` block no
  longer exists.** Two were already dead (`environment()` and `bound()` *are* on the
  Application/Container contracts). The other three were hiding real issues:
  - `auth()->check()` / `auth()->id()`: the Auth *Factory* contract exposes only `guard()`
    and `shouldUse()`; `check()`/`id()` live on the Guard it resolves. Now calls
    `auth()->guard()->check()`. Same guard at runtime, but type-safe.
  - `new static()` in `PaymentException::withContext()`, replaced with a
    `@phpstan-consistent-constructor` annotation, which PHPStan *enforces*: adding a subclass
    with a different constructor signature is now an error rather than a silent hazard.
  - `PaymentServiceProvider::registerRoutes()` called `routesAreCached()`, which exists only
    on the concrete `Foundation\Application`, not the contract `ServiceProvider::$app` is
    typed to. Now narrowed with an `instanceof` check that falls through to registering
    routes on a non-standard container. Routes present when they could have been cached is
    harmless; routes missing is not.

### Changed

- **Webhook channel extraction now reports the real payment instrument** (behavior change).
  `PayPalDriver::extractWebhookChannel()` returned a hardcoded `'paypal'` without reading the
  payload at all, so a card-funded, Venmo-funded, and balance-funded payment were all recorded
  identically, and redundantly with `provider = 'paypal'`. It now reads
  `resource.payment_source` and returns the instrument key (`card`, `paypal`, `venmo`, …), or
  `null` when PayPal reports none. `SquareDriver::extractWebhookChannel()` similarly defaulted
  a missing `source_type` to `'card'`, recording card payments for instruments that were never
  cards; it now returns `null` when the field is absent or empty.

  Both previously satisfied their `?string` signature only because a PHPStan suppression hid
  that they could never actually return `null`. Applications reading `channel` off PayPal or
  Square transactions will now see accurate instrument values (or `null`) instead of a
  constant. More useful, but different from before, hence noted as a behavior change.

- `ChannelMapper::mapToPayPal()` removed; the `paypal` match arm returns `null` directly.
  PayPal's Orders v2 API has no funding-source restriction parameter, so the method could only
  ever return `null`. The indirection just obscured that.

- **The post-success invariant is now enforced structurally rather than by convention.**
  Every post-charge side effect moved into `PaymentManager::completeSuccessfulCharge()`,
  which cannot throw. Previously each risky call was individually wrapped, so a future edit
  adding an unwrapped line between the provider call and the return would silently
  reintroduce the double-charge bug.
- `EloquentRefundRepository`'s refunded-total query now derives its status list from
  `RefundStatus::countsTowardRefundedAmount()` instead of hardcoding the strings, so a new
  status cannot silently escape the over-refund guard.
- Documentation no longer hardcodes the bundled provider count ("all 8 providers" ->
  "every bundled provider"), which was guaranteed to go stale.

---
---

*Remainder of 3.0.0, from the first audit pass (2026-08-12).*

Production-readiness audit following 2.1.0's refund/feature-selective-install work. Every item below
was found and fixed with a test-first regression test in the same audit pass - see the audit's Payment
Safety Matrix for how each was verified.

### Breaking

- **`WebhookEventRepositoryInterface` gained a new required method, `forget(string $provider, string $eventKey): void`.**
  Any application binding a custom implementation of this interface (via `$this->app->bind(WebhookEventRepositoryInterface::class, ...)`)
  must add this method before upgrading. See "Fixed" below for why it exists. The bundled
  `EloquentWebhookEventRepository` already implements it - no action needed if you use the default binding.

### Fixed

- **Duplicate-charge risk in `PaymentManager::chargeWithFallback()`**: post-charge bookkeeping (session
  cache write, `PaymentInitiated` event dispatch) ran *inside* the same try block as the provider call, so a
  failure in bookkeeping *after* a successful charge was caught by the fallback loop's own error handling and
  retried against the next configured provider - charging the customer twice. Bookkeeping now runs in its own
  isolated try/catch that can never trigger a fallback retry.
- **Ambiguous network-timeout charges no longer fall back to a second provider.** A charge request that times
  out or loses its response before confirming success is not distinguishable from one the provider actually
  processed - retrying it against a different provider risked a double charge. `ChargeException::isAmbiguousProviderOutcome()`
  now detects this case (no HTTP response was ever received, or the Stripe SDK reports a connection-level
  failure) and `chargeWithFallback()` stops immediately with a clear `ProviderException` instead of trying
  another provider.
- **A webhook delivery that fails mid-processing can now actually be retried.** `ProcessWebhook` records a
  delivery as "seen" before processing it (so a genuinely concurrent duplicate is rejected immediately), but a
  failure afterward (a `WebhookReceived` listener throwing, a transient DB error) left that marker in place
  forever - so the job's own configured retries (`$tries`/`$backoff`) would see the delivery as "already
  processed" and silently skip every retry. The catch block now clears the marker on failure via the new
  `forget()` method (see Breaking, above) so a genuine retry reprocesses the delivery.
- **Concurrent refund requests for the same transaction could both reach the provider.** The existing
  duplicate-refund guard (`RefundValidator::hasInFlightRefund()`) only sees a `pending` `refund_transactions`
  row once a *previous* refund's provider call has already returned - so two refund requests submitted close
  enough together (a double-clicked "refund" button, two app processes handling a retried request) could both
  pass that check before either had written anything, and both proceed to call the provider. `Refund::refund()`
  now also claims an atomic, short-lived cache lock (`Cache::add()`) before calling the provider, closing that
  window. Requires a cache store that's actually shared and atomic across processes (database/redis/memcached)
  to protect multi-process deployments - the `array` driver is process-local and provides no cross-process
  protection.
- **`StripeDriver::charge()`/`verify()` only caught `Stripe\Exception\ApiErrorException`**, unlike every other
  driver's charge/verify methods, which also catch a generic `Throwable` as a safety net. A non-SDK exception
  (e.g. accessing an unexpectedly-shaped SDK response object) would previously propagate raw instead of as a
  clean `ChargeException`/`VerificationException`. Now consistent with the other seven drivers.
- **`FlutterwaveDriver::charge()` silently sent `redirect_url: null` to Flutterwave when no callback URL was
  configured**, instead of failing fast the way Stripe and PayPal already do for the same situation. Now
  throws `InvalidConfigurationException` with the same guidance those two drivers give.
- **`SquareDriver::healthCheck()` and `MonnifyDriver::healthCheck()` logged nothing on failure**, unlike the
  other six drivers' health checks. Both now log on every branch, and Square's now catches `Throwable` instead
  of only `ChargeException`, matching the rest.

### Also fixed (non-behavioral)

- PHPStan: `WebhookEvent` model was missing `@method` annotations for `where()`/`delete()`, used by the new
  `forget()` method above.
- PHPStan: dead `$envVar = null` assignment in `UninstallCommand::describeResource()` that made its own `??`
  fallback unreachable.

---
## [2.1.0] - 2026-08-12

> **Never tagged, and intentionally not installable.** Everything below shipped as part of
> `3.0.0`. It is recorded under its own heading because the work is a distinct, self-contained
> feature set, not because a `v2.1.0` release exists - the tag history goes `v2.0.2` -> `v3.0.0`.
>
> The tag was withheld deliberately. This commit predates the payment-safety fixes in `3.0.0`,
> so publishing it would have made a version with known duplicate-charge and double-refund
> defects resolvable for anyone on a `^2.0` constraint - and a *newer* one than `v2.0.2`, so
> Composer would have upgraded them into it.

### Added

- **Refund support across all 8 providers** (Paystack, Stripe, PayPal, Flutterwave, Square, Mollie, Monnify,
  OPay). Unlike subscriptions, every provider PayZephyr talks to has a real refund endpoint, so this shipped
  in one release rather than rolling out incrementally. Per-provider mapping decisions are covered in the refunds chapter.
  - New fluent API: `Payment::refund($transactionReference)->amount(...)->currency(...)->reason(...)->with($provider)->refund()`,
    plus `->fetch($refundReference)`. Supports full and partial refunds. `->currency()` is required for
    multi-currency merchants on Square, PayPal, Mollie, and OPay, whose refund APIs need it specified
    explicitly rather than inferring it from the original charge.
  - New `SupportsRefundsInterface` (opt-in, following the same pattern as `SupportsSubscriptionsInterface`),
    `RefundRequestDTO`/`RefundResponseDTO`, `RefundException`, `RefundStatus` enum.
  - Refunds are automatically logged to a new `refund_transactions` table (see
    [Configuration](configuration.md#refunds)), with both metadata and the `->reason()` value sanitized
    before persisting.
  - Refund validation (`payments.refunds.validation.enabled`, on by default) runs two checks before calling
    the provider: an in-flight duplicate guard (`payments.refunds.prevent_duplicates`, on by default) that
    rejects a second refund attempt while an earlier one on the same transaction is still pending/processing,
    without blocking legitimate sequential partial refunds once it resolves; and an over-refund check that
    validates a refund's amount against the original transaction's remaining refundable balance when the
    original charge was logged locally (best-effort).
  - New `RefundCreated`/`RefundCompleted`/`RefundFailed` events, dispatched from webhook processing for the
    providers (Paystack, Square, Monnify, OPay, and some Stripe/Mollie payment methods) that confirm refunds
    asynchronously rather than in the initial API response.
  - New documentation: [docs/refunds.md](refunds.md).
- **Feature-selective installation.** `php artisan payzephyr:install` previously published and offered to run
  migrations for every table unconditionally, forcing `subscription_transactions`/`refund_transactions` onto
  every install regardless of whether the app uses those features. The installer now distinguishes core
  (`payment_transactions`, `webhook_events` - both depended on unconditionally by default config) from
  optional (Subscriptions, Refunds - neither depends on the other), and only installs what's selected.
  - Interactive: one confirmation per optional feature, pre-selected based on what's already installed.
  - Non-interactive: `--all` installs every optional feature; `--features=subscriptions,refunds` installs
    exactly the named ones (case-insensitive, rejects unknown names clearly); `--no-interaction` alone with
    neither flag installs core only - "install everything" is never the silent non-interactive default.
  - Re-running the installer is always safe and strictly additive: already-installed features are left alone,
    a feature can be added later without touching existing tables or data, and declining an already-installed
    feature never removes it. New selections are recorded in `.env` (`PAYZEPHYR_FEATURE_SUBSCRIPTIONS`/
    `PAYZEPHYR_FEATURE_REFUNDS`) and exposed via the new `config('payments.features')` (informational only -
    doesn't gate the fluent API at runtime).
  - New granular `vendor:publish` tags (`payzephyr-migrations-core`/`-subscriptions`/`-refunds`); the original
    `payments-migrations` tag (publishes everything) is unchanged for backward compatibility.
  - New single source of truth: `Console\Features` registry (also resolves feature dependencies, though none
    exist between Subscriptions and Refunds today).
  - See [Installation: core vs. optional features](installation.md#core-vs-optional-features).
- **`php artisan payzephyr:uninstall`.** Feature-aware, explicitly destructive counterpart to the installer:
  drops the tables PayZephyr owns, removes the migration files it published, and clears the `.env` feature
  flags it wrote - nothing else. `config/payments.php` is never touched (it may contain your own
  customization, and there's no general "unpublish" concept to safely reverse).
  - Refuses to run in a non-interactive environment without `--force`. Interactively, requires confirming
    twice: a yes/no prompt, then typing `UNINSTALL` verbatim.
  - `--features=refunds` removes only the named optional feature, leaving core and every other feature
    untouched; omit it to remove everything, including core. Only accepts optional feature names (the same
    registry `--features=` on the installer validates against) - core can't be selectively uninstalled while
    leaving optional feature tables behind.
  - Feature-aware: never attempts to touch a table/migration that was never installed in the first place.
  - Also removes the corresponding row from Laravel's own migration-tracking table, so a feature removed this
    way and reinstalled later gets its table recreated by `migrate` instead of being silently skipped as
    "already run."
  - Safe to run repeatedly: a second run against an already-uninstalled feature reports there's nothing to do
    rather than erroring.
  - See [Installation: uninstalling PayZephyr](installation.md#uninstalling-payzephyr).
- **Polished installer UI using Laravel Prompts.** `payzephyr:install`'s feature selection is now a single
  `multiselect` prompt (checkboxes, already-installed features pre-checked) instead of one yes/no confirmation
  per feature, plus an intro/outro and a pre-migration summary of what's about to run. Laravel Prompts is a
  hard dependency of `laravel/framework` itself, so no new package dependency was added; it automatically
  falls back to standard Artisan-style prompts on Windows and in non-interactive/test environments (Laravel's
  own `ConfiguresPrompts` handles this transparently), so nothing about `--no-interaction`, `--features=`, or
  `--all` behavior changed.

### Fixed

- **Refund status was persisted to `refund_transactions` as the provider's raw, un-normalized string instead
  of the canonical `RefundStatus` value** (e.g. Square/PayPal/Monnify's `"PENDING"`/`"COMPLETED"`, Paystack's
  `"processed"`, Stripe's `"succeeded"`, Mollie's `"refunded"`). `EloquentRefundRepository::hasInFlightRefund()`
  and `sumRefundedAmount()` match against the fixed lowercase set `['pending','processing','completed']`, so
  for most providers this **silently disabled the duplicate-refund and over-refund guards** documented in
  [Refunds: preventing over-refunds and duplicates](refunds.md#preventing-over-refunds-and-duplicates) - the
  checks always saw an empty/zero refund history for that transaction regardless of what had actually been
  refunded. Fixed by normalizing via `RefundResponseDTO::getStatus()->value` before persisting.
- **A refund confirmed by webhook never updated the locally-persisted `refund_transactions` row.**
  `ProcessWebhook::processRefundWebhook()` dispatched `RefundCompleted`/`RefundFailed` but never wrote the
  resolved status back to the database, so for any provider confirming asynchronously - exactly the case
  [Refunds](refunds.md#listening-for-refund-events) recommends listening for these events over polling
  `fetch()` - the local row stayed `pending` forever, permanently tripping the in-flight duplicate guard for
  that transaction. Fixed with a new `RefundRepositoryInterface::updateStatusIfExists()` (mirrors
  `TransactionRepositoryInterface::updateIfNotSuccessful()`'s concurrency-safe, no-op-if-missing pattern, and
  never regresses an already-terminal status from a replayed/out-of-order webhook).
- **`FlutterwaveDriver::fetchRefund()` queried the wrong endpoint.** It called
  `GET /v3/transactions/{id}/refunds` - a *transaction*-id-keyed endpoint that lists every refund against a
  transaction - using the *refund's own* reference as the transaction id. Since refund IDs and transaction IDs
  are different Flutterwave ID sequences, this could return refund data for an unrelated transaction that
  happened to share that numeric ID. Fixed to call `GET /v3/refunds/{id}` (verified against Flutterwave's API
  documentation), which fetches a single refund by its own ID directly.
- **A full (no explicit amount) Square refund omitted the required `amount_money` field.** Unlike the other
  seven providers, Square's `CreateRefund` API has no "omit amount for a full refund" semantics -
  `amount_money` is required unconditionally (verified against Square's API documentation). `SquareDriver`'s
  full-refund path now looks up the original payment first to determine the amount to refund, and fails with
  a clear `RefundException` if that lookup fails rather than sending an incomplete request.
- **PayPal refunds always formatted the amount with 2 decimal places**, even for zero-decimal currencies
  (JPY, KRW, ...), where PayPal's API expects e.g. `"5000"` rather than `"5000.00"`. `PayPalDriver::charge()`
  already computed per-currency decimals via `getCurrencyDecimals()`; the refund path now uses the same logic.
- Minor: `StripeRefundMethods` accessed `$refund->amount` without a null-coalesce, unlike every other driver's
  defensive `?? 0` pattern around provider response fields.
- `guzzlehttp/guzzle`/`guzzlehttp/psr7` and `league/commonmark` dev lockfile entries refreshed to their latest
  CVE-fixed versions (flagged as M-1 in the pre-2.0.0 audit for guzzle; commonmark surfaced separately during
  this pass). Both are dev-only in this package's dependency tree and don't propagate to a consuming app's
  install, but the lockfile hygiene is worth keeping current.
- `stripe/stripe-php` bumped from `^13.0` (8 majors, ~2 years behind) to `^21.0` - flagged as a suggestion
  (S-1) in the pre-2.0.0 audit. Verified against the package's actual Stripe SDK usage (`StripeClient`,
  `Webhook`, the SDK's exception hierarchy): full test suite and static analysis pass unchanged against v21.2.0.
- **A full (no explicit amount) Monnify refund omitted the required `refundAmount` field**, the same class of
  bug as the Square one above. Monnify's own API documentation is explicit: `refundAmount` is required, and
  "for a full refund, set this to the full transaction amount" rather than allowing it to be left out.
  `MonnifyDriver`'s full-refund path now looks up the original transaction via `verify()` first (the trait's
  own docblock already documents `$transactionReference` as the same reference `verify()` queries by).
- `RefundStatus::fromString()` now recognizes Square's `"REJECTED"` refund status (verified against Square's
  Refunds API docs) as `FAILED`. It previously fell through to `FAILED` only via the DTO's unrecognized-status
  fallback, not through explicit recognition.
- `Stripe\Price::$unit_amount` and stripe-php v21's other stricter API-parameter type stubs surfaced a couple
  of `null`-vs-`int` mismatches in `StripeSubscriptionMethods::updatePlan()`'s new-Price-on-amount-change path,
  a direct consequence of the SDK bump above - fixed with an explicit cast (behaviorally identical for the
  `per_unit` prices this package ever creates; only tiered-billing prices, which this package doesn't support,
  would ever have a genuinely null `unit_amount`).
- New opt-in `php artisan payzephyr:refunds:normalize-status` (`--dry-run` supported) - a one-time backfill for
  `refund_transactions` rows written *before* the status-normalization fix above, whose `status` column may
  still hold a raw, un-normalized provider string. Not run automatically: whether to rewrite existing
  production data is the application owner's call. Idempotent; reports (without guessing at) any row whose
  status can't be mapped to a known `RefundStatus`.
- `phpstan/phpstan` bumped from `1.12.34` (65 releases behind) to `^2.2`. Surfaced 37 previously-invisible
  findings at the project's existing level-6 configuration - almost all incomplete generic-array type
  annotations on `@method`/`@property` docblocks (Models, the `Payment` facade), plus a handful of genuinely
  dead code (redundant `!==` comparisons, an always-true `is_object()` check, two `Http\Resources` classes
  using an inline `@var $this` trick PHPStan 2.x now correctly rejects). All fixed; the remaining 3 findings
  (two drivers' `extractWebhookChannel()`, `ChannelMapper::mapToPayPal()`) are deliberate false positives -
  narrowing just those implementations' return types would break the uniform interface contract shared across
  all drivers/mappers - and are now explicit, commented `ignoreErrors` entries rather than silent noise.

- **Webhook signature verification narrowed its `catch` to `Exception` instead of `Throwable` in four sites**
  (`StripeDriver::validateWebhook()`, two in `PayPalDriver`, `MonnifyDriver::healthCheck()`) - a latent gap
  flagged in the pre-2.0.0 release audit (M-2): not currently exploitable (an outer `catch (Throwable)` at
  every call site already covered it), but a trap for a future refactor. All four now catch `Throwable`.
- **`HasWebhookValidation`'s webhook-timestamp field matching accepted any numeric value under a matched
  field name** (including the generic `"time"`), with no check that the value was plausible as an actual
  Unix timestamp - flagged in the pre-2.0.0 audit (M-4). A future payload with an unrelated small numeric
  field (a counter, a duration) under an earlier-matched name like `created` could have both misreported a
  bogus timestamp and caused a legitimate webhook's real timestamp (in a later field) to be ignored,
  producing a false-positive rejection. Fixed by requiring a matched value to fall within a plausible
  calendar-year range (2000-2100) before accepting it, otherwise the matcher keeps checking later field
  names.

---
## [2.0.2] - 2026-08-11

### Fixed

- **`docs/`, `README.md`, and `CHANGELOG.md` were excluded from distributed installs.**
  `.gitattributes` marked them `export-ignore`, which strips them from the archive a
  normal Composer dist install downloads - the default, and what most production deploy
  pipelines use via `--prefer-dist`. Consumers that read this content directly out of
  `vendor/kendenigerian/payzephyr` (the package's own developer portal, notably) had
  nothing to read on a fresh install. `tests/`, `.github/`, and the other dev-only paths
  remain excluded - only the three paths a normal consumer might actually want are
  affected.

---
## [2.0.1] - 2026-08-01

### BREAKING

- **Dropped support for Laravel 10.x and 11.x.** PayZephyr now requires Laravel 12.x or
  13.x. Both dropped majors are now past their upstream security-fix window (Laravel 10:
  ended 2025-02-04; Laravel 11: ended 2026-03-12), meaning every version in those ranges
  has at least one permanently-unpatched security advisory - Composer's advisory-blocking
  policy was refusing to install them in CI at all. `illuminate/database`,
  `illuminate/http`, and `illuminate/support` now require `^12.0|^13.0`;
  `orchestra/testbench` requires `^10.0|^11.0`; `pestphp/pest` and
  `pestphp/pest-plugin-laravel` require `^3.0|^5.0`. CI's test matrix no longer runs
  Laravel 10.*/11.* jobs. See [Upgrade Guide](../docs/upgrade-guide.md#laravel-10x-and-11x-are-no-longer-supported).

### Fixed

- **`SubscriptionQuery`'s filter methods (`whereStatus()`, `active()`, `cancelled()`,
  `forPlan()`, `createdAfter()`, `createdBefore()`) crashed with a fatal
  `Error: Cannot use object ... as array` for every subscription-capable provider except
  Paystack.** Paystack's `listSubscriptions()` returns raw provider arrays, but the five
  other subscription-capable drivers added in v2.0.0 (Stripe, PayPal, Flutterwave, Square,
  Mollie) return `SubscriptionResponseDTO` objects - `applyFilters()` was written only
  against the Paystack shape and array-indexed every result unconditionally. Fixed by
  normalizing both shapes to a common view before filtering; `createdAfter()`/
  `createdBefore()` are now a documented no-op against DTO-shaped results (the DTO carries
  no creation timestamp) rather than crashing.

### Coverage

- Added regression tests for the `SubscriptionQuery` fix above, plus tests for the five
  subscription lifecycle event classes and `SubscriptionValidator`'s duplicate-prevention,
  terminal-state, and authorization-code-format branches - all previously untested.

---
## [2.0.0] - 2026-08-01

### Added

- **Laravel 13 support.** `illuminate/database`, `illuminate/http`, and `illuminate/support`
  now accept `^13.0`; `orchestra/testbench` accepts `^11.0`; `pestphp/pest` and
  `pestphp/pest-plugin-laravel` accept `^5.0` (required to test against Laravel 13 - its
  test tooling needs PHP 8.4+, one version ahead of Laravel 13's own PHP 8.3+ minimum).
  Verified empirically, not just by resolving dependencies: the full test suite (1275
  tests) was run against a real Laravel 13.23.0 / Pest 5.0.2 / PHPUnit 13.2.6 install and
  passes cleanly. CI's matrix now includes two dedicated Laravel 13 entries (PHP 8.4 and
  8.5).

### Fixed

- **`phpunit.xml`'s `<coverage>` block silently zeroed out every test run under PHPUnit
  13** (bundled with Pest 5, required for Laravel 13 testing). When no coverage driver
  (Xdebug/PCOV) is installed, PHPUnit 13 doesn't just warn about the missing driver the
  way 10.5/11.x did - it aborts before executing a single test, while still exiting `0`
  as if the run succeeded. Confirmed via a real regression: a trivial test writing a
  marker file never ran, despite `pest` reporting no visible failure at all. Fixed by
  removing the always-on `<coverage>` block from `phpunit.xml` and generating coverage
  reports via CLI flags instead (`--coverage-clover`, `--coverage-html`, etc.), which is
  what the dedicated CI coverage job and the `composer test-coverage` script already used
  underneath the XML config. This would have silently broken CI's main test job the
  moment it picked up a Laravel 13 combination, independent of anything else in this
  entry - worth knowing if any other Laravel package sees tests "pass" with suspiciously
  little output after a PHPUnit 13 upgrade.

### Known gaps

- **PHPStan 1.x cannot fully analyze this codebase against `illuminate/http` ^13.0.**
  Locally installing Laravel 13 surfaces 8 PHPStan errors in `WebhookRequest`,
  `WebhookController`, `HealthEndpointMiddleware`, and `Console\InstallCommand` -
  `getContent()`/`$headers` reported as undefined, a `JsonResponse`-vs-`Response` return
  mismatch, an undefined `Command::SUCCESS` constant. All four are PHPStan 1.x's bundled
  Symfony/Console stubs failing to resolve Symfony 8.x's actual class hierarchy (which
  Laravel 13 pulls in) - not real defects: all 1275 tests exercise exactly these
  methods/properties and pass. PHPStan 2.x resolves this but introduces 29 unrelated,
  stricter-analysis findings across the codebase - deliberately left as a separate,
  future PHPStan-2.x migration rather than bundled into Laravel 13 support. PHPStan is
  not part of CI (`composer analyse` is a local/manual gate only), so this doesn't affect
  automated verification - only local `composer analyse` runs against a Laravel-13-only
  install.


### BREAKING

- **`Contracts\SupportsSubscriptionsInterface::cancelSubscription()` /
  `enableSubscription()`** now take a single `DataObjects\SubscriptionActionDTO $action`
  instead of `(string $subscriptionCode, string $token)`. `$token` was Paystack-specific
  and forced every other provider's driver to accept a parameter it couldn't use. Read
  provider-specific parameters via `$action->option('token')`.
  - **Not affected**: the public fluent API (`Subscription::cancel(?string $token =
    null)`, `->enable()`, `->token()`) keeps its exact existing signature - only direct
    callers of the driver interface, or custom driver implementations, need to update.
- **`Services\SubscriptionValidator::validateCancellation()`** dropped its `string $token`
  parameter - it now only performs the provider-agnostic terminal-state check. Token
  format validation moved into `PaystackSubscriptionMethods`, where it actually belongs.
- **PayPal webhook signature verification is now asynchronous.** A request with an
  invalid PayPal webhook signature now receives `202` (queued) instead of a synchronous
  rejection status - verification happens inside the `ProcessWebhook` job and invalid
  deliveries are silently discarded there. This removes two outbound HTTP calls
  (OAuth token fetch + PayPal's verify-webhook-signature API) from the request/response
  cycle.
- **NOWPayments support removed entirely** - not deprecated, not soft-disabled.
  `NowPaymentsDriver`, the `payments.providers.nowpayments` config block, and all
  provider-mapping registrations are gone. Any application with
  `PAYMENTS_DEFAULT_PROVIDER=nowpayments` or `PAYMENTS_FALLBACK_PROVIDER=nowpayments`,
  or code calling `Payment::with('nowpayments')`, breaks on upgrade. This is an
  intentional product decision to drop crypto payment support, not a technical
  deprecation with a replacement - remove NOWPayments usage before upgrading. See


### Added

- **Stripe and PayPal subscription support** (`SupportsSubscriptionsInterface` was
  previously only implemented by `PaystackDriver`). Full set of
  API-mapping decisions - Stripe Prices as plans (immutable amount/interval, so
  `updatePlan()` creates a new Price when either changes), Stripe's terminal `canceled`
  status being unrecoverable (`enableSubscription()` throws rather than approximating),
  PayPal's `/suspend`+`/activate` pair mapped to `cancelSubscription()`/`enableSubscription()`
  (reversible, matching Paystack's semantics - permanent cancellation via
  `$action->option('permanent', true)`), and why PayPal's `listSubscriptions()` throws
  (no such endpoint exists in PayPal's REST API).
  - `SubscriptionRequestDTO` gained an optional `callbackUrl` field (PayPal's
    subscription approval redirect requires one); `Subscription::callbackUrl()` sets it
    fluently. Additive, defaults to `null` - no existing call site changes.
- **Flutterwave, Square, and Mollie subscription support**, bringing subscription
  support to every driver whose provider actually offers a recurring-billing API. See
  Full API-mapping decisions and disclosed limitations:
  - **Flutterwave**: plans via `payment-plans`; subscribing is a side effect of a
    tokenized charge carrying `payment_plan` (requires `->authorization()` with a saved
    card token); cancel/enable map to confirmed `/cancel` and `/activate` endpoints.
    `updatePlan()` and single-subscription `fetchSubscription()` are pattern-matched
    from confirmed sibling endpoints, not independently verified - flagged for sandbox
    testing before production use.
  - **Square**: plans are a `SUBSCRIPTION_PLAN` + `SUBSCRIPTION_PLAN_VARIATION` Catalog
    object pair created via `/v2/catalog/batch-upsert`; subscribing requires
    `->authorization()` with a Square card-on-file ID; cancel/enable map to Square's
    reversible `/pause` and `/resume` endpoints, not the permanent `/cancel`.
  - **Mollie**: has no server-side plan resource at all, so `createPlan()` encodes the
    plan client-side into an opaque `planCode` and `listPlans()` throws; subscribing
    requires `->authorization()` with an existing mandate ID; `enableSubscription()`
    always throws (Mollie has no merchant-triggered resume); `listSubscriptions()`
    requires `$customer` (Mollie's list endpoint is customer-scoped). Subscription
    codes are encoded as `"{customerId}:{subscriptionId}"` since Mollie requires both
    IDs for every operation.
  - `MonnifyDriver` and `OPayDriver` remain unimplemented - neither provider exposes a
    provider-managed subscription API today (research behind
    this).
- **Event-level webhook idempotency** (`webhook_events` table): duplicate webhook
  deliveries - which gateways send routinely as part of retry/at-least-once semantics -
  are now deduped before any side effect runs, including subscription lifecycle event
  dispatch (`SubscriptionCreated`, `SubscriptionRenewed`, etc.), not just the
  transaction-status update. New migration:
  `2024_01_01_000002_create_webhook_events_table.php`.
- **Repository layer** (`TransactionRepositoryInterface`, `SubscriptionRepositoryInterface`,
  `WebhookEventRepositoryInterface`): `PaymentManager` and `ProcessWebhook` no longer call
  Eloquent statics directly, closing the DIP gap that made them hard to unit test.
- **`DataObjects\SubscriptionActionDTO`**: generic carrier for subscription action
  parameters (see BREAKING above).
- **`Contracts\RequiresAsyncWebhookVerification`**: interface for drivers whose webhook
  verification requires an outbound API call. `PayPalDriver` always defers;
  `MollieDriver` defers only when no `webhook_secret` is configured (its API-fallback
  path)

### Fixed

- **Replay-window bypass**: a webhook with no recognizable timestamp field used to be
  treated as valid ("missing = trust it"). It's now rejected. This required fixing
  timestamp *extraction* itself first - the previous implementation never actually found
  a timestamp for 5 of 9 providers, because it only checked the wrong (flat, top-level)
  location in the payload.
- **`SubscriptionTransaction` race condition**: concurrent webhook deliveries for a
  brand-new `subscription_code` could silently drop one delivery's data (unique
  constraint violation swallowed by a broad catch block). Now uses the same lock +
  create-race retry pattern already proven correct for `PaymentTransaction`.
- **TLS verification could be disabled via config** (`testing_mode`). Removed entirely -
  outbound requests to payment providers always verify certificates. Tests use
  `AbstractDriver::setClient()` to inject a mock HTTP client instead.
- **Unbounded recursion in log sanitization** could exhaust memory on deeply nested,
  attacker-influenced log context. Depth-capped, matching the limit already applied to
  persisted transaction metadata.
- **`TypeError` on logging a plain array**: `HasLogSanitization` called a
  string-typed method with a numeric array key under `declare(strict_types=1)`,
  crashing the log call itself for any context containing a plain list.

### Changed

- **DriverFactory - OCP Compliance Improvement**
  - Removed hardcoded special cases for driver class resolution (opay, nowpayments, paypal)
  - All providers now explicitly define `driver_class` in config for consistency
  - Maintains OCP (Open/Closed Principle) - new providers require only config changes
  - Convention-based resolution remains as fallback for custom drivers
  - Improves maintainability and clarity - no more guessing which drivers need special handling

### Benefits

- **Consistency**: All providers follow the same explicit configuration pattern
- **OCP Compliance**: No code changes needed when adding new providers
- **Clarity**: Explicit `driver_class` makes driver resolution clear and self-documenting
- **Extensibility**: New providers can be added via config only

### Migration guide (v1.x -> v2.0.0)

1. If you call `$driver->cancelSubscription()` / `enableSubscription()` directly (not via
   the `Subscription` fluent API): wrap your arguments in a `SubscriptionActionDTO`:
   ```diff
   - $driver->cancelSubscription($code, $token);
   + $driver->cancelSubscription(new SubscriptionActionDTO($code, ['token' => $token]));
   ```
2. If you have a custom driver implementing `SupportsSubscriptionsInterface`: update
   `cancelSubscription()`/`enableSubscription()` to the new signature (the upgrade guide has a worked example).
3. If you call `SubscriptionValidator::validateCancellation()` directly: drop the
   `$token` argument.
4. Run the new migration: `php artisan migrate` (adds `webhook_events`).
5. Remove any `PAYMENTS_TESTING_MODE` / `testing_mode` config entry - it's inert and will
   be silently ignored either way.
6. If you monitor PayPal webhook response codes for signature-rejection: that signal has
   moved to the `payments` log channel - the HTTP response is now always
   `202` regardless of signature validity.

Everything else in this release is additive or internal and requires no changes.

---
## [1.8.0] - 2025-12-27

### Added

- **Subscription Transaction Logging**
  - Automatic logging of all subscription operations to `subscription_transactions` table
  - `SubscriptionTransaction` model with query scopes (active, cancelled, forCustomer, forPlan)
  - Full audit trail of subscription lifecycle events
  - Configurable table name and enable/disable logging
  - Automatic logging on create, update, cancel, and status changes

- **Idempotency Support for Subscriptions**
  - Automatic UUID generation for idempotency keys
  - Custom idempotency key support
  - Prevents duplicate subscriptions from network retries
  - Idempotency header support in subscription creation
  - Key format validation and best practices

- **Subscription Lifecycle Events**
  - `SubscriptionCreated` event - Fired when subscription is created
  - `SubscriptionRenewed` event - Fired when subscription renews successfully
  - `SubscriptionCancelled` event - Fired when subscription is cancelled
  - `SubscriptionPaymentFailed` event - Fired when subscription payment fails
  - Complete webhook integration for all events
  - Event listener examples and documentation

- **Business Logic Validation**
  - `SubscriptionValidator` service for comprehensive validation
  - Plan existence and active status validation
  - Duplicate subscription prevention (configurable)
  - Authorization code format validation
  - Cancellation eligibility validation
  - Validation occurs before API calls to prevent errors

- **Subscription State Management**
  - `SubscriptionStatus` enum with state machine logic
  - States: ACTIVE, NON_RENEWING, CANCELLED, COMPLETED, ATTENTION, EXPIRED
  - State transition validation (`canTransitionTo()`)
  - Helper methods: `canBeCancelled()`, `canBeResumed()`, `isBilling()`
  - Provider status normalization (`fromString()`, `tryFromString()`)
  - State transition diagram and documentation

- **Subscription Query Builder**
  - `SubscriptionQuery` class with fluent interface
  - Filter methods: `forCustomer()`, `forPlan()`, `whereStatus()`, `active()`, `cancelled()`
  - Date filtering: `createdAfter()`, `createdBefore()`
  - Pagination: `take()`, `page()`
  - Provider filtering: `from()`
  - Execution methods: `get()`, `first()`, `count()`, `exists()`
  - Comprehensive query examples and use cases

- **PlanResponseDTO**
  - Type-safe plan response data transfer object
  - Implements `JsonSerializable` for Laravel responses
  - Consistent plan data structure across providers
  - Amount conversion (minor to major units)
  - `toArray()` method for array conversion

- **PlanResource**
  - Laravel resource for plan JSON responses
  - Consistent API response format
  - Matches pattern of `ChargeResource` and `VerificationResource`
  - Comprehensive test coverage

- **Subscription Configuration**
  - `prevent_duplicates` - Prevent duplicate active subscriptions
  - `validation.enabled` - Enable/disable business validation
  - `logging.enabled` - Enable/disable transaction logging
  - `logging.table` - Custom table name for logging
  - `webhook_events` - Configure which webhook events to handle
  - `retry.*` - Automatic retry configuration for failed payments
  - `grace_period` - Grace period for failed payments
  - `notifications.*` - Email notification configuration

- **Lifecycle Hooks Interface**
  - `SubscriptionLifecycleHooks` interface for custom drivers
  - Hook into subscription lifecycle events
  - Custom driver integration examples

### Changed

- **Paystack Plan Creation**
  - Removed unsupported `metadata` parameter from plan creation
  - Only sends parameters supported by Paystack API (name, interval, amount, currency, description, invoice_limit, send_invoices, send_sms)
  - Fixed array filter to preserve boolean `false` values for `send_invoices` and `send_sms`

- **Documentation**
  - Complete overhaul of `SUBSCRIPTIONS.md` with comprehensive sections
  - Added Transaction Logging, Idempotency, Lifecycle Events, Validation, Subscription States, and Querying sections
  - Updated all code examples to reflect current best practices
  - Added configuration documentation
  - Enhanced developer guide with new features

- **Return Types**
  - Updated documentation to reflect `PlanResponseDTO` return types (was incorrectly showing `array`)
  - All subscription methods now consistently return DTOs

### Fixed

- **Paystack API Compliance**
  - Fixed "Unknown/Unexpected parameter: metadata" error in plan creation
  - Plan creation now only sends supported parameters per Paystack documentation

- **Array Filter Consistency**
  - Fixed array filter to only filter `null` values (not empty strings)
  - Preserves boolean `false` values for `send_invoices` and `send_sms` options

- **Documentation Accuracy**
  - Fixed return type documentation inconsistencies
  - Updated method signatures to match actual implementation
  - Corrected endpoint paths in examples

### Upgrade Notes

**This is a MINOR version release (1.8.0) - fully backward compatible with v1.7.0**

**No Breaking Changes:**
- All existing subscription code continues to work without modification
- All new features are opt-in or enabled by default with sensible defaults
- No API changes to existing methods

**Optional Setup Steps:**

1. **Run Migrations** (if using subscription transaction logging)
   ```bash
   php artisan migrate
   ```
   This creates the `subscription_transactions` table if it doesn't exist.

2. **Update Configuration** (optional - defaults work out of the box)
   Add subscription configuration to `config/payments.php` if you want to customize:
   ```php
   'subscriptions' => [
       'prevent_duplicates' => env('PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES', false),
       'logging' => [
           'enabled' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED', true),
           'table' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE', 'subscription_transactions'),
       ],
       // ... other settings
   ],
   ```

3. **Update Event Listeners** (optional - only if using lifecycle events)
   If you want to use the new lifecycle events:
   ```php
   // app/Providers/EventServiceProvider.php
   protected $listen = [
       \KenDeNigerian\PayZephyr\Events\SubscriptionCreated::class => [
           \App\Listeners\HandleSubscriptionCreated::class,
       ],
       // ... other events
   ];
   ```

**New Features (All Opt-in):**
- Transaction logging (enabled by default, can be disabled via config)
- Idempotency (optional, use `->idempotency()` method)
- Query builder (new `Payment::subscriptions()` method)
- Lifecycle events (optional, register listeners if needed)

**Upgrade Time:**
- **0 minutes** - Works immediately with existing code
- **5-10 minutes** - If you want to configure new features

---
## [1.7.0] - 2025-12-18

### Added

- **Subscription Support for PaystackDriver**
  - Full subscription management API with fluent builder pattern
  - Create, update, get, and list subscription plans
  - Create, get, cancel, enable, and list subscriptions
  - Support for trial periods, custom start dates, and quantities
  - Authorization code support for immediate subscription activation
  - Comprehensive test coverage (100+ subscription tests)
  - Complete documentation with workflow examples

- **Subscription Data Transfer Objects**
  - `SubscriptionPlanDTO` - Type-safe plan creation
  - `SubscriptionRequestDTO` - Type-safe subscription requests
  - `SubscriptionResponseDTO` - Normalized subscription responses
  - Automatic validation and amount conversion

- **Subscription Exceptions**
  - `PlanException` - Plan-specific errors
  - `SubscriptionException` - Subscription-specific errors
  - Better error handling and debugging

- **Recommended Subscription Flow**
  - Redirect-to-payment flow for better user experience
  - Authorization code extraction from payment verification
  - Complete controller examples and documentation

### Changed

- **VerificationResponseDTO**: Added `authorizationCode` property
  - Enables subscription creation with saved payment methods
  - Extracted from Paystack transaction verification response
  - Supports recommended subscription flow pattern

- **PaystackDriver**: Enhanced verification to include authorization code
  - Extracts authorization code from transaction verification
  - Available in `VerificationResponseDTO` for subscription creation

### Documentation

- **New Subscription Guide** (`docs/SUBSCRIPTIONS.md`)
  - Complete subscription workflow documentation
  - Plan management examples
  - Subscription management examples
  - Recommended redirect-to-payment flow
  - Error handling patterns
  - Security considerations
  - Developer guide for adding subscription support to new drivers

- **Updated README**: Added subscription quick start example
- **Updated Documentation Index**: Added subscription guide reference
- **Updated Contributing Guide**: Added note about subscription support

### Technical Details

- **Architecture**: Subscription methods extracted to `PaystackSubscriptionMethods` trait
  - Follows Single Responsibility Principle (SRP)
  - Easy to extend for other providers
  - Maintains consistency with payment driver architecture

- **PHPStan Level 6**: All subscription code passes strict type checking
  - Explicit array type specifications
  - No ignored errors
  - Full type safety

### Tests

- **PaystackSubscriptionTest**: 9 comprehensive tests for subscription operations
- **SubscriptionCompleteTest**: 100+ tests for fluent API and edge cases
- **SubscriptionSecurityTest**: 30+ security-focused tests
- **SubscriptionEdgeCasesTest**: 25+ edge case tests
- All 1,165 tests passing (2,240 assertions)

### Developer Experience

- **Fluent API**: Consistent with payment API
  - `Payment::subscription()->customer()->plan()->subscribe()`
  - `payment()->subscription()` helper function support
  - Method chaining and builder pattern

- **Provider Support**: Currently only PaystackDriver supports subscriptions
  - Clear documentation about current limitations
  - Developer guide for adding support to other providers
  - Future support planned for other providers

---
## [1.4.1] - 2025-12-16

### Fixed

- **Race Condition Protection**: Fixed race condition vulnerability in transaction updates
  - Added database row locking (`lockForUpdate()`) with status re-check after lock acquisition
  - Prevents concurrent webhook/verification requests from causing duplicate processing
  - Applied to both `PaymentManager::updateTransactionFromVerification()` and `ProcessWebhook::updateTransactionFromWebhook()`
- **Inconsistent Logging**: Fixed logging inconsistency in `AbstractDriver`
  - Now uses consistent `Log::channel()` method instead of `logger()` helper
  - Respects configured log channel from config with proper fallback
- **Cache Key Generation**: Optimized cache context resolution in `PaymentManager`
  - Cache context is now cached per instance to avoid repeated function calls
  - Improves performance for applications making multiple cache operations
- **Idempotency Key Validation**: Added validation for custom idempotency keys
  - Validates format (alphanumeric, dashes, underscores only)
  - Validates length (max 255 characters)
  - Throws `InvalidArgumentException` for invalid keys

### Improved

- **Code Organization**: Extracted logging functionality to `LogsToPaymentChannel` trait
  - Eliminates code duplication across multiple classes
  - Used by: `PaymentManager`, `WebhookController`, `PaymentTransaction`, `ProcessWebhook`, `WebhookRequest`
- **ChannelMapper Refactoring**: Replaced magic method usage with PHP 8 `match` expressions
  - More type-safe and IDE-friendly
  - Better refactoring support
- **Error Context**: Enhanced error logging with comprehensive context
  - Includes error class, stack trace, request context, and provider configuration
  - Improves debugging and monitoring capabilities
- **Configuration**: Made webhook retry settings configurable
  - Added `PAYMENTS_WEBHOOK_MAX_RETRIES` (default: 3)
  - Added `PAYMENTS_WEBHOOK_RETRY_BACKOFF` (default: 60 seconds)
  - Added `PAYMENTS_CACHE_SESSION_TTL` (default: 3600 seconds)

### Documentation

- Updated documentation to reflect code review fixes
- Added idempotency key validation details
- Documented webhook retry configuration options
- Added race condition protection details to security guide
- Enhanced configuration examples with new environment variables

---
## [1.4.0] - 2025-12-15

### Security Enhancements

- **Metadata Sanitization**: Automatic XSS protection for metadata and customer data before storage
- **Health Endpoint Security**: IP whitelisting and token authentication for `/payments/health` endpoint
- **Webhook Payload Size Limits**: Configurable maximum payload size to prevent DoS attacks (default: 1MB)
- Enhanced input validation and sanitization throughout the package

### Code Quality

- **Final Classes**: Core classes marked as `final` for better encapsulation and performance
- **Readonly DTOs**: Data transfer objects use `readonly` properties for immutability
- **Consistent Logging**: Unified `log()` method across all classes (replaces direct `logger()` calls)
- **Configurable Log Channel**: Customize log channel via `PAYMENTS_LOG_CHANNEL` environment variable
- **Minimized Docblocks**: Streamlined documentation comments for better readability
- **Removed Deprecations**: Cleaned up non-code-breaking deprecations

### Added

- `MetadataSanitizer` service for automatic data sanitization
- `HealthEndpointMiddleware` for securing health check endpoint
- Configurable log channel in `config/payments.php`
- Environment variable `PAYMENTS_LOG_CHANNEL` for custom log channels

### 🔄 Changed

- All logging now uses consistent `log()` method instead of direct `logger()` calls
- Log channel is configurable (defaults to `'payments'`, falls back to default Laravel channel)
- Health endpoint now requires authentication/IP whitelisting in production (configurable)
- Webhook requests validate payload size before processing

### Testing

- Updated test suite to work with `final` classes (917 tests, 1,808 assertions)
- Added tests for metadata sanitization
- Enhanced security test coverage

### Documentation

- Updated logging documentation with configurable channel details
- Enhanced security guide with new features
- Streamlined contributing guidelines

---
## [1.3.0] - 2025-12-14

### Added

- **Mollie Payment Provider Support**
  - Full integration with Mollie payment gateway
  - Support for EUR, USD, GBP, and other Mollie-supported currencies
  - Redirect-based payment flow with hosted payment page
  - Webhook validation via signature (HMAC SHA-256) when webhook secret is configured, with API fallback
  - Automatic payment verification on webhook receipt
  - Comprehensive test coverage with 53 tests (96 assertions)
  - Edge case handling for network errors and missing data
  - Health check support with proper error handling
  - Idempotency key support for duplicate prevention
  - Status normalization for Mollie payment states
  - Customer data extraction from payment responses
  - Metadata support for custom payment information

### 📊 Test Coverage

- Added `MollieDriverTest.php` with 28 comprehensive tests
- Added `MollieDriverCoverageTest.php` with 11 coverage tests
- Added `MollieDriverEdgeCasesTest.php` with 14 edge case tests
- All tests passing with proper mocking and assertions

### Technical Details

- Mollie webhook validation fetches payment details from API instead of signature verification
- Proper error handling for 404, network timeouts, and API failures
- Timestamp validation to prevent replay attacks
- Currency formatting with proper decimal handling
- Channel mapping support for payment methods

### Documentation

- Added Mollie configuration guide
- Updated provider documentation
- Added usage examples
- Webhook setup instructions

---
## [1.2.1] - 2025-12-12

### Fixed

- Fixed PHPStan static analysis errors by improving code quality
  - Replaced nullsafe operators with direct property access where appropriate
  - Fixed variable scope issues in exception handling
  - Removed dead catch blocks
  - Fixed return type annotations in StatusNormalizer
  - Improved Eloquent model property access using `getAttribute()`
  - Added proper type hints for scope methods
  - Fixed ArrayObject import in PaymentTransaction model
- Improved type safety across the codebase
- Enhanced code quality and maintainability

### Added

- Comprehensive test coverage improvements
  - Added `SquareDriverEdgeCasesTest` for error handling scenarios
  - Added `HealthEndpointTest` for health check endpoint
  - Added `ChannelMapperSquareOpayTest` for Square and OPay channel mappings
  - Added `PaymentManagerEdgeCasesTest` for edge cases
  - Added `PaymentRateLimitingTest` for rate limiting scenarios
  - Added `ProcessWebhookJobErrorHandlingTest` for webhook error handling
- PHPStan configuration file (`phpstan.neon`) for static analysis
- Enhanced PHPDoc annotations for better IDE support and type safety

### 📊 Test Coverage

- **855 tests passing** with **1,707 assertions**
- Improved coverage for previously untested code paths
- All new test files passing successfully

### Developer Experience

- Added `composer analyse` command for PHPStan static analysis
- Improved code quality and type safety
- Better IDE support with enhanced PHPDoc annotations

---
## [1.2.0] - 2025-12-12

### Security

- **CRITICAL:** Added SQL injection prevention in table name validation
  - Table names are validated against strict regex pattern
  - Invalid table names automatically fall back to default
  - Warnings logged for invalid table name attempts
- **CRITICAL:** Implemented webhook replay attack prevention with timestamp validation
  - All drivers now validate webhook timestamps
  - Configurable tolerance window (default: 5 minutes)
  - Old webhooks outside tolerance are automatically rejected
  - Backward compatible: webhooks without timestamps still accepted (with warning)
- **CRITICAL:** Added multi-tenant cache isolation
  - Cache keys automatically include user context
  - Prevents cache poisoning in multi-tenant scenarios
  - Supports Laravel auth and session-based identification
- **HIGH:** Implemented automatic log sanitization for sensitive data
  - Automatic redaction of sensitive keys (password, secret, token, api_key, etc.)
  - Pattern-based detection of API keys and tokens
  - Recursive sanitization of nested arrays and objects
- **HIGH:** Added rate limiting for payment initialization
  - Prevents payment spam and DoS attacks
  - Per-user, per-email, or per-IP rate limiting
  - Configurable limits and decay windows
- Enhanced input validation (email, URL, reference format)
  - RFC 5322 compliant email validation
  - HTTPS enforcement for production callback URLs
  - Reference format validation prevents SQL injection

### Added

- Security configuration section in `config/payments.php`
  - `webhook_timestamp_tolerance` - Configurable webhook timestamp tolerance
  - `rate_limit` - Rate limiting configuration (enabled, max_attempts, decay_seconds)
  - `sanitize_logs` - Enable/disable log sanitization
  - `cache_isolation` - Enable/disable cache isolation
- `getCacheContext()` method in PaymentManager for multi-tenant isolation
- `validateWebhookTimestamp()` method in AbstractDriver for replay prevention
- `extractWebhookTimestamp()` method in AbstractDriver for timestamp extraction
- `sanitizeLogContext()` method in AbstractDriver for log safety
- `isSensitiveKey()` method in AbstractDriver for sensitive key detection
- Enhanced email validation methods in ChargeRequestDTO
  - `isValidEmail()` - RFC 5322 compliant validation
  - `isValidUrl()` - URL validation with HTTPS enforcement
  - `isValidReference()` - Reference format validation
- Comprehensive security test suite
  - SQL injection prevention tests
  - Webhook replay attack prevention tests
  - Cache isolation tests
  - Log sanitization tests
  - Rate limiting tests
  - Input validation tests

### Documentation

- Added comprehensive security guide (`docs/SECURITY.md`)
  - Security features overview
  - Best practices
  - Security monitoring
  - Incident response procedures
  - Security checklist
- Updated README.md with security considerations
  - Security features section
  - Production checklist
  - Multi-tenancy support documentation
  - Troubleshooting section
- Enhanced testing documentation
- Added webhook async processing warnings

### Tests

- Added 50+ new security tests
  - SQL injection prevention tests
  - Webhook timestamp validation tests
  - Cache isolation tests
  - Log sanitization tests
  - Rate limiting tests
  - Enhanced input validation tests
- Test coverage increased to 90%+
- All security tests passing

### Fixed

- Cache poisoning vulnerability in multi-tenant scenarios
- Missing validation in PaymentTransaction::getTable()
- Potential sensitive data exposure in logs
- Webhook replay attack vulnerability (all drivers)
- Missing timestamp validation in FlutterwaveDriver, MonnifyDriver, SquareDriver, OPayDriver
- Enhanced timestamp validation in StripeDriver and PayPalDriver

### 🔄 Changed

- All webhook validation methods now include timestamp validation
- Cache keys now include user context when available
- Log context is automatically sanitized before logging
- Rate limiting is automatically applied to payment initialization
- Enhanced email validation rejects malformed emails
- Production callback URLs must use HTTPS

---
## [1.1.12] - 2025-12-12

### Changed
- **SquareDriver HTTP Implementation**: Refactored SquareDriver to use direct HTTP requests instead of SDK
  - Removed dependency on Square PHP SDK
  - All API calls now use Guzzle HTTP client via `AbstractDriver::makeRequest()`
  - `charge()` method uses direct POST to `/v2/online-checkout/payment-links`
  - `verify()` methods use direct HTTP requests:
    - `verifyByPaymentId()` uses GET `/v2/payments/{id}`
    - `verifyByPaymentLinkId()` uses GET `/v2/online-checkout/payment-links/{id}`
    - `verifyByReferenceId()` uses POST `/v2/orders/search`
    - `getOrderById()` uses GET `/v2/orders/{id}`
    - `getPaymentDetails()` uses GET `/v2/payments/{id}`
  - `healthCheck()` uses GET `/v2/locations` for API connectivity testing
  - **Benefits**:
    - No external SDK dependency required
    - Consistent HTTP client usage across all drivers
    - Better control over request/response handling
    - Simplified error handling with standard HTTP exceptions
    - Reduced package size and dependencies

### Improved
- **SquareDriver Status Normalization**: Added Square-specific status mapping
  - `APPROVED` status now correctly maps to `success` (Square-specific behavior)
  - Overrides default normalization to handle Square's payment status semantics
- **SquareDriver Error Handling**: Enhanced exception handling for verification
  - Proper handling of `ChargeException` wrapping from `makeRequest()`
  - Preserves original error messages from Square API
  - Better exception chain traversal for health checks

### Fixed
- **SquareDriver Health Check**: Fixed exception handling for network errors
  - Now properly distinguishes between `ClientException` (API responding) and `ConnectException` (network error)
  - Returns `false` only for actual network connectivity issues
  - Returns `true` for API errors (indicates API is operational)

### Tests
- Updated SquareDriver tests to work with direct HTTP requests
  - All mocks updated to use `request()` instead of SDK methods
  - Test responses updated to match Square API format
  - All 41 Square driver tests passing (68 assertions)
  - All integration tests passing (14 tests)

---
## [1.1.11] - 2025-12-12

### Changed
- **SquareDriver SDK Integration**: Refactored SquareDriver to use the official Square PHP SDK
  - Replaced raw HTTP requests with Square SDK client (`Square\SquareClient`)
  - `charge()` method now uses `CreatePaymentLinkRequest` with SDK models (`Money`, `Order`, `OrderLineItem`, `CheckoutOptions`, `PrePopulatedData`)
  - `verify()` methods now use SDK APIs:
    - `verifyByPaymentId()` uses `$client->payments->get()`
    - `verifyByPaymentLinkId()` uses `$client->checkout->paymentLinks->get()`
    - `verifyByReferenceId()` uses `$client->orders->search()`
    - `getOrderById()` uses `$client->orders->get()`
    - `getPaymentDetails()` uses `$client->payments->get()`
  - `healthCheck()` now uses `$client->locations->list()` for API connectivity testing
  - SDK client initialization with environment detection (Sandbox/Production)
  - Support for injecting mocked HTTP client for testing compatibility
  - **Benefits**:
    - Type-safe SDK models and better IDE support
    - Official SDK support and updates
    - Improved error handling with SDK exceptions
    - Better maintainability and alignment with Square's best practices

### Improved
- **SquareDriver Code Quality**: Enhanced error handling and exception management
  - Proper handling of SDK exceptions (`SquareApiException`, `SquareException`)
  - Fallback HTTP-based verification for test compatibility
  - Improved exception chain traversal for health checks

### Tests
- Updated SquareDriver tests to work with SDK responses
  - Test responses updated to match SDK expectations (e.g., required `version` field in `PaymentLink`)
  - Error format updated to match SDK error structure
  - All 716 tests passing (1,447 assertions)

---
## [1.1.9] - 2025-12-11

### Fixed
- **PaystackDriver Health Check**: Fixed incorrect interpretation of 400 Bad Request responses
  - A 400 Bad Request from Paystack when checking `/transaction/verify/invalid_ref_test` now correctly indicates the API is working
  - The health check now properly traverses the exception chain to find `ClientException` with 400/404 status codes
  - Previously, the health check incorrectly returned `false` for expected 400 responses
  - **Impact**: Paystack health checks now correctly report API availability
- **SquareDriver Health Check**: Fixed incorrect interpretation of 404 Not Found responses
  - A 404 Not Found from Square when checking `/v2/payments/invalid_ref_test` now correctly indicates the API is working
  - The health check now properly traverses the exception chain to find `ClientException` with 400/404 status codes
  - Changed health check endpoint from `/v2/locations` to `/v2/payments/invalid_ref_test` for consistency
  - Previously, the health check incorrectly returned `false` for expected 404 responses
  - **Impact**: Square health checks now correctly report API availability
  - A 400 Bad Request from Paystack when checking `/transaction/verify/invalid_ref_test` now correctly indicates the API is working
  - The health check now properly traverses the exception chain to find `ClientException` with 400/404 status codes
  - Previously, the health check incorrectly returned `false` for expected 400 responses
  - **Impact**: Paystack health checks now correctly report API availability

### Improved
- **Exception Chain Traversal**: Improved exception handling in `PaystackDriver::healthCheck()` to properly traverse exception chains
  - More robust detection of `ClientException` within wrapped exceptions
  - Better logging with exception class information for debugging

### Tests
- Updated `PaystackDriverCoverageTest` to correctly expect `true` for 400 ClientException responses
- All 716 tests passing

---
## [1.1.8] - 2025-12-11

### Added
- **Application-Originating Payment Events**: New events for payment lifecycle hooks
  - `PaymentInitiated`: Dispatched after successful `charge()` operation
    - Provides clean hooks for business logic (e.g., sending email confirmations, updating inventory)
    - Event contains `ChargeRequestDTO`, `ChargeResponseDTO`, and provider name
  - `PaymentVerificationSuccess`: Dispatched after successful verification with success status
    - Triggered when payment verification results in a successful state
    - Event contains reference, `VerificationResponseDTO`, and provider name
  - `PaymentVerificationFailed`: Dispatched after successful verification with failed status
    - Triggered when payment verification results in a failed state
    - Event contains reference, `VerificationResponseDTO`, and provider name

### Changed
- **Centralized Idempotency Key Generation**: Idempotency keys are now automatically generated
  - `ChargeRequestDTO::fromArray()` now automatically generates a UUID v4 idempotency key if not provided
  - Ensures every payment request always has a unique idempotency key
  - Uses Laravel's `Str::uuid()` for consistent UUID v4 format
  - Removed manual idempotency key generation from `SquareDriver` (now handled centrally)
  - **Benefit**: Simplifies driver logic and ensures consistent key formatting across all providers

### Improved
- **PaymentManager Cache Cleanup**: Explicit cache deletion after successful verification
  - Cache entries are now explicitly deleted after successful verification instead of relying solely on expiration
  - Reduces unnecessary data accumulation in cache for already-verified payments
  - Improves cache efficiency and reduces memory usage

### Documentation
- Updated idempotency key documentation to reflect automatic generation
- Added documentation for new payment events
- Updated examples to show that idempotency keys are optional (auto-generated if not provided)

### Tests
- All 716 tests passing (1,447 assertions)
- Verified backward compatibility with existing idempotency key usage
- All events properly dispatched and testable

---
## [1.1.7] - 2025-12-11

### Changed
- **Convention over Configuration**: Refactored core services to eliminate hardcoded provider lists
  - **DriverFactory**: Now uses Convention over Configuration to automatically resolve driver classes
    - Converts provider name to `{Provider}Driver` class name (e.g., `'paystack'` → `PaystackDriver`)
    - Handles special cases (e.g., `'paypal'` → `PayPalDriver`)
    - No longer requires hardcoded provider-to-class mappings
    - Maintains backward compatibility with registered drivers and config `driver_class` settings
  - **ProviderDetector**: Dynamically builds prefix list from all providers in configuration
    - Automatically loads prefixes from `config('payments.providers')`
    - Uses `reference_prefix` from config if set, otherwise defaults to `UPPERCASE(provider_name)`
    - Loads all providers (not just enabled ones) for detection purposes
    - Supports custom prefixes via `reference_prefix` config option
  - **ChannelMapper**: Uses dynamic method checking instead of hardcoded provider list
    - Automatically calls `mapTo{Provider}()` methods based on provider name
    - No hardcoded provider checks required
    - Easier to extend with new provider mappings

### Improved
- **Maintainability**: Adding new providers no longer requires updating multiple hardcoded lists
- **Extensibility**: New providers automatically work if they follow naming conventions
- **Code Quality**: Reduced code duplication and improved adherence to DRY principles

### Configuration
- Added `reference_prefix` configuration option for providers that need custom prefixes:
  - Flutterwave: `'reference_prefix' => 'FLW'` (instead of default `'FLUTTERWAVE'`)
  - Monnify: `'reference_prefix' => 'MON'` (instead of default `'MONNIFY'`)

### Documentation
- Updated `docs/architecture.md` to reflect Convention over Configuration approach
- Documented dynamic prefix loading in ProviderDetector
- Documented Convention-based driver resolution in DriverFactory
- Documented dynamic method checking in ChannelMapper

### Tests
- All 716 tests passing
- Updated ProviderDetector tests to set up providers with correct `reference_prefix` values
- Verified backward compatibility with existing functionality

---
## [1.1.6] - 2025-12-11

### Added
- **Install Command**: New `payzephyr:install` artisan command for streamlined package setup
  - Automatically publishes configuration file
  - Publishes migration files
  - Optionally runs migrations with user confirmation
  - Displays setup instructions and example environment variables
  - Supports `--force` flag to overwrite existing files

### Changed
- **Documentation**: Updated installation instructions across all documentation files
  - README.md now uses `payzephyr:install` as the primary installation method
  - GETTING_STARTED.md updated with new install command workflow
  - DOCUMENTATION.md updated to reflect simplified installation process
  - Manual installation steps retained as alternative option for advanced users

### Improved
- **Developer Experience**: Simplified package installation from 3 manual steps to 1 command
  - Reduces setup time and potential for errors
  - Provides better onboarding experience for new users
  - Maintains backward compatibility with manual setup option

### Documentation
- Updated all installation guides to feature `payzephyr:install` command
- Added clear examples and expected output for install command
- Documented `--force` flag usage for overwriting existing files
- Maintained comprehensive documentation for manual setup alternative

---
## [1.1.5] - 2025-12-10

### Added
- **OPay Driver**: New payment driver with dual authentication support
  - Create Payment API: Bearer token authentication using Public Key
  - Status API: HMAC-SHA512 signature authentication using Private Key (Secret Key) and Merchant ID
  - Support for card payments, bank transfer, USSD, and mobile money
  - Comprehensive test coverage with integration and coverage tests

### Changed
- **OPay Driver**: Improved authentication implementation
  - Implemented HMAC-SHA512 signature generation for status API
  - Signature uses private key (secret_key) concatenated with merchant ID
  - Maintains backward compatibility for create payment API
  - Updated documentation to reflect dual authentication requirements

### Documentation
- Added comprehensive OPay driver documentation with authentication details
- Updated README and provider docs with new authentication requirements
- Clarified secret_key requirement for OPay status API

### Tests
- Added comprehensive test coverage for OPayDriver
- Fixed OPayDriverIntegrationTest to include secret_key in config
- All tests passing (700+ tests)

## [1.1.4] - 2025-12-09

### Fixed
- **Square Driver**: Fixed payment verification flow and improved code quality
  - Added missing `location_ids` parameter to order search API request (fixes "Must provide at least 1 location_id" error)
  - Fixed verification to handle `payment_link_id` (providerId) in addition to `reference_id`
  - Added payment link lookup as a verification strategy before order search fallback
  - Verification now supports three strategies: payment ID → payment link ID → reference ID order search

### Changed
- **Square Driver**: Refactored `verify()` method for better maintainability
  - Extracted verification logic into focused helper methods:
    - `verifyByPaymentId()` - handles direct payment ID lookup
    - `verifyByPaymentLinkId()` - handles payment link ID lookup
    - `verifyByReferenceId()` - handles reference ID order search
    - `searchOrders()` - encapsulates order search API call
    - `getOrderById()` - retrieves order by ID
    - `getPaymentFromOrder()` - extracts payment ID from order tenders
    - `getPaymentDetails()` - retrieves payment details by ID
  - Reduced main `verify()` method from ~135 lines to ~27 lines
  - Eliminated code duplication and improved testability
  - All 659 tests passing (1,336 assertions)

## [1.1.3] - 2025-12-09

### Changed
- **Core Classes**: Marked all core classes as `final` for better OCP compliance
  - All driver classes (PayPalDriver, StripeDriver, SquareDriver, PaystackDriver, FlutterwaveDriver, MonnifyDriver)
  - Core service classes (PaymentManager, DriverFactory, StatusNormalizer, ProviderDetector, ChannelMapper)
  - Controller and model classes (WebhookController, PaymentTransaction, Payment, PaymentServiceProvider)
  - All exception classes
  - This prevents inheritance and enforces composition, improving code maintainability

### Fixed
- **Square Driver**: Updated API version and cleaned up logging
  - Updated Square API version from `2024-01-18` to `2024-10-18`
  - Removed debug logging added for troubleshooting 401 authentication errors
  - Cleaned up unnecessary logs while maintaining essential operational logging
  - Updated SquareDriverCoverageTest to reflect new API version

- **Tests**: Refactored all test files to work with final classes
  - Replaced partial mocks of final driver classes with real instances and HTTP client mocking via `setClient()` method
  - Updated PaymentManager tests to use real instances with reflection-based driver injection into internal cache
  - Replaced DriverFactory mocks with direct driver injection into PaymentManager
  - Fixed status normalizer expectations in WebhookControllerCoverageTest to match actual driver behavior
  - Updated PayPalDriverWebhookTest to properly mock StreamInterface for HTTP response bodies
  - All 659 tests now pass successfully (1,336 assertions)

### Technical Details
- Tests now use composition (injecting mocks via public setters/reflection) instead of inheritance
- PaymentManager tests inject mock drivers directly into the internal `$drivers` cache using reflection
- Driver tests mock HTTP clients instead of extending final driver classes
- Maintains full test coverage while respecting final class constraints (OCP compliance)
- Improved test isolation by using real instances where possible

## [1.1.2] - 2025-12-09

### Feature

- Integrated Square driver providing:
- Comprehensive test coverage (41 tests, 68 assertions)
- Complete documentation updates across all docs
- Full integration with existing test suites
- Verification of all OCP methods (extractWebhookReference, extractWebhookStatus, extractWebhookChannel, resolveVerificationId)
- The Square driver is now fully tested, documented, and ready for production use.


## [1.0.9] - 2025-12-08

### Fixed

- **Stripe Webhook Validation**: Enhanced webhook signature validation with improved error messages and troubleshooting hints. Fixed validation failures by ensuring proper webhook secret configuration.
- **Flutterwave Webhook Validation**: Improved webhook validation with better error handling and logging. Added support for `FLUTTERWAVE_WEBHOOK_SECRET` configuration option.
- **SQLite Database Locks**: Increased webhook throttle limit from 60 to 120 requests per minute to reduce concurrent database lock issues when using SQLite cache driver. Added documentation note recommending `file` or `array` cache drivers for webhook routes.

### Improved

- **Webhook Error Messages**: Enhanced error messages for both Stripe and Flutterwave webhook validation failures with specific troubleshooting hints and configuration guidance.
- **Configuration**: Added `webhook_secret` option to Flutterwave configuration for dedicated webhook secret management (falls back to `secret_key` for backward compatibility).

### Changed

- **Webhook Throttling**: Increased throttle limit for webhook routes from 60 to 120 requests per minute to better handle concurrent webhook deliveries from payment providers.

---
## [1.0.8] - 2025-12-08

### Refactor

- **Moved provider-specific logic to drivers**: All webhook data extraction and verification ID resolution logic is now encapsulated in individual driver classes.
- **Eliminated hardcoded match statements**: `WebhookController` and `PaymentManager` no longer contain provider-specific `match ($provider)` statements.
- **New driver methods**: Added four new methods to `DriverInterface`:
  - `extractWebhookReference()` - Extract payment reference from webhook payload
  - `extractWebhookStatus()` - Extract payment status from webhook payload
  - `extractWebhookChannel()` - Extract payment channel from webhook payload
  - `resolveVerificationId()` - Resolve the ID needed for payment verification
- **Benefits**:
  - Adding new providers no longer requires modifying core classes
  - Each driver encapsulates its own data extraction logic
  - Follows SOLID principles (Open/Closed Principle)
  - Easier to test and maintain


## [1.0.7] - 2025-12-07

### Fixed

- Implement cache-first verification to support Unified API without DB logging
- PaymentManager: Now caches 'CustomRef ⇒ ProviderID' mapping for 1 hour during charge().
- PaymentManager: verify() uses Cache → DB → Prefix logic to find the correct Provider and ID.
- StripeDriver: Added support for verification via Checkout Session ID (cs_).
- MonnifyDriver: Fixed verification failure caused by query parameters in reference string.

## [1.0.6] - 2025-12-07

### Fixed

- StripeDriver charge() must use config callbackUrl as fallback to prevent empty success_url error when using →charge().

## [1.0.5] - 2025-12-07

### Fixed

- Implement cache-based provider resolution for verify()
- Ensures fast verification for custom references even if database logging is disabled.
- Resolution Priority: Explicit → Cache → Database → Prefix → Fallback Loop.

## [1.0.4] - 2025-12-07

### Fixed
- Standardize callback URL query parameters across all drivers
- AbstractDriver: Added appendQueryParam helper for safe URL construction.
- Drivers (Flutterwave, Monnify, PayPal, Stripe): Updated charge methods to explicitly append the 'reference' query parameter to the callback URL.
- This ensures a unified developer experience where Payment::verify(\$request→reference) works consistently for all providers.

## [1.0.3] - 2025-12-07

### Changed
- **PayPal:** Updated the default checkout flow to use `landing_page => GUEST_CHECKOUT`. This ensures users see the "Pay with Debit/Credit Card" option immediately instead of being forced to log in, significantly improving conversion rates.

## [1.0.2] - 2025-12-07

### Fixed
- **Flutterwave:** Fixed `404 Not Found` error caused by incorrect URL path resolution. Removed leading slashes in `FlutterwaveDriver` methods to ensure endpoints correctly append to the configured versioned base URL (`/v3/`).
- **PayPal:** Fixed `422 Unprocessable Entity` error by refactoring the payload to use the modern `experience_context` structure instead of the deprecated `application_context`.
- **PayPal:** Fixed "Cannot redirect to an empty URL" crash. The driver now correctly identifies the `payer-action` link type returned by the API v2, which replaced the previous `approve` link type.
- **Monnify:** Fixed a syntax error (missing comma) in the published `config/payments.php` file that caused application crashes during boot.

### Documentation
- **Monnify:** Added inline documentation in the configuration file to clarify the correct Base URLs for Sandbox (`https://sandbox.monnify.com`) vs. Live (`https://api.monnify.com`) environments.

## [1.0.1] - 2025-12-04

### Added
- **PaymentTransaction Model**: Full Eloquent model for transaction management
  - Mass assignment protection with explicit `$fillable` array
  - Convenient scopes: `successful()`, `failed()`, `pending()`
  - Helper methods: `isSuccessful()`, `isFailed()`, `isPending()`
  - Automatic JSON casting for metadata and customer fields
  - Configurable table name via config

- **Automatic Transaction Logging**:
  - All charges automatically logged to database on initialization
  - Webhook events automatically update transaction status
  - Verification events update transaction records
  - Graceful fallback if database logging fails

- **PayPal Zero-Decimal Currency Support**:
  - Intelligent currency precision detection
  - Supports 16 zero-decimal currencies (JPY, KRW, etc.)
  - Automatic formatting based on currency type

- **Enhanced Security Audit Documentation**:
  - Comprehensive security review document
  - Production deployment checklist
  - Incident response guidelines
  - GDPR and PCI-DSS compliance notes

- **Rounding Precision Handling**:
  - ChargeRequest now automatically rounds amounts to two decimal places
  - Prevents validation exceptions on high-precision inputs (e.g., 100.999)
  - Ensures consistent monetary formatting across all providers

- **Webhook Error Status Codes**:
  - WebhookController now returns HTTP 500 on internal errors
  - Previously returned HTTP 200 even on failures
  - Ensures payment providers trigger automatic retries
  - Improves webhook reliability and event processing

### Security
- **CRITICAL: Webhook Signature Validation Fix**
  - Fixed webhook signature bypass vulnerability
  - Now uses raw request body for signature verification
  - Prevents forged webhook attacks
  - **Impact**: HIGH - All users should update immediately

- **Input Validation Enhancements**:
  - Added maximum amount validation (999,999,999.99)
  - Strict decimal precision validation (max 2 places)
  - Protected against floating-point overflow
  - Enhanced email validation

- **Mass Assignment Protection**:
  - PaymentTransaction model properly guarded
  - Only necessary fields are marked as fillable
  - Prevents unauthorized field modification

### Fixed
- **Floating-Point Precision Issues**:
  - Improved `getAmountInMinorUnits()` with proper rounding
  - Uses `PHP_ROUND_HALF_UP` for consistent banker's rounding
  - Added validation for unreasonable decimal precision
  - Documented monetary value handling best practices

- **Stripe Driver** (Already Correct):
  - Confirmed Checkout Sessions implementation
  - Proper URL generation for `redirect()` method
  - No changes needed - working as intended

- **Database Migration Usage**:
  - Migration is now actively used by transaction logging
  - Webhook controller updates records automatically
  - Verification updates records on success

### Removed
- **Unused Dependencies**:
  - Removed `moneyphp/money` from composer.json
  - Removed unused `CurrencyConverterInterface` contract
  - Cleaned up unused exception classes
  - Reduced package size and complexity

### Changed
- **WebhookController**:
  - Now uses raw request body for signature validation
  - Extracts reference intelligently per provider
  - Updates transaction status automatically
  - Normalizes status across all providers
  - Enhanced error logging with context

- **PaymentManager**:
  - Added `logTransaction()` method for database logging
  - Added `updateTransactionFromVerification()` method
  - Improved error handling with context
  - Better exception aggregation on failure

- **ChargeRequest**:
  - Enhanced validation with security in mind
  - Better error messages for invalid inputs
  - Documented floating-point handling
  - Added overflow protection

### Documentation
- **New README.md**:
  - Professional formatting with badges
  - Comprehensive usage examples
  - Webhook setup guide with code samples
  - Security best practices section
  - API reference
  - Contributing guidelines

- **New SECURITY_AUDIT.md**:
  - Complete security review findings
  - Production deployment checklist
  - Monitoring and logging recommendations
  - Compliance notes (PCI-DSS, GDPR)
  - Incident response procedures

### Breaking Changes
None - This release is fully backward compatible.

### Migration Guide
No migration needed. Simply update via composer:

```bash
composer update kendenigerian/payzephyr
php artisan migrate  # Run new migration if not already run
```

---

## [1.0.0] - 2025-12-04

### 🎉 Initial Release

#### Added
- **Multi-Provider Support**:
  - Paystack integration
  - Flutterwave integration
  - Monnify integration
  - Stripe integration
  - PayPal integration

- **Core Features**:
  - Fluent payment API with chainable methods
  - Automatic provider fallback
  - Health check system with caching
  - Webhook signature verification
  - Currency support validation
  - Transaction reference generation

- **Developer Experience**:
  - Facade support (`Payment::charge()`)
  - Helper function (`payment()->charge()`)
  - Clean exception hierarchy
  - Comprehensive test suite (Pest PHP)
  - PSR-4 autoloading
  - Laravel auto-discovery

- **Configuration**:
  - Environment-based configuration
  - Per-provider settings
  - Webhook path customization
  - Health check configuration
  - Logging options

- **Data Transfer Objects**:
  - `ChargeRequest` - Standardized payment request
  - `ChargeResponse` - Standardized charge response
  - `VerificationResponse` - Standardized verification

- **Driver Architecture**:
  - `AbstractDriver` base class
  - `DriverInterface` contract
  - Individual driver implementations
  - HTTP client abstraction
  - Automatic header management

- **Testing**:
  - 70+ comprehensive tests
  - Unit tests for all drivers
  - Integration tests for workflows
  - Feature tests for facades
  - Mock support for external APIs

- **Documentation**:
  - Installation guide
  - Configuration examples
  - Usage documentation
  - Provider-specific guides
  - Webhook setup instructions

#### Provider-Specific Features

**Paystack**:
- Support for NGN, GHS, ZAR, USD
- Bank transfer support
- USSD payment support
- Custom channels selection
- Split payment configuration

**Flutterwave**:
- Support for 7+ currencies
- Mobile money integration
- Card payment support
- Customizable payment page

**Monnify**:
- Nigerian Naira (NGN) support
- Dynamic account generation
- Bank transfer support
- OAuth2 authentication

**Stripe**:
- Support for 135+ currencies
- Checkout Sessions
- Payment Intents API
- Apple Pay / Google Pay ready
- SCA compliance

**PayPal**:
- Support for major currencies
- PayPal balance payments
- Credit card via PayPal
- Sandbox mode support

---

## Release Schedule

- **Major versions** (x.0.0): Breaking changes, new architecture
- **Minor versions** (1.x.0): New features, backward compatible
- **Patch versions** (1.0.x): Bug fixes, security patches

---

## Upgrade Guide

### From 1.0.x to 1.0.9

**No breaking changes** - Simply update:

```bash
composer update kendenigerian/payzephyr
```

**New features available**:
1. Transaction logging - run migration:
   ```bash
   php artisan migrate
   ```

2. Query transactions:
   ```php
   use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
   
   $transactions = PaymentTransaction::successful()->get();
   ```

3. Enhanced security: ensure webhook verification is enabled:
   ```env
   PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
   ```

---

## Support

- 📧 Email: ken.de.nigerian@payzephyr.dev
- 💬 Discussions: [GitHub Discussions](https://github.com/ken-de-nigerian/payzephyr/discussions)

---

## Links

- [Documentation](https://github.com/ken-de-nigerian/payzephyr/wiki)
- [Contributing Guide](contributing.md)
- [License](/LICENSE)
