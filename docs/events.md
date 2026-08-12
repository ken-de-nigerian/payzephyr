# Events

PayZephyr dispatches ordinary Laravel events at specific points in a payment's or subscription's lifecycle, so your app can react (send a confirmation email, update a dashboard, notify a Slack channel) without you having to remember to call that code manually every place a payment might happen.

If you haven't used Laravel's event system before: an event is just a plain PHP object carrying some data, and a listener is a class with a `handle()` method that receives it. You register which listeners run for which events in `app/Providers/EventServiceProvider.php`. The [Laravel documentation on events](https://laravel.com/docs/events) covers the mechanism itself in depth; this chapter focuses on what PayZephyr specifically dispatches and when.

## The full catalog

| Event | Fired when | Namespace |
|---|---|---|
| `PaymentInitiated` | A charge was successfully started (`Payment::charge()` or `->redirect()` succeeded) | `KenDeNigerian\PayZephyr\Events` |
| `PaymentVerificationSuccess` | `Payment::verify()` found the payment succeeded | `KenDeNigerian\PayZephyr\Events` |
| `PaymentVerificationFailed` | `Payment::verify()` found the payment did *not* succeed | `KenDeNigerian\PayZephyr\Events` |
| `WebhookReceived` | Any webhook was received, verified, and processed | `KenDeNigerian\PayZephyr\Events` |
| `SubscriptionCreated` | A subscription-created webhook was processed | `KenDeNigerian\PayZephyr\Events` |
| `SubscriptionRenewed` | A subscription-renewal webhook was processed | `KenDeNigerian\PayZephyr\Events` |
| `SubscriptionCancelled` | A subscription-cancelled webhook was processed | `KenDeNigerian\PayZephyr\Events` |
| `SubscriptionPaymentFailed` | A subscription renewal payment failed | `KenDeNigerian\PayZephyr\Events` |
| `RefundCreated` | A refund webhook was processed and the refund is still pending/processing | `KenDeNigerian\PayZephyr\Events` |
| `RefundCompleted` | A refund webhook confirmed the refund completed | `KenDeNigerian\PayZephyr\Events` |
| `RefundFailed` | A refund webhook reported the refund failed | `KenDeNigerian\PayZephyr\Events` |

## Payment events

**`PaymentInitiated`** fires the moment a charge is successfully created, before the customer has actually paid anything, so this is "a payment attempt started," not "a payment succeeded." Useful for logging or analytics on checkout starts.

```php
public readonly ChargeRequestDTO $request;
public readonly ChargeResponseDTO $response;
public readonly string $provider;
```

**`PaymentVerificationSuccess`** and **`PaymentVerificationFailed`** fire from `Payment::verify()` itself, reflecting what it found:

```php
public readonly string $reference;
public readonly VerificationResponseDTO $verification;
public readonly string $provider;
```

```php
// app/Listeners/LogPaymentOutcome.php

namespace App\Listeners;

use KenDeNigerian\PayZephyr\Events\PaymentVerificationSuccess;

class LogPaymentOutcome
{
    public function handle(PaymentVerificationSuccess $event): void
    {
        Log::info('Payment confirmed', [
            'reference' => $event->reference,
            'amount' => $event->verification->amount,
            'provider' => $event->provider,
        ]);
    }
}
```

**A useful thing to notice:** if your callback route already calls `Payment::verify()` (as it should; see [Payment Verification](verification.md)), that call *also* fires `PaymentVerificationSuccess`/`Failed` on its own. You don't need to manually dispatch anything to get these: they come for free from calling `verify()` normally.

## `WebhookReceived`

Covered in depth in [Webhooks](webhooks.md); this is the one event to listen for if you want to react to *any* webhook PayZephyr processes, regardless of provider or event type:

```php
public readonly string $provider;    // e.g. "paystack"
public readonly array $payload;      // the provider's raw webhook body
public readonly ?string $reference;  // extracted payment reference, if any
```

## Subscription events

These four fire from inside PayZephyr's webhook processing specifically: a subscription's state changes on the provider's side (a renewal happens on a schedule, for instance, with no request from your app at all), and these events are how your app finds out. See [Subscriptions](subscriptions.md) for the full lifecycle these fit into.

**`SubscriptionCreated`**

```php
public readonly string $subscriptionCode;
public readonly string $provider;
public readonly array $data;  // raw event data from the provider
```

**`SubscriptionRenewed`** fires on every successful recurring charge, not just the first one:

```php
public readonly string $subscriptionCode;
public readonly string $provider;
public readonly string $invoiceReference;
public readonly array $data;
```

**`SubscriptionCancelled`**

```php
public readonly string $subscriptionCode;
public readonly string $provider;
public readonly array $data;
```

**`SubscriptionPaymentFailed`** fires when a recurring charge attempt failed (an expired card, insufficient funds). This does *not* necessarily mean the subscription is cancelled: most providers retry a few times before giving up, depending on their own dunning configuration:

```php
public readonly string $subscriptionCode;
public readonly string $provider;
public readonly string $reason;
public readonly array $data;
```

A realistic example: extending access when a subscription renews, and flagging the account when a renewal payment fails:

```php
// app/Listeners/SyncSubscriptionStatus.php

namespace App\Listeners;

use App\Models\User;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;

class SyncSubscriptionStatus
{
    public function handle(SubscriptionRenewed|SubscriptionPaymentFailed $event): void
    {
        $user = User::where('subscription_code', $event->subscriptionCode)->first();

        if (! $user) {
            return;
        }

        if ($event instanceof SubscriptionRenewed) {
            $user->update(['subscription_active' => true, 'access_expires_at' => now()->addMonth()]);
        } else {
            $user->update(['subscription_active' => false]);
            // Consider notifying the user their payment failed, rather than
            // silently cutting off access.
        }
    }
}
```

Register both handled event types in `EventServiceProvider`:

```php
protected $listen = [
    \KenDeNigerian\PayZephyr\Events\SubscriptionRenewed::class => [
        \App\Listeners\SyncSubscriptionStatus::class,
    ],
    \KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed::class => [
        \App\Listeners\SyncSubscriptionStatus::class,
    ],
];
```

## Refund events

These three fire from inside PayZephyr's webhook processing, for the providers that confirm refunds asynchronously (see [Refunds](refunds.md#sync-vs-async-confirmation)) - a refund you issued a moment ago finally settles, and these events are how your app finds out without polling.

**`RefundCreated`** fires when a refund webhook arrives while the refund is still pending or processing (no completed/failed status yet):

```php
public readonly string $refundReference;
public readonly string $transactionReference;
public readonly string $provider;
public readonly array $data;  // raw event data from the provider
```

**`RefundCompleted`**

```php
public readonly string $refundReference;
public readonly string $transactionReference;
public readonly string $provider;
public readonly array $data;
```

**`RefundFailed`**

```php
public readonly string $refundReference;
public readonly string $transactionReference;
public readonly string $provider;
public readonly string $reason;
public readonly array $data;
```

A realistic example: crediting a customer's account balance once a refund is confirmed, rather than assuming `refund()`'s initial response was final:

```php
// app/Listeners/SyncRefundStatus.php

namespace App\Listeners;

use App\Models\Order;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundFailed;

class SyncRefundStatus
{
    public function handle(RefundCompleted|RefundFailed $event): void
    {
        $order = Order::where('transaction_reference', $event->transactionReference)->first();

        if (! $order) {
            return;
        }

        if ($event instanceof RefundCompleted) {
            $order->update(['refund_status' => 'completed']);
        } else {
            $order->update(['refund_status' => 'failed']);
            // Consider alerting an admin - a failed refund usually needs manual follow-up.
        }
    }
}
```

## A note on listener execution

By default, Laravel listeners run synchronously, in the same request (or job) that dispatched the event. Since `WebhookReceived` and the subscription events are already dispatched from inside a queued job (see [Queues](queues.md)), your listener code runs on the queue too, which is good, since it means a slow listener (sending an email, for instance) doesn't block webhook processing for other requests. If a listener needs to do something slow and you want extra isolation, you can still make the listener itself implement `ShouldQueue` for its own dedicated job; see the [Laravel queued listeners documentation](https://laravel.com/docs/events#queued-event-listeners).

## Next steps

- [Webhooks](webhooks.md): how these events get dispatched in the first place
- [Subscriptions](subscriptions.md): the full subscription lifecycle these events track
- [Refunds](refunds.md): the full refund lifecycle these events track
