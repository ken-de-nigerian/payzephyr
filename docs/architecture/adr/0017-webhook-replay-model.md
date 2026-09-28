# ADR-0017: One replay model - signed timestamps where they exist, deduplication everywhere, pruning only where it is safe

- **Status**: Accepted
- **Date**: 2026-09-28

## Problem

ADR-0001 made a five-minute replay window mandatory for every provider, measured against a
timestamp found in the payload. ADR-0005 added deduplication and left its table to grow without
bound. Taken together, and checked against what each provider actually sends, three things were
wrong.

1. **The window read timestamps that are not event times.** The shared field list tries
   `created_at` before `paid_at`. Paystack's `charge.success` carries both, and `created_at` is
   when the transaction was *initialised*, so a customer who took more than five minutes between
   opening checkout and paying had their `charge.success` rejected with a 403. A
   `subscription.disable` carries only the subscription's own `createdAt`, so every cancellation
   of a subscription older than five minutes was rejected. Flutterwave's `data.created_at` has the
   same meaning. Both were verified with signed payloads against the previous code.
2. **The window rejected the provider's own retries.** A retry repeats the original body, so its
   payload timestamp keeps getting older. Paystack retries for 72 hours; any outage on the
   merchant's side longer than five minutes turned every retry into a 403 and the event was lost.
   Stripe re-signs each attempt with a fresh `t=` and its SDK already enforces a window on that,
   so the additional check on `Event.created` did nothing but reject Stripe's retries.
3. **Nothing pruned `webhook_events`, and pruning naively would reopen replays.** For a provider
   without a trustworthy window, the stored key is the only thing that stops a replay; delete it
   and a years-old signed body is processed again (ADR-0014 noted this for Razorpay). ADR-0005
   also says its migration adds a `created_at` index to make pruning cheap. It does not.

## Options Considered

1. **Widen the one window to 72 hours.** Rejected: still reads the wrong field for Paystack and
   Flutterwave, so a subscription older than the window can never be cancelled by webhook; and
   it weakens Stripe and Paddle, whose signed per-delivery timestamps deserve a tight window.
2. **Separate what a timestamp means, and let deduplication carry the rest.** Chosen.
3. **Keep every key forever.** Rejected as the only behaviour: it is safe but leaves ADR-0005's
   growth problem unsolved for the providers where pruning is safe.

## Decision

A replay window is applied only to a timestamp the provider signs and that means what the
window assumes; every provider is deduplicated (ADR-0016); and `webhook_events` rows are pruned
only for providers whose window already rejects anything older than the retention period.

| Provider | Timestamp the window uses | Window | Replay horizon | Pruned by default |
| --- | --- | --- | --- | --- |
| Stripe | `t=` in `Stripe-Signature`, fresh per attempt | `security.webhook_timestamp_tolerance` (300 s) | 300 s | yes |
| Paddle | `ts=` in `Paddle-Signature`, fresh per attempt | same | 300 s | yes |
| PayPal | `create_time`, the event's creation | `webhook.events.replay_window` (72 h) | 72 h | yes |
| Square | envelope `created_at`, the event's creation | same | 72 h | yes |
| Paystack, Flutterwave | none - no field means "when this event happened" | none | unbounded | no |
| Monnify, OPay | none verified | none | unbounded | no |
| Razorpay, Mollie | none (ADR-0014, ADR-0015) | none | unbounded | no |

## Why

- A signed per-delivery timestamp is the textbook replay defence and never rejects a genuine
  retry, because each attempt is re-signed. Five minutes is right for it.
- An event-creation timestamp is signed but fixed across retries, so its window has to outlast the
  provider's retry schedule. 72 hours covers the published schedules of the providers in this
  group; PayPal's deliveries are acknowledged with 202 and never retried at all.
- Where no field means "when this happened", any window is a guess that rejects real events.
  Deduplication is exact instead: a replay is byte-identical to a delivery already recorded.
- `payzephyr:webhooks:prune` deletes a provider's rows only when the retention period is longer
  than that provider's horizon, and refuses a retention that is not. So every replay is either
  still recorded or already outside the window. Rows for unbounded providers are kept unless
  `--include-unbounded` is passed, which says in its output exactly what it gives up.
- Monnify and OPay are in the unbounded group because no field in their payloads is confirmed to
  be an event time. Sandbox verification may move them; until then, the safe side of being wrong
  is keeping rows, not rejecting payments.

## Trade-offs

- Rows for Paystack, Flutterwave, Monnify, OPay, Razorpay and Mollie accumulate. A row is a
  provider name, a key of up to 64 characters and two timestamps. `--include-unbounded` exists
  for operators who prefer the growth bound to the replay protection, and says so.
- Monnify and OPay lose a check they had. It was never shown to test an event time, and
  deduplication covers replays for as long as rows are kept.
- Two settings instead of one, because a signed delivery time and an event creation time are
  different things and need different windows.

## Backward Compatibility

- `security.webhook_timestamp_tolerance` now governs only signed delivery timestamps (Stripe,
  Paddle). PayPal and Square use `webhook.events.replay_window`.
- Deliveries that were wrongly rejected before are now accepted. Nothing that was accepted
  before is now rejected.
- A new core migration adds the `created_at` index to `webhook_events`; `php artisan
  payzephyr:install` publishes it.
- Supersedes ADR-0001's single mandatory window; completes ADR-0005's pruning follow-up.
