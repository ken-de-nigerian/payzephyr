# ADR-0002: Remove insecure defaults (TLS bypass, unbounded log recursion)

- **Status**: Accepted
- **Date**: 2026-07-31

## Problem

Two defaults in the codebase are unsafe the moment they're misconfigured, and a third
(health-check auth) looked like a similar case but turned out not to be safely fixable
as a default flip; see the "considered and rejected" note below.

1. `AbstractDriver::initializeClient()` sets Guzzle's `verify` option from a single config
   boolean: `'verify' => ! ($this->config['testing_mode'] ?? false)`
   ([AbstractDriver.php:93](../../../src/Drivers/AbstractDriver.php)). If `testing_mode`
   is ever `true` outside a local sandbox (a copy-pasted `.env`, a staging config that
   leaks into production), every outbound call to every payment gateway (charges,
   verification, secrets in transit) loses TLS certificate verification. For a payments
   library this is the single highest-blast-radius line in the codebase.
2. `HasLogSanitization::sanitizeLogContext()` recurses over arbitrary log context with no
   depth limit ([HasLogSanitization.php:40](../../../src/Traits/HasLogSanitization.php)).
   `MetadataSanitizer::sanitize()` already solved this exact problem for data persisted to
   the database (`METADATA_MAX_DEPTH`,
   [MetadataSanitizer.php:13](../../../src/Services/MetadataSanitizer.php)) but the fix was
   never applied to the sibling sanitizer used by every `log()` call, which routinely
   receives attacker-influenced webhook/error context.

## Options Considered (TLS bypass)

1. **Keep the flag, document it louder.** Rejected: a documentation comment does not stop
   a copy-pasted `.env` value. "Would Taylor Otwell merge this?" No; Laravel itself does
   not offer a global "disable cert verification" app config for exactly this reason.
2. **Remove the flag; use `AbstractDriver::setClient()` (already public, already used by
   the test suite) to inject a mock Guzzle handler in tests.** Chosen: the seam already
   existed, so this required no new test infrastructure.

## Options Considered (log recursion)

1. **Cap depth in `HasLogSanitization`, mirroring `MetadataSanitizer`.** Chosen: same
   proven approach, reused rather than reinvented (`PaymentConstants` gains
   `LOG_SANITIZATION_MAX_DEPTH`, distinct from `METADATA_MAX_DEPTH` since log context and
   persisted metadata have different legitimate shapes).

## Decision (health-check auth: considered, not applied here)

We evaluated defaulting `health_check.require_auth` to `true` in this same pass. Rejected
for Tier 1: `HealthEndpointMiddleware` requires auth by checking the token against
`allowed_tokens`, which also defaults to an **empty array**
([HealthEndpointMiddleware.php:39-46](../../../src/Http/Middleware/HealthEndpointMiddleware.php)).
Flipping `require_auth` to `true` without also requiring `allowed_tokens` to be set would
not "secure" the endpoint; it would permanently 401 it for everyone, silently, on
`composer update`, with seven existing passing tests
(`tests/Unit/HealthEndpointTest.php`) as direct evidence of the assumed-open contract today.
That's a breaking behavioral change wearing a patch-release costume. Deferred to a future
major-version ADR with a proper migration note; documented in `docs/SECURITY.md` instead
for now as an operator responsibility.

## Why

Verified by reading the actual middleware logic and the existing test suite's assumptions,
not inferred from the config file alone: the naive fix here would have introduced the
exact kind of silent breaking change ADR-driven review exists to catch.

## Trade-offs

- TLS: none accepted; there was no legitimate use of `verify: false` that `setClient()`
  mocking doesn't already cover.
- Log depth cap: extremely deep (attacker-supplied) nested log context is now truncated to
  `[MAX_DEPTH_EXCEEDED]` rather than logged in full. Accepted: the alternative is a
  memory-exhaustion DoS vector.

## Backward Compatibility

- `testing_mode` config key is **removed**. Anyone setting it in `.env` or `config/payments.php`
  will see it silently ignored (Laravel config extra keys are inert) rather than erroring:
  acceptable since its only effect (disabling TLS verification) should never have been
  relied upon outside tests, and tests are migrated to `setClient()` in this same change.
- No public interface signatures change.
