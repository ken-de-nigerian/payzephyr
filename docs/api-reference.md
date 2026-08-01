# API Reference

This is the reference for PayZephyr's public API: every class and method you're expected to call directly from your own application code. It's organized by class, and assumes you've read the relevant chapter for context (linked from each section); this page documents *what each method does*, not *why the feature works the way it does*.

## `Payment` (facade: `KenDeNigerian\PayZephyr\Facades\Payment`)

See [Your First Payment](first-payment.md) and [Understanding Payment Flow](payment-flow.md) for context.

### Building a charge (fluent, chainable)

| Method | Purpose |
|---|---|
| `amount(float $amount)` | Amount in your currency's major unit (e.g. `15.00` for fifteen dollars, not cents) |
| `currency(string $currency)` | 3-letter ISO code, e.g. `'USD'`. Defaults to `payments.currency.default` if omitted |
| `email(string $email)` | Customer's email (required by every provider) |
| `reference(string $reference)` | Your own reference string. Auto-generated if omitted |
| `callback(string $url)` | Where the customer is redirected after paying; see [Your First Payment](first-payment.md#step-3-handling-the-return-trip) |
| `metadata(array $metadata)` | Arbitrary data attached to this charge, echoed back on verification |
| `idempotency(string $key)` | Prevents duplicate charges on retry; see [Advanced Usage](advanced-usage.md#idempotency-preventing-accidental-double-charges) |
| `description(string $description)` | Shown on the provider's payment page and the customer's statement |
| `customer(array $customer)` | Additional customer details some providers accept (name, phone) |
| `channels(array $channels)` | Restrict to specific payment channels (`['card', 'bank_transfer']`), where the provider supports it |
| `with(string\|array $providers)` / `using(...)` | Which provider(s) to use (identical methods, two names). A list enables [automatic fallback](providers.md#automatic-fallback) |

### Executing (terminal, call last)

**`charge(): ChargeResponseDTO`**
Creates the charge and returns the response directly, without redirecting: use this for an API-only integration (a mobile app backend, for instance) rather than a browser-based checkout.
*Throws:* `ChargeException`.

**`redirect(): RedirectResponse`**
Creates the charge and returns a Laravel redirect response sending the customer to the provider's hosted payment page. What you'll use in almost every controller.
*Throws:* `ChargeException`.

### Other `Payment` methods

**`verify(string $reference, ?string $provider = null): VerificationResponseDTO`**
Confirms a payment's actual status directly with the provider. See [Payment Verification](verification.md) for full detail; this is the method that entire chapter is about.
*Throws:* `VerificationException`.
```php
$verification = Payment::verify($reference);
if ($verification->isSuccessful()) { /* ... */ }
```

**`subscription(?string $code = null): Subscription`**
Starts a subscription fluent builder; see the `Subscription` class below. Pass a subscription code if you're about to act on an *existing* subscription (cancel, fetch); omit it when creating a new one.

**`subscriptions(): SubscriptionQuery`**
Starts a query builder for finding existing subscriptions; see `SubscriptionQuery` below.

## `Subscription` (returned by `Payment::subscription()`)

See [Subscriptions](subscriptions.md) for full context and examples.

### Building

| Method | Purpose |
|---|---|
| `customer(string $customer)` | Customer email for a new subscription |
| `plan(string $plan)` | Plan code to subscribe to |
| `quantity(int $quantity)` | Multi-seat subscriptions, where supported |
| `startDate(string $startDate)` | Delay the first billing cycle |
| `trialDays(int $days)` | Trial period before the first charge |
| `authorization(string $authorization)` | A saved payment method token (required by Stripe, Square, and Flutterwave); see [Subscriptions](subscriptions.md#the-building-blocks) |
| `callbackUrl(string $callbackUrl)` | Required for PayPal's subscription-approval redirect |
| `metadata(array $metadata)` | Arbitrary data attached to the subscription (sanitized before storage; see [Security](security.md#metadata-sanitization)) |
| `idempotency(?string $key = null)` | Auto-generates a UUID if no key given |
| `code(string $subscriptionCode)` | The subscription to act on, for cancel/fetch/enable operations |
| `token(string $token)` | Paystack's email confirmation token, required for its cancel/enable |
| `option(string $key, mixed $value)` | Provider-specific options, e.g. `option('at_period_end', true)` for Stripe |
| `with(string\|array $providers)` / `using(...)` | Which provider to use |
| `planData(SubscriptionPlanDTO $plan)` | The plan definition, for `createPlan()` |
| `planUpdates(array $updates)` | Fields to change, for `updatePlan()` |
| `perPage(int $perPage)` / `page(int $page)` | Pagination for `list()` |

### Executing (terminal)

**`create(): SubscriptionResponseDTO`** / **`subscribe(): SubscriptionResponseDTO`**: identical, create a new subscription. *Throws:* `SubscriptionException`.

**`fetch(): SubscriptionResponseDTO`**: retrieve an existing subscription by code. *Throws:* `SubscriptionException`.

**`cancel(?string $token = null): SubscriptionResponseDTO`**: cancel/disable/pause, depending on provider (see the [per-provider table](subscriptions.md#cancelling-and-re-enabling)). *Throws:* `SubscriptionException`.

**`enable(?string $token = null): SubscriptionResponseDTO`**: re-activate. Not possible on every provider; see [Subscriptions](subscriptions.md#cancelling-and-re-enabling). *Throws:* `SubscriptionException`.

**`list(?string $customer = null): array`**: list subscriptions, optionally filtered by customer. *Throws:* `SubscriptionException`.

**`createPlan(): PlanResponseDTO`**, **`updatePlan(): PlanResponseDTO`**, **`fetchPlan(): PlanResponseDTO`**, **`listPlans(): array`**: plan management. *Throws:* `PlanException`.

## `SubscriptionQuery` (returned by `Payment::subscriptions()`)

See [Subscriptions](subscriptions.md#finding-subscriptions).

| Method | Purpose |
|---|---|
| `forCustomer(string $customer)` | Filter by customer email |
| `forPlan(string $planCode)` | Filter by plan |
| `whereStatus(string $status)` | Filter by exact status string |
| `active()` / `cancelled()` | Shortcut status filters |
| `createdAfter(string $date)` / `createdBefore(string $date)` | Date range filters |
| `take(int $perPage)` / `page(int $page)` | Pagination |
| `from(string $provider)` | Scope to one provider |

Terminal: **`get(): array`** (of `SubscriptionResponseDTO`), **`first(): ?SubscriptionResponseDTO`**, **`count(): int`**, **`exists(): bool`**.

## Data objects

These are the typed objects PayZephyr's methods return: **always objects, accessed with `->property`, never arrays.**

**`ChargeResponseDTO`** (from `charge()`)
`reference`, `authorizationUrl`, `accessCode`, `status`, `metadata`, `provider`; helpers `isSuccessful()`, `isPending()`.

**`VerificationResponseDTO`** (from `verify()`); see [Payment Verification](verification.md#what-you-get-back) for the full field list and usage guidance.

**`SubscriptionResponseDTO`** (from subscription operations); see [Subscriptions](subscriptions.md#what-you-get-back).

**`PlanResponseDTO`** (from plan operations)
`planCode`, `name`, `amount`, `interval`, `currency`, `description`, `invoiceLimit`, `metadata`, `provider`.

**`SubscriptionPlanDTO`** (input to `planData()`)
```php
new SubscriptionPlanDTO(
    name: 'Pro Monthly',
    amount: 20.00,
    interval: 'monthly', // daily | weekly | monthly | annually
    currency: 'USD',
    description: null,
    invoiceLimit: null,
    sendInvoices: true,
    sendSms: true,
    metadata: [],
);
```

## Exceptions

Full explanation and handling patterns in [Error Handling](error-handling.md). Every exception below extends `KenDeNigerian\PayZephyr\Exceptions\PaymentException`.

| Exception | Thrown by |
|---|---|
| `ChargeException` | `charge()`, `redirect()` |
| `VerificationException` | `verify()` |
| `SubscriptionException` | Subscription create/cancel/enable/fetch/list |
| `PlanException` | Plan create/update/fetch/list |
| `InvalidConfigurationException` | Any operation on a misconfigured provider |
| `DriverNotFoundException` | Referencing an unknown or disabled provider |

## Events

Full catalog and examples in [Events](events.md). All under `KenDeNigerian\PayZephyr\Events`: `PaymentInitiated`, `PaymentVerificationSuccess`, `PaymentVerificationFailed`, `WebhookReceived`, `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCancelled`, `SubscriptionPaymentFailed`.

## Models

**`KenDeNigerian\PayZephyr\Models\PaymentTransaction`**: ordinary Eloquent model over the `payment_transactions` table. Scopes: `successful()`, `failed()`.

**`KenDeNigerian\PayZephyr\Models\SubscriptionTransaction`**: over `subscription_transactions`. Scopes: `active()`, `cancelled()`, `forCustomer()`, `forPlan()`.

See [Advanced Usage](advanced-usage.md#reading-transaction-history-directly).

## Next steps

- [Architecture](architecture.md): how these pieces fit together internally
- Any of the feature chapters linked throughout this page for the *why* behind each method
