# ADR-0001: Fail-closed webhook replay window with provider-accurate timestamp extraction

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

`HasWebhookValidation::validateWebhookTimestamp()` is the only replay-window defense for
the six drivers that use plain HMAC signatures (Paystack, Flutterwave, Monnify, OPay,
NOWPayments, Mollie's signature path); a captured, validly-signed webhook body can be
replayed indefinitely unless this check rejects it.

The original implementation returned `true` (valid) whenever it could not find a timestamp
in the payload:

```php
if ($timestamp === null) {
    $this->log('warning', 'Webhook timestamp missing', [...]);
    return true;
}
```

That is a real bypass on its own. But fixing it by flipping `true` to `false` is not safe
in isolation, because `extractWebhookTimestamp()`'s field list
(`timestamp`, `created_at`, `createdAt`, `event_time`, `eventTime`, `time`) is checked only
at the **top level** of the decoded webhook body. We verified the actual payload shape each
provider sends (provider docs + our own test fixtures, 2026-07-31) and found the field
either doesn't exist at the top level, or doesn't exist under any of the checked names, for
5 of 9 providers:

| Provider | Real field | Location | Matches original list? |
|---|---|---|---|
| Paystack | `paid_at` / `created_at` | nested under `data` | No |
| Flutterwave | `created_at` | nested under `data` | No |
| Monnify | `paidOn` / `completedOn` / `createdOn` | nested under `eventData` | No |
| OPay | `timestamp` | nested under `payload` | No |
| PayPal | `create_time` | top level | No, field name not in list |
| Stripe | `created` | top level (but SDK enforces tolerance independently, see below) | No, field name not in list |
| Mollie (signature path) | none, body is always `{"id": "..."}` | n/a | Never present, by design |
| Square | `created_at` | top level | Yes |
| NOWPayments | `created_at` | top level | Yes |

Our own test suite (`tests/Unit/WebhookSignatureTest.php`) fabricates a top-level
`'timestamp' => time()` field that does not reflect what these providers actually send,
which is why the mismatch went undetected by CI.

Naively flipping the default to fail-closed, without fixing extraction, would have shipped
a "security patch" that breaks 100% of production webhook processing for Paystack
(the package's default provider), Flutterwave, Monnify, OPay, and PayPal.

## Options Considered

1. **Flip the default only.** Rejected: verified above to break 5 of 9 providers in
   production on upgrade.
2. **Recursive search for any matching key, at any depth.** Rejected: can false-positive
   on unrelated nested objects (e.g. a `metadata.created_at` a merchant put in their own
   payload), and hides *which* field is authoritative, making the check unauditable.
3. **Per-driver `extractWebhookTimestamp()` override, reusing the base field-matching
   logic against the correct nested object.** Chosen. `AbstractDriver`/`HasWebhookValidation`
   already documented this method as an override point; this uses that seam as designed.

## Decision

- `extractWebhookTimestamp()`'s fallback field list gains `created` (Stripe) and
  `create_time` (PayPal), both top-level, so no per-driver override needed for those two.
- `PaystackDriver` and `FlutterwaveDriver` override `extractWebhookTimestamp()` to check
  `$payload['data']` before falling back to the base implementation, via a new shared
  helper `HasWebhookValidation::extractWebhookTimestampFrom(array $payload, string $key)`.
- `MonnifyDriver` overrides to check `$payload['eventData']`; the base field list gains
  `paidOn`, `completedOn`, `createdOn` to match Monnify's actual key names.
- `OPayDriver` overrides to check `$payload['payload']`.
- `MollieDriver::validateWebhookSignature()` (the direct-HMAC path) no longer calls
  `validateWebhookTimestamp()` at all; Mollie's webhook body is *always* `{"id": "..."}"`
  by design (a ping telling you to re-fetch state), so a timestamp can never be present
  there, and replaying an old ping only causes a redundant re-fetch of Mollie's *current*
  true state via `validateWebhookViaAPI()`, not processing of stale data. The timestamp
  check remains on `validateWebhookViaAPI()`'s path, where the fetched Payment resource
  does carry `createdAt`.
- `validateWebhookTimestamp()` now returns `false` (rejects) when no timestamp is found,
  for every driver where a timestamp is expected to be extractable.
- Square and NOWPayments needed no change: their top-level field already matched.

## Why

Verified against provider documentation and our own driver signature-validation call
sites (`grep validateWebhookTimestamp src/Drivers`), not assumed. See the table above for
sources. Stripe's own SDK (`\Stripe\Webhook::constructEvent()`) already enforces a replay
tolerance from the `t=` component of the `Stripe-Signature` header before our code runs;
our app-level check is defense-in-depth there, not the primary control.

## Trade-offs

- Five drivers now carry a few lines of override instead of one shared implementation.
  Accepted: the alternative (silent, provider-specific bypass) is a worse hidden cost.
- Local/manual webhook testing with a hand-crafted curl payload lacking a timestamp field
  will now be rejected. This is intentional and matches `webhook.verify_signature`, the
  existing sanctioned escape hatch for local development; we did not add a second flag
  for this.

## Backward Compatibility

- Public API: unaffected; `extractWebhookTimestamp()` and `validateWebhookTimestamp()`
  are `protected`, not part of the public contract.
- Behavioral: a previously-accepted (but actually unverified) webhook lacking a
  recognizable timestamp will now be rejected with a 401-equivalent (`authorize()` returns
  `false`). This is the intended fix, not a regression: the check was a no-op before.
- No major version bump required; this ships as a patch release.
