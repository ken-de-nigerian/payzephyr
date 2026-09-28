# ADR-0016: Key webhook deduplication on the event, not the object it is about

- **Status**: Accepted
- **Date**: 2026-09-28

## Problem

ADR-0005 records every webhook delivery in `webhook_events` under a key and skips any later
delivery with the same key. It preferred "a native ID where one is known", falling back to a
hash of the body. For four providers the native id chosen was the id of the **object** the
event is about, not of the event:

| Provider | Key used | Distinct events that shared it |
| --- | --- | --- |
| Paystack | `data.id` | `subscription.create` / `subscription.disable`; `invoice.create` / `invoice.payment_failed` |
| Flutterwave | `data.id` | a subscription's activation and its cancellation |
| Monnify | `eventData.transactionReference` | a payment and each refund against it |
| OPay | `payload.transactionId` | every status change of one transaction |

The second event of each pair was recorded as a duplicate delivery and skipped without error:
a cancelled subscription stayed active, a completed refund stayed pending. `AbstractDriver`'s
default also accepted a top-level `payment_id`, the same mistake for any custom driver.

Mollie was worse. Its classic webhook body is `{"id": "tr_..."}` and nothing else, identical for
paid, refunded, charged back and expired. Keyed on the id, or on a body hash, every status change
after the first was dropped, and a listener following the documented pattern - re-verify on
`WebhookReceived` - never heard about a refund or a chargeback.

## Options Considered

1. **Compose the key from the event name and the object id** (`subscription.disable:4242`).
   Rejected: some events legitimately fire more than once for one object with the same name -
   Paystack's `invoice.update` as an invoice moves through states - and would still collide.
2. **Key providers without an event id on a hash of the body.** Chosen. A provider retry and an
   attacker's replay are both byte-identical to the original, so both still collide; two
   genuinely different events differ somewhere in their bodies, so they never do. This is the
   fallback ADR-0005 already specified for providers without an id.
3. **Mollie: fetch the payment in the job and key on its status.** Rejected: an extra API call
   per delivery to decide whether to process something that is safe to process anyway.
4. **Mollie: process every stateless delivery.** Chosen, via `SendsStatelessWebhooks`. The body
   carries no state, so the only thing a delivery - retry or replay included - can do is prompt
   another look at Mollie's current state.

## Decision

`extractWebhookEventId()` returns an event identifier or null, never an object identifier; with
null the delivery is keyed on its body hash, and a driver implementing `SendsStatelessWebhooks`
can declare a payload undeduplicable, which `ProcessWebhook` then processes every time.

## Why

- Stripe (`evt_`), PayPal (`WH-`), Square (`event_id`), Paddle (`event_id`) and Razorpay (event
  name plus the entity ids, ADR-0014) already keyed on the event and are unchanged.
- Paystack, Flutterwave, Monnify and OPay send no event id; their drivers now return null.
- `MollieDriver::isStatelessWebhook()` is true for a payload without a `type`. A typed event,
  such as `hook.ping`, carries its own `event_...` id and is deduplicated normally.
- A stateless delivery never claims a key, so a failed one leaves nothing to release.
- `WebhookEventIdempotencyTest` has one test per row of the table above, plus Mollie; each fails
  against the previous code. A byte-identical retry is still deduplicated.

## Trade-offs

- A provider that retries with a body that differs from the original - a regenerated field,
  a reordered key - would now be processed twice where it was deduplicated before. Transaction
  writes stay idempotent (`updateIfNotSuccessful()`, refund upserts), but `WebhookReceived`
  would fire twice. That is the recoverable direction to be wrong in; dropping a cancellation
  is not. Whether each provider's retries are byte-identical is on the sandbox verification
  list.
- A Mollie retry or replay reaches `WebhookReceived` listeners again. Documented; listeners are
  already told to act on re-verified state, not on the delivery.

## Backward Compatibility

- The four drivers' `extractWebhookEventId()` now return null. `AbstractDriver`'s default no
  longer reads `payment_id`. A custom driver relying on that gets body-hash keys instead.
- `webhook_events` rows written before upgrading are keyed the old way, so a provider retry of an
  event processed just before the upgrade will be processed once more. Nothing to migrate.
- `SendsStatelessWebhooks` is additive.
- Refines ADR-0005's "prefer a native ID" to "prefer a native **event** ID".
