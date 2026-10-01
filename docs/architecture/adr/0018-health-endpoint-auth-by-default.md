# ADR-0018: Require health-endpoint authentication outside local development

- **Status**: Accepted
- **Date**: 2026-09-28

## Problem

`/payments/health` reports which providers an application uses and whether each is reachable.
ADR-0002 considered defaulting `health_check.require_auth` to `true` and deferred it to a major
version, because the middleware then demanded a token and `allowed_tokens` defaults to empty:
flipping the default would have made the endpoint answer 401 to everyone, silently, on
`composer update`.

Re-reading the middleware for this ADR found the same trap already live for anyone who followed
the documentation. The production checklist said to set `REQUIRE_AUTH=true` with *either* tokens
*or* an IP allowlist. With an allowlist alone, the middleware checked the IP and then demanded a
token nobody could have configured, and refused every request.

## Options Considered

1. **Default `require_auth` to `true` everywhere.** Rejected: a fresh install on a developer's
   machine would 401 its own health endpoint before any configuration.
2. **Default to `true` except in `local` and `testing`, and make a misconfiguration loud.**
   Chosen.
3. **Keep `false` and rely on the warning.** Rejected: the warning is one line in a log that a
   new install is least likely to be reading.

## Decision

`require_auth` defaults to on unless `APP_ENV` is `local` or `testing`; an IP allowlist alone
satisfies it, both are enforced when both are set, and with neither set every request is refused
and the reason is logged once an hour.

## Why

- ADR-0002's objection was the silent lockout. A published `config/payments.php` carries its own
  explicit `false`, so an existing install that published its config is untouched by the new
  default. One that did not, and runs in production with nothing configured, is now refused -
  failing closed, which is the point - and an error in the payments log names the fix.
- The allowlist fix makes the documented configuration work as documented. It is not a
  loosening: the allowlist is still enforced whenever it is set, and a token still is whenever
  tokens are set.
- Each case is pinned in `HealthEndpointMiddlewareAuthTest`, including the default read from the
  config file under `production`, `staging`, `local` and `testing`.

## Trade-offs

- The default depends on `APP_ENV`. An application that runs production with `APP_ENV=local` -
  itself a misconfiguration Laravel warns about - keeps an open endpoint.
- Uptime monitors that cannot send a header need an IP allowlist, or `?token=` in the URL.

## Backward Compatibility

- **Breaking** for installs that do not publish `config/payments.php` and run outside
  local/testing with no tokens or IPs configured: the health endpoint now answers 401. Migration:
  set `PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS` or `PAYMENTS_HEALTH_CHECK_ALLOWED_IPS`, or set
  `PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=false` to keep it open. Shipped in 5.0.0.
- Installs with a published config are unaffected by the default. Those that had set
  `REQUIRE_AUTH=true` with only an allowlist go from refused to working.
- Closes the deferral recorded in ADR-0002.
