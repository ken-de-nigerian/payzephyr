# FAQ

**Does PayZephyr support refunds?**

Not yet. There's no `refund()` method or refund tracking in the current version — this is a real gap, not an oversight we're hiding. If you need to issue a refund today, you'll need to call your provider's refund API directly using their own SDK, outside of PayZephyr, and record it in your own application. Refund support is a reasonable thing to want from a package like this, and it's on the radar for a future release.

**Can I use more than one provider at the same time?**

Yes — that's a core feature, not an edge case. See [Multiple Providers](providers.md). You can pick a provider per call, set a default, and configure automatic fallback if your primary provider is unreachable.

**Do I need a queue worker just to accept payments?**

For the one-time-charge flow alone (`->redirect()` and `->verify()`), no — those work synchronously without a queue. But **webhooks require one**, and webhooks are what make your payment records reliable rather than dependent on the customer's browser cooperating. See [Queues](queues.md) for the full explanation — in practice, treat a queue worker as required for any real deployment.

**Why does `verify()` sometimes return `pending` instead of success or failure?**

Some payment channels — bank transfers and USSD, especially — don't resolve instantly even when the customer did everything correctly; the provider is waiting on the actual transfer to clear. `pending` means "genuinely still in progress," not "something went wrong." See [Payment Verification](verification.md#why-status-is-a-normalized-string-not-each-providers-raw-value).

**Can I add a payment provider PayZephyr doesn't support?**

Yes — see [Custom Drivers](custom-drivers.md) for a full walkthrough of building your own driver.

**Does PayZephyr support Laravel Octane?**

There's nothing PayZephyr-specific that's known to conflict with Octane's persistent-application-state model, but this hasn't been extensively tested against it. If you hit an issue specifically under Octane (state leaking between requests, for instance), please [open an issue](https://github.com/ken-de-nigerian/payzephyr/issues) — that's genuinely useful information for improving support here, rather than something we can confidently promise works today.

**Why do I need to call `verify()` at all — can't I just trust the redirect?**

No — and this is important enough that it has [its own explanation](payment-flow.md#why-verify-instead-of-trusting-the-redirect). Short version: a redirect happening isn't proof of payment, and query parameters are trivially forgeable by anyone.

**What happens if a provider sends the same webhook twice?**

Nothing bad — this is normal provider behavior (a retry after a slow response, typically), and PayZephyr deduplicates automatically so your listener only runs once per actual event. See [Webhooks](webhooks.md#duplicate-deliveries).

**Which PHP and Laravel versions are supported?**

PHP 8.2+ and Laravel 12 or 13. Laravel 10 and 11 are no longer supported — both are past their upstream security-fix window. See [Installation](installation.md#before-you-start) and [Upgrade Guide](upgrade-guide.md).

**Is PayZephyr PCI compliant?**

Card data never touches your server or PayZephyr — every provider's own hosted checkout page (or tokenization flow, for saved cards) handles that part. You're still responsible for following each provider's own integration guidelines. See [Security](security.md#what-payzephyr-does-not-protect-you-from).

**How is this different from just using each provider's own SDK directly?**

You could absolutely do that. PayZephyr's value is specifically in *not* having to: one API instead of eight, automatic fallback if a provider is down, unified webhook handling, and consistent transaction logging — all without giving up access to provider-specific features when you need them (see [Advanced Usage](advanced-usage.md) and [Custom Drivers](custom-drivers.md)).

**Something's not working — where do I start?**

[Troubleshooting](troubleshooting.md) covers the most common issues with concrete fixes.
