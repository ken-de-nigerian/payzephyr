# Subscriptions Guide

Complete guide to using subscription functionality in PayZephyr. Subscriptions follow the same unified fluent builder pattern as payments for consistency.

PayZephyr provides enterprise-grade subscription management with automatic transaction logging, idempotency support, lifecycle events, business validation, and comprehensive state management. All subscription operations are automatically logged to the database, and you can query subscriptions using a powerful query builder interface.

## What's New in Subscription Management

The subscription system has been enhanced with several enterprise-grade features:

- **Transaction Logging** - All subscription operations (create, cancel, status changes) automatically logged to `subscription_transactions` table
- **Idempotency Support** - Prevent duplicate subscriptions with automatic UUID generation or custom keys
- **Lifecycle Events** - Comprehensive webhook events (SubscriptionCreated, SubscriptionRenewed, SubscriptionCancelled, SubscriptionPaymentFailed)
- **Query Builder** - Advanced subscription querying with fluent interface: `Payment::subscriptions()->forCustomer()->active()->get()`
- **State Machine** - Subscription status enum (ACTIVE, CANCELLED, etc.) with state transition validation and helper methods
- **Business Validation** - Built-in validation prevents duplicate subscriptions and validates plan eligibility before API calls

## Enhanced Subscription Features

The subscription system includes several enterprise-grade features:

- **Transaction Logging** - All subscriptions automatically logged to `subscription_transactions` table with full audit trail
- **Idempotency Support** - Prevent duplicate subscriptions with automatic UUID generation or custom keys
- **Lifecycle Events** - Comprehensive webhook events (SubscriptionCreated, SubscriptionRenewed, SubscriptionCancelled, SubscriptionPaymentFailed)
- **Query Builder** - Advanced subscription querying with fluent interface (`Payment::subscriptions()`)
- **State Machine** - Subscription status enum with state transition validation and helper methods
- **Business Validation** - Built-in validation prevents duplicate subscriptions and validates plan eligibility
- **Lifecycle Hooks** - Optional interface for custom drivers to hook into subscription lifecycle events

## ⚠️ Important: Current Provider Support

**Currently, only PaystackDriver supports subscriptions.** Support for other providers (Stripe, PayPal, etc.) will be added in future releases.

If you're a developer and want to add subscription support for a new driver, see the [Developer Guide](#developer-guide-adding-subscription-support-to-a-driver) section below.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Provider Support](#provider-support)
3. [Transaction Logging](#transaction-logging)
4. [Idempotency](#idempotency)
5. [Lifecycle Events](#lifecycle-events)
6. [Validation](#validation)
7. [Subscription States](#subscription-states)
8. [Querying Subscriptions](#querying-subscriptions)
9. [Configuration](#configuration)
10. [Creating Subscription Plans](#creating-subscription-plans)
11. [Creating Subscriptions](#creating-subscriptions)
12. [Managing Subscriptions](#managing-subscriptions)
13. [Plan Management](#plan-management)
14. [Complete Workflow Examples](#complete-workflow-examples)
15. [Error Handling](#error-handling)
16. [Best Practices](#best-practices)
17. [Security Considerations](#security-considerations)
18. [Developer Guide: Adding Subscription Support to a Driver](#developer-guide-adding-subscription-support-to-a-driver)

---

## Getting Started

Subscriptions are accessed through the `Payment` facade or the `payment()` helper function, following the same pattern as regular payments:

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
```

### Basic Pattern

**Using the Facade:**
```php
// The unified pattern - same as Payment!
Payment::subscription()
    ->customer('user@example.com')
    ->plan('PLN_abc123')
    ->with('paystack')  // Currently only PaystackDriver supports subscriptions
    ->subscribe();      // Final action method (create() is also available as an alias)
```

**Using the Helper Function:**
```php
// The payment() helper works exactly like the Payment facade
payment()->subscription()
    ->customer('user@example.com')
    ->plan('PLN_abc123')
    ->with('paystack')  // Currently only PaystackDriver supports subscriptions
    ->subscribe();      // Final action method (create() is also available as an alias)
```

Both approaches work identically - use whichever you prefer!

---

## Provider Support

### Current Status

| Provider        | Subscription Support | Status        |
|-----------------|----------------------|---------------|
| Paystack        | ✅ Full Support       | Available Now |
| Stripe          | ❌ Not Yet            | Coming Soon   |
| PayPal          | ❌ Not Yet            | Coming Soon   |
| Flutterwave     | ❌ Not Yet            | Coming Soon   |
| Monnify         | ❌ Not Yet            | Coming Soon   |
| Other Providers | ❌ Not Yet            | Coming Soon   |

### Why Only Paystack?

Subscription support requires provider-specific implementation. PaystackDriver was chosen as the first implementation because:

1. **Wide Adoption**: Paystack is widely used in the target markets
2. **Comprehensive API**: Paystack provides a complete subscription API
3. **Testing**: Extensive testing ensures reliability

### Future Support

We're actively working on adding subscription support for other providers. If you need subscription support for a specific provider, please:

1. Check our [GitHub Issues](https://github.com/ken-de-nigerian/payzephyr/issues) for planned support
2. Open a feature request if not already planned
3. Consider contributing (see [Developer Guide](#developer-guide-adding-subscription-support-to-a-driver))

---

## Transaction Logging

All subscription operations are automatically logged to the `subscription_transactions` table, providing a complete audit trail of subscription lifecycle events. This happens automatically - no additional code required.

### What Gets Logged

The following operations are automatically logged:

- **Subscription Creation** - When a subscription is created via `->subscribe()`
- **Subscription Updates** - When subscription status changes (via webhooks or API calls)
- **Cancellations** - When a subscription is cancelled
- **Status Changes** - Any status transitions (active → cancelled, etc.)

### Logged Data

Each subscription transaction record contains:

- `subscription_code` - Unique subscription identifier
- `provider` - Payment provider name (e.g., 'paystack')
- `status` - Current subscription status
- `plan_code` - Associated plan code
- `customer_email` - Customer email address
- `amount` - Subscription amount
- `currency` - Currency code
- `next_payment_date` - Next billing date
- `metadata` - Custom metadata (JSON)
- `created_at` / `updated_at` - Timestamps

### Querying Subscription Transactions

Use the `SubscriptionTransaction` model to query logged subscriptions:

```php
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

// Get subscription by code
$subscription = SubscriptionTransaction::where('subscription_code', 'SUB_xyz123')->first();

// Get all active subscriptions
$active = SubscriptionTransaction::active()->get();

// Get all cancelled subscriptions
$cancelled = SubscriptionTransaction::cancelled()->get();

// Get subscriptions for a specific customer
$customerSubs = SubscriptionTransaction::forCustomer('user@example.com')->get();

// Get subscriptions for a specific plan
$planSubs = SubscriptionTransaction::forPlan('PLN_abc123')->get();

// Complex queries
$recentActive = SubscriptionTransaction::active()
    ->where('created_at', '>=', now()->subDays(30))
    ->orderBy('created_at', 'desc')
    ->get();

// Count active subscriptions
$count = SubscriptionTransaction::active()->count();
```

### Configuration

Enable or disable subscription transaction logging in `config/payments.php`:

```php
'subscriptions' => [
    'logging' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED', true),
        'table' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE', 'subscription_transactions'),
    ],
],
```

**Environment Variables:**
- `PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED` - Enable/disable logging (default: `true`)
- `PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE` - Custom table name (default: `subscription_transactions`)

### Relationship with Payment Transactions

Subscription transactions are separate from payment transactions:

- **Payment Transactions** (`payment_transactions`) - Log one-time payments
- **Subscription Transactions** (`subscription_transactions`) - Log recurring subscription lifecycle

Both tables work together to provide complete financial tracking.

---

## Idempotency

Idempotency ensures that retrying a subscription operation doesn't create duplicates. This is critical for handling network failures, user double-clicks, and race conditions.

### What Idempotency Prevents

- **Duplicate Subscriptions** - Network retries won't create multiple subscriptions
- **Double Charges** - Page refreshes won't trigger duplicate subscription creation
- **Race Conditions** - Concurrent requests won't create duplicate subscriptions

### How It Works

PayZephyr supports idempotency through unique keys. When you provide an idempotency key, the provider (Paystack) ensures that the same key won't process the same operation twice within a time window.

### Using Idempotency

#### Automatic UUID Generation

The simplest approach - PayZephyr automatically generates a UUID:

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->idempotency()  // Auto-generates UUID
    ->with('paystack')
    ->subscribe();
```

#### Custom Idempotency Key

Provide your own unique key (must be unique per operation):

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->idempotency('user-123-plan-abc-' . time())  // Custom key
    ->with('paystack')
    ->subscribe();
```

#### Best Practices for Keys

- **Make keys unique** - Include user ID, plan code, and timestamp
- **Use consistent format** - `{user_id}-{plan_code}-{timestamp}` or similar
- **Store keys** - Save keys to database to track retries
- **Key lifetime** - Provider-specific (typically 24 hours for Paystack)

### Idempotent Retries

When retrying a failed operation, use the same idempotency key:

```php
$idempotencyKey = 'user-123-plan-abc-' . now()->timestamp;

try {
    $subscription = Payment::subscription()
        ->customer('customer@example.com')
        ->plan('PLN_abc123')
        ->idempotency($idempotencyKey)
        ->with('paystack')
        ->subscribe();
} catch (SubscriptionException $e) {
    // Network error - retry with same key
    sleep(2);
    $subscription = Payment::subscription()
        ->customer('customer@example.com')
        ->plan('PLN_abc123')
        ->idempotency($idempotencyKey)  // Same key!
        ->with('paystack')
        ->subscribe();
}
```

### When to Use Idempotency

**Always use idempotency for:**
- Subscription creation
- Critical operations that must not be duplicated
- Operations triggered by user actions (button clicks)

**Optional for:**
- Read-only operations (fetch, list)
- Operations that are naturally idempotent (cancellation with same token)

### Key Format Requirements

- **Minimum length**: 10 characters (Paystack requirement)
- **Maximum length**: 255 characters
- **Characters**: Alphanumeric, hyphens, underscores
- **Uniqueness**: Must be unique per operation type

---

## Lifecycle Events

PayZephyr dispatches Laravel events for all subscription lifecycle changes, allowing you to react to subscription events in your application.

### Available Events

#### SubscriptionCreated

Fired when a new subscription is successfully created.

```php
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;

Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event) {
    // $event->subscriptionCode - Subscription code
    // $event->provider - Provider name
    // $event->data - Full subscription data
    
    // Example: Send welcome email
    Mail::to($event->data['customer']['email'] ?? '')
        ->send(new SubscriptionWelcomeMail($event->subscriptionCode));
    
    // Example: Update user subscription status
    User::where('email', $event->data['customer']['email'] ?? '')
        ->update(['has_active_subscription' => true]);
});
```

#### SubscriptionRenewed

Fired when a subscription is successfully renewed (payment processed).

```php
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;

Event::listen(SubscriptionRenewed::class, function (SubscriptionRenewed $event) {
    // $event->subscriptionCode - Subscription code
    // $event->provider - Provider name
    // $event->invoiceReference - Invoice reference
    // $event->data - Full renewal data
    
    // Example: Extend user access
    $subscription = SubscriptionTransaction::where('subscription_code', $event->subscriptionCode)->first();
    if ($subscription) {
        User::where('email', $subscription->customer_email)
            ->update(['subscription_expires_at' => $subscription->next_payment_date]);
    }
    
    // Example: Send renewal confirmation
    Mail::to($subscription->customer_email ?? '')
        ->send(new SubscriptionRenewedMail($event->subscriptionCode));
});
```

#### SubscriptionCancelled

Fired when a subscription is cancelled.

```php
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;

Event::listen(SubscriptionCancelled::class, function (SubscriptionCancelled $event) {
    // $event->subscriptionCode - Subscription code
    // $event->provider - Provider name
    // $event->data - Full cancellation data
    
    // Example: Revoke user access
    $subscription = SubscriptionTransaction::where('subscription_code', $event->subscriptionCode)->first();
    if ($subscription) {
        User::where('email', $subscription->customer_email)
            ->update(['has_active_subscription' => false]);
    }
    
    // Example: Send cancellation confirmation
    Mail::to($subscription->customer_email ?? '')
        ->send(new SubscriptionCancelledMail($event->subscriptionCode));
});
```

#### SubscriptionPaymentFailed

Fired when a subscription payment fails.

```php
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;

Event::listen(SubscriptionPaymentFailed::class, function (SubscriptionPaymentFailed $event) {
    // $event->subscriptionCode - Subscription code
    // $event->provider - Provider name
    // $event->reason - Failure reason
    // $event->data - Full failure data
    
    // Example: Notify user of payment failure
    $subscription = SubscriptionTransaction::where('subscription_code', $event->subscriptionCode)->first();
    if ($subscription) {
        Mail::to($subscription->customer_email)
            ->send(new PaymentFailedMail($event->subscriptionCode, $event->reason));
    }
    
    // Example: Mark subscription for attention
    SubscriptionTransaction::where('subscription_code', $event->subscriptionCode)
        ->update(['status' => 'attention']);
});
```

### Registering Event Listeners

#### In EventServiceProvider

```php
// app/Providers/EventServiceProvider.php
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;

protected $listen = [
    SubscriptionCreated::class => [
        \App\Listeners\HandleSubscriptionCreated::class,
    ],
    SubscriptionRenewed::class => [
        \App\Listeners\HandleSubscriptionRenewed::class,
    ],
    SubscriptionCancelled::class => [
        \App\Listeners\HandleSubscriptionCancelled::class,
    ],
    SubscriptionPaymentFailed::class => [
        \App\Listeners\HandleSubscriptionPaymentFailed::class,
    ],
];
```

#### Using Event Facade

```php
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;

Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event) {
    // Handle event
});
```

### Webhook Integration

Events are automatically dispatched when webhooks are received. Configure webhook URLs in your provider dashboard:

- **Paystack**: `https://yourdomain.com/payments/webhook/paystack`

See [Webhooks Guide](webhooks.md) for complete webhook setup.

### Complete Webhook Handler Example

```php
// app/Http/Controllers/SubscriptionWebhookController.php
use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;

class SubscriptionWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        $event = $payload['event'] ?? null;
        
        switch ($event) {
            case 'subscription.create':
                SubscriptionCreated::dispatch(
                    $payload['data']['subscription_code'],
                    'paystack',
                    $payload['data']
                );
                break;
                
            case 'invoice.payment_failed':
                SubscriptionPaymentFailed::dispatch(
                    $payload['data']['subscription']['subscription_code'],
                    'paystack',
                    $payload['data']['gateway_response'] ?? 'Payment failed',
                    $payload['data']
                );
                break;
                
            // Handle other events...
        }
        
        return response()->json(['status' => 'success']);
    }
}
```

---

## Validation

PayZephyr includes built-in business logic validation to prevent common subscription errors before making API calls.

### What Gets Validated

#### Plan Validation

- **Plan Existence** - Plan code must exist in provider
- **Plan Active Status** - Plan must be active (not disabled)
- **Currency Compatibility** - Plan currency must match subscription currency

#### Subscription Validation

- **Customer Required** - Customer email must be provided
- **Plan Required** - Plan code must be provided
- **Duplicate Prevention** - If enabled, prevents duplicate active subscriptions
- **Authorization Code Format** - Authorization codes must be valid format (min 10 characters)

#### Cancellation Validation

- **Subscription Exists** - Subscription must exist
- **Not in Terminal State** - Cannot cancel already cancelled/completed/expired subscriptions
- **Token Format** - Email token must be valid format (min 10 characters)

### Configuration

Enable or disable validation in `config/payments.php`:

```php
'subscriptions' => [
    'prevent_duplicates' => env('PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES', false),
    'validation' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED', true),
    ],
],
```

**Environment Variables:**
- `PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES` - Prevent duplicate subscriptions (default: `false`)
- `PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED` - Enable/disable validation (default: `true`)

### Validation Errors

When validation fails, a `SubscriptionException` is thrown with a descriptive message:

```php
try {
    $subscription = Payment::subscription()
        ->customer('customer@example.com')
        ->plan('INVALID_PLAN')
        ->with('paystack')
        ->subscribe();
} catch (SubscriptionException $e) {
    // Handle validation error
    if (str_contains($e->getMessage(), 'not active')) {
        return response()->json(['error' => 'Selected plan is not available'], 400);
    }
    
    if (str_contains($e->getMessage(), 'already has an active subscription')) {
        return response()->json(['error' => 'You already have an active subscription'], 400);
    }
    
    // Other validation errors
    logger()->error('Subscription validation failed', [
        'error' => $e->getMessage(),
    ]);
    
    return response()->json(['error' => 'Subscription validation failed'], 400);
}
```

### Common Validation Errors

| Error Message                                                           | Cause                                           | Solution                                  |
|-------------------------------------------------------------------------|-------------------------------------------------|-------------------------------------------|
| `Plan PLN_xxx is not active`                                            | Plan is disabled or doesn't exist               | Verify plan code and status               |
| `Customer already has an active subscription`                           | Duplicate prevention enabled                    | Cancel existing subscription first        |
| `Invalid authorization code format`                                     | Authorization code too short                    | Use valid authorization code from payment |
| `Cannot cancel subscription: subscription is already in terminal state` | Trying to cancel already cancelled subscription | Check subscription status first           |
| `Invalid email token format`                                            | Email token too short or invalid                | Use token from subscription email         |

### Customizing Validation

Validation occurs in the `SubscriptionValidator` service. To customize:

1. Create a custom validator service
2. Extend `SubscriptionValidator`
3. Override validation methods
4. Register in service provider

---

## Subscription States

PayZephyr uses a comprehensive subscription status enum with state machine logic to manage subscription lifecycle.

### Available States

#### ACTIVE

Subscription is active and billing normally.

- **Meaning**: Customer has access, payments are processing
- **Entry**: Created with valid authorization, payment succeeded
- **Allowed Operations**: Cancel, set to non-renewing
- **Transitions To**: NON_RENEWING, CANCELLED, ATTENTION

```php
use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;

$status = SubscriptionStatus::ACTIVE;
$status->label(); // "Active"
$status->isBilling(); // true
$status->canBeCancelled(); // true
```

#### NON_RENEWING

Subscription is active but won't renew after current period.

- **Meaning**: Customer has access until period ends, then subscription ends
- **Entry**: Customer cancelled but period hasn't ended
- **Allowed Operations**: Resume (reactivate), cancel immediately
- **Transitions To**: ACTIVE, CANCELLED, COMPLETED

```php
$status = SubscriptionStatus::NON_RENEWING;
$status->isBilling(); // true
$status->canBeResumed(); // true
```

#### CANCELLED

Subscription has been cancelled.

- **Meaning**: No access, no future billing
- **Entry**: Customer cancelled, period ended, or payment failed
- **Allowed Operations**: Resume (reactivate)
- **Transitions To**: ACTIVE

```php
$status = SubscriptionStatus::CANCELLED;
$status->canBeResumed(); // true
$status->canBeCancelled(); // false
```

#### COMPLETED

Subscription has completed its full lifecycle.

- **Meaning**: All invoices paid, subscription naturally ended
- **Entry**: Invoice limit reached, subscription period completed
- **Allowed Operations**: None (terminal state)
- **Transitions To**: None

```php
$status = SubscriptionStatus::COMPLETED;
$status->allowedTransitions(); // []
```

#### ATTENTION

Subscription requires attention (payment failed, etc.).

- **Meaning**: Customer action required, access may be limited
- **Entry**: Payment failed, authorization expired
- **Allowed Operations**: Resolve issue, cancel
- **Transitions To**: ACTIVE, CANCELLED, EXPIRED

```php
$status = SubscriptionStatus::ATTENTION;
$status->canBeCancelled(); // true
```

#### EXPIRED

Subscription has expired due to payment failure.

- **Meaning**: No access, payment failed and grace period expired
- **Entry**: Payment failed multiple times, grace period ended
- **Allowed Operations**: None (terminal state)
- **Transitions To**: None

```php
$status = SubscriptionStatus::EXPIRED;
$status->allowedTransitions(); // []
```

### State Machine Methods

#### Check Capabilities

```php
use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;

$status = SubscriptionStatus::ACTIVE;

// Check if can be cancelled
$status->canBeCancelled(); // true

// Check if can be resumed
$status->canBeResumed(); // false

// Check if actively billing
$status->isBilling(); // true
```

#### Get Valid Transitions

```php
$status = SubscriptionStatus::ACTIVE;
$transitions = $status->allowedTransitions();
// Returns: [NON_RENEWING, CANCELLED, ATTENTION]

// Check if transition is allowed
$status->canTransitionTo(SubscriptionStatus::CANCELLED); // true
$status->canTransitionTo(SubscriptionStatus::COMPLETED); // false
```

#### Create from Provider Status

```php
// Normalize provider status to enum
$status = SubscriptionStatus::fromString('active'); // ACTIVE
$status = SubscriptionStatus::fromString('cancelled'); // CANCELLED
$status = SubscriptionStatus::fromString('non-renewing'); // NON_RENEWING

// Safe conversion (returns null if invalid)
$status = SubscriptionStatus::tryFromString('unknown'); // null
```

### State Transition Diagram

```
ACTIVE
  ├──> NON_RENEWING (customer cancels)
  ├──> CANCELLED (immediate cancellation)
  └──> ATTENTION (payment issue)

NON_RENEWING
  ├──> ACTIVE (resumed)
  ├──> CANCELLED (immediate cancellation)
  └──> COMPLETED (period ends)

ATTENTION
  ├──> ACTIVE (issue resolved)
  ├──> CANCELLED (customer cancels)
  └──> EXPIRED (grace period ends)

CANCELLED
  └──> ACTIVE (resumed)

COMPLETED (terminal)
EXPIRED (terminal)
```

### Using States in Your Code

```php
use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;

$subscription = Payment::subscription($subscriptionCode)
    ->with('paystack')
    ->get();

// Convert provider status to enum
$status = SubscriptionStatus::fromString($subscription->status);

// Check capabilities
if ($status->canBeCancelled()) {
    // Show cancel button
}

if ($status->isBilling()) {
    // Grant access
}

// Validate state transitions
if ($status->canTransitionTo(SubscriptionStatus::CANCELLED)) {
    // Allow cancellation
}
```

---

## Querying Subscriptions

PayZephyr provides a powerful query builder for advanced subscription filtering and retrieval, similar to Laravel's query builder.

### Basic Usage

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

// Get all subscriptions
$subscriptions = Payment::subscriptions()
    ->get();

// Get first subscription
$first = Payment::subscriptions()
    ->first();

// Count subscriptions
$count = Payment::subscriptions()
    ->count();
```

### Filtering Methods

#### Filter by Customer

```php
$subscriptions = Payment::subscriptions()
    ->forCustomer('user@example.com')
    ->get();
```

#### Filter by Plan

```php
$subscriptions = Payment::subscriptions()
    ->forPlan('PLN_abc123')
    ->get();
```

#### Filter by Status

```php
// Using whereStatus
$subscriptions = Payment::subscriptions()
    ->whereStatus('active')
    ->get();

// Using shorthand methods
$active = Payment::subscriptions()
    ->active()
    ->get();

$cancelled = Payment::subscriptions()
    ->cancelled()
    ->get();
```

#### Filter by Date Range

```php
$subscriptions = Payment::subscriptions()
    ->createdAfter('2024-01-01')
    ->createdBefore('2024-12-31')
    ->get();
```

#### Filter by Provider

```php
$subscriptions = Payment::subscriptions()
    ->from('paystack')
    ->get();
```

### Pagination

```php
// Set items per page
$subscriptions = Payment::subscriptions()
    ->take(20)
    ->page(1)
    ->get();

// Get page 2
$page2 = Payment::subscriptions()
    ->take(20)
    ->page(2)
    ->get();
```

### Complex Queries

```php
// Find all active subscriptions for a customer
$activeSubs = Payment::subscriptions()
    ->forCustomer('user@example.com')
    ->active()
    ->from('paystack')
    ->get();

// Find subscriptions created in last 30 days
$recent = Payment::subscriptions()
    ->createdAfter(now()->subDays(30)->toDateString())
    ->active()
    ->get();

// Find subscriptions for specific plan, paginated
$planSubs = Payment::subscriptions()
    ->forPlan('PLN_premium')
    ->take(50)
    ->page(1)
    ->get();
```

### Execution Methods

#### get()

Returns array of subscription data:

```php
$results = Payment::subscriptions()
    ->active()
    ->get();

// Structure: ['data' => [...], 'meta' => [...]]
$subscriptions = $results['data'] ?? $results;
```

#### first()

Returns first matching subscription as `SubscriptionResponseDTO` or `null`:

```php
$subscription = Payment::subscriptions()
    ->forCustomer('user@example.com')
    ->active()
    ->first();

if ($subscription) {
    echo $subscription->subscriptionCode;
    echo $subscription->status;
}
```

#### count()

Returns count of matching subscriptions:

```php
$count = Payment::subscriptions()
    ->active()
    ->count();
```

#### exists()

Checks if any subscriptions match the query:

```php
$hasActive = Payment::subscriptions()
    ->forCustomer('user@example.com')
    ->active()
    ->exists();
```

### Real-World Examples

#### Find All Active Subscriptions for Billing

```php
$activeSubscriptions = Payment::subscriptions()
    ->active()
    ->from('paystack')
    ->get();

foreach ($activeSubscriptions['data'] ?? [] as $sub) {
    // Process billing for each subscription
    processBilling($sub['subscription_code']);
}
```

#### Identify Subscriptions Needing Renewal

```php
$expiringSoon = Payment::subscriptions()
    ->active()
    ->createdBefore(now()->subMonths(1)->toDateString())
    ->get();

// Subscriptions created over a month ago that are still active
// May need renewal attention
```

#### Audit Subscription History

```php
$history = Payment::subscriptions()
    ->forCustomer('user@example.com')
    ->createdAfter('2024-01-01')
    ->get();

// Complete subscription history for customer
```

---

## Configuration

PayZephyr provides comprehensive configuration options for subscription management.

### Subscription Configuration

All subscription settings are in `config/payments.php` under the `subscriptions` key:

```php
'subscriptions' => [
    // Prevent duplicate subscriptions
    'prevent_duplicates' => env('PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES', false),
    
    // Validation settings
    'validation' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED', true),
    ],
    
    // Transaction logging
    'logging' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED', true),
        'table' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE', 'subscription_transactions'),
    ],
    
    // Webhook events to handle
    'webhook_events' => [
        'subscription.create',
        'subscription.disable',
        'subscription.enable',
        'subscription.not_renew',
        'invoice.payment_failed',
    ],
    
    // Retry configuration
    'retry' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_RETRY_ENABLED', false),
        'max_attempts' => env('PAYMENTS_SUBSCRIPTIONS_RETRY_MAX_ATTEMPTS', 3),
        'delay_hours' => env('PAYMENTS_SUBSCRIPTIONS_RETRY_DELAY_HOURS', 24),
    ],
    
    // Grace period for failed payments (days)
    'grace_period' => env('PAYMENTS_SUBSCRIPTIONS_GRACE_PERIOD', 7),
    
    // Notifications
    'notifications' => [
        'enabled' => env('PAYMENTS_SUBSCRIPTIONS_NOTIFICATIONS_ENABLED', false),
        'events' => [
            'created',
            'cancelled',
            'renewed',
            'payment_failed',
        ],
    ],
],
```

### Environment Variables

| Variable                                       | Default                     | Description                                |
|------------------------------------------------|-----------------------------|--------------------------------------------|
| `PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES`    | `false`                     | Prevent duplicate active subscriptions     |
| `PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED`    | `true`                      | Enable business logic validation           |
| `PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED`       | `true`                      | Enable transaction logging                 |
| `PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE`         | `subscription_transactions` | Custom table name for logging              |
| `PAYMENTS_SUBSCRIPTIONS_RETRY_ENABLED`         | `false`                     | Enable automatic retry for failed payments |
| `PAYMENTS_SUBSCRIPTIONS_RETRY_MAX_ATTEMPTS`    | `3`                         | Maximum retry attempts                     |
| `PAYMENTS_SUBSCRIPTIONS_RETRY_DELAY_HOURS`     | `24`                        | Hours between retry attempts               |
| `PAYMENTS_SUBSCRIPTIONS_GRACE_PERIOD`          | `7`                         | Grace period in days for failed payments   |
| `PAYMENTS_SUBSCRIPTIONS_NOTIFICATIONS_ENABLED` | `false`                     | Enable email notifications                 |

### Configuration Precedence

1. **Environment Variables** - Highest priority (`.env` file)
2. **Config File** - `config/payments.php`
3. **Defaults** - Package defaults

### Example Configuration

```env
# .env
PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES=true
PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED=true
PAYMENTS_SUBSCRIPTIONS_RETRY_ENABLED=true
PAYMENTS_SUBSCRIPTIONS_RETRY_MAX_ATTEMPTS=3
PAYMENTS_SUBSCRIPTIONS_GRACE_PERIOD=7
```

---

## Creating Subscription Plans

### Basic Plan Creation

```php
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

// Create a monthly subscription plan
$planDTO = new SubscriptionPlanDTO(
    name: 'Monthly Premium',
    amount: 5000.00,        // ₦5,000.00 (package handles conversion)
    interval: 'monthly',     // daily, weekly, monthly, annually
    currency: 'NGN',
    description: 'Monthly premium subscription with full access',
    sendInvoices: true,      // Send invoices to customers
    sendSms: true,           // Send SMS notifications
    metadata: [
        'plan_type' => 'premium',
        'features' => 'all',
    ]
);

// Using the facade
$plan = Payment::subscription()
    ->planData($planDTO)
    ->with('paystack')  // Required: Currently only PaystackDriver supports subscriptions
    ->createPlan();

// Or using the helper function
$plan = payment()->subscription()
    ->planData($planDTO)
    ->with('paystack')
    ->createPlan();

// Save the plan code to your database
$planCode = $plan->planCode; // e.g., 'PLN_abc123xyz'

// Return as JSON response in your controller
use KenDeNigerian\PayZephyr\Http\Resources\PlanResource;

return new PlanResource($plan);
```

### Plan with Invoice Limit

```php
$planDTO = new SubscriptionPlanDTO(
    name: 'Annual Plan',
    amount: 50000.00,        // ₦50,000.00
    interval: 'annually',
    currency: 'NGN',
    invoiceLimit: 12,       // Stop after 12 invoices
    description: 'Annual subscription plan'
);

$plan = Payment::subscription()
    ->planData($planDTO)
    ->with('paystack')
    ->createPlan();
```

### Plan with Different Intervals

```php
// Daily plan
$dailyPlan = new SubscriptionPlanDTO(
    name: 'Daily Access',
    amount: 100.00,
    interval: 'daily',
    currency: 'NGN'
);

// Weekly plan
$weeklyPlan = new SubscriptionPlanDTO(
    name: 'Weekly Access',
    amount: 500.00,
    interval: 'weekly',
    currency: 'NGN'
);

// Monthly plan
$monthlyPlan = new SubscriptionPlanDTO(
    name: 'Monthly Access',
    amount: 2000.00,
    interval: 'monthly',
    currency: 'NGN'
);

// Create all plans
$daily = Payment::subscription()->planData($dailyPlan)->with('paystack')->createPlan();
$weekly = Payment::subscription()->planData($weeklyPlan)->with('paystack')->createPlan();
$monthly = Payment::subscription()->planData($monthlyPlan)->with('paystack')->createPlan();
```

### Plan Validation

The `SubscriptionPlanDTO` automatically validates:
- **Name**: Must not be empty
- **Amount**: Must be greater than zero
- **Interval**: Must be one of: `daily`, `weekly`, `monthly`, `annually`
- **Currency**: Must be a valid 3-letter ISO code

Invalid plans will throw `InvalidArgumentException` before making any API calls.

---

## Creating Subscriptions

### Basic Subscription

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->with('paystack')
    ->subscribe();

// Access subscription details
$subscriptionCode = $subscription->subscriptionCode;
$status = $subscription->status;
$emailToken = $subscription->emailToken;  // CRITICAL: Save this!

// Save to database IMMEDIATELY
DB::table('subscriptions')->insert([
    'user_id' => auth()->id(),
    'subscription_code' => $subscription->subscriptionCode,
    'email_token' => $subscription->emailToken,  // Required for cancel/enable
    'plan_code' => 'PLN_abc123',
    'status' => $subscription->status,
    'amount' => $subscription->amount,
    'next_payment_date' => $subscription->nextPaymentDate,
    'created_at' => now(),
]);
```

**Note**: Both `Payment::subscription()` and `payment()->subscription()` work identically. Always save the `emailToken` immediately - it's required for cancel/enable operations.

### Subscription with Trial Period

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->trialDays(14)  // 14-day free trial
    ->with('paystack')
    ->subscribe();
```

### Subscription with Custom Start Date

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->startDate('2024-02-01')  // Start on specific date (Y-m-d format)
    ->with('paystack')
    ->subscribe();
```

### Subscription with Quantity

```php
// For multi-seat subscriptions
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->quantity(5)  // 5 seats/licenses
    ->with('paystack')
    ->subscribe();
```

### Subscription with Authorization Code

```php
// If customer already has a saved card authorization
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->authorization('AUTH_abc123')  // Saved authorization code
    ->with('paystack')
    ->subscribe();
```

### Subscription with Metadata

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->metadata([
        'user_id' => auth()->id(),
        'order_id' => 12345,
        'subscription_type' => 'premium',
        'referral_code' => 'REF123',
    ])
    ->with('paystack')
    ->subscribe();
```

### Complete Subscription Example

```php
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->quantity(1)
    ->trialDays(7)
    ->startDate('2024-02-01')
    ->metadata(['user_id' => auth()->id()])
    ->with('paystack')
    ->subscribe();
```

### Subscription Validation

The `SubscriptionRequestDTO` automatically validates:
- **Customer**: Must not be empty (email or customer code)
- **Plan**: Must not be empty (plan code)
- **Quantity**: Must be at least 1 (if provided)
- **Trial Days**: Cannot be negative (if provided)

Invalid subscriptions will throw `InvalidArgumentException` before making any API calls.

---

## Recommended Subscription Flow (Redirect to Payment)

### Step-by-Step Implementation

#### Step 1: User Selects Plan - Redirect to Payment

```php
// In your SubscriptionController
public function subscribe(Request $request)
{
    $planCode = $request->input('plan_code');
    $user = auth()->user();
    
    // Get plan details
    $plan = Payment::subscription()
        ->plan($planCode)
        ->with('paystack')
        ->getPlan();
    
    // Redirect user to payment page
    // This charges the first payment and gets authorization
    return Payment::amount($plan['amount'] / 100)  // Convert from kobo to major units
        ->currency($plan['currency'])
        ->email($user->email)
        ->callback(route('subscription.callback', [
            'plan_code' => $planCode,
            'user_id' => $user->id,
        ]))
        ->metadata([
            'plan_code' => $planCode,
            'user_id' => $user->id,
            'subscription_flow' => true,  // Flag for callback handler
        ])
        ->with('paystack')
        ->redirect();  // User redirected to Paystack payment page
}
```

#### Step 2: Payment Callback - Create Subscription

```php
// Handle payment callback
public function subscriptionCallback(Request $request)
{
    $reference = $request->input('reference');
    $planCode = $request->input('plan_code');
    $userId = $request->input('user_id');
    
    try {
        // Verify the payment was successful
        $verification = Payment::verify($reference, 'paystack');
        
        if (!$verification->isSuccessful()) {
            return redirect()->route('subscription.failed')
                ->with('error', 'Payment was not successful. Please try again.');
        }
        
        // Extract authorization code from verification response
        // This is now available in VerificationResponseDTO!
        $authorizationCode = $verification->authorizationCode;
        
        if (!$authorizationCode) {
            // Fallback: Create subscription without authorization
            // Paystack will send email to customer for authorization
            $subscription = Payment::subscription()
                ->customer($verification->customer['email'] ?? $user->email)
                ->plan($planCode)
                ->with('paystack')
                ->subscribe();
        } else {
            // Create subscription with authorization - immediate activation!
            $subscription = Payment::subscription()
                ->customer($verification->customer['email'] ?? $user->email)
                ->plan($planCode)
                ->authorization($authorizationCode)  // Use saved authorization
                ->with('paystack')
                ->subscribe();
        }
        
        // Save subscription to database
        DB::table('subscriptions')->insert([
            'user_id' => $userId,
            'subscription_code' => $subscription->subscriptionCode,
            'email_token' => $subscription->emailToken,  // CRITICAL: Save this!
            'plan_code' => $planCode,
            'status' => $subscription->status,
            'amount' => $subscription->amount,
            'authorization_code' => $authorizationCode,  // Save for future use
            'next_payment_date' => $subscription->nextPaymentDate,
            'created_at' => now(),
        ]);
        
        // Update user's subscription status
        User::where('id', $userId)->update([
            'subscription_status' => 'active',
            'subscription_code' => $subscription->subscriptionCode,
        ]);
        
        return redirect()->route('subscription.success')
            ->with('subscription', $subscription);
            
    } catch (\Exception $e) {
        logger()->error('Subscription creation failed', [
            'reference' => $reference,
            'plan_code' => $planCode,
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        return redirect()->route('subscription.failed')
            ->with('error', 'Failed to create subscription. Please contact support.');
    }
}
```

#### Step 3: Routes Setup

```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
    Route::get('/subscription/callback', [SubscriptionController::class, 'subscriptionCallback'])->name('subscription.callback');
    Route::get('/subscription/success', [SubscriptionController::class, 'success'])->name('subscription.success');
    Route::get('/subscription/failed', [SubscriptionController::class, 'failed'])->name('subscription.failed');
});
```

### Complete Example: Full Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Facades\Payment;

class SubscriptionController extends Controller
{
    /**
     * Show subscription plans page
     */
    public function plans()
    {
        $plans = [
            [
                'code' => 'PLN_basic',
                'name' => 'Basic Plan',
                'amount' => 5000.00,
                'interval' => 'monthly',
            ],
            [
                'code' => 'PLN_pro',
                'name' => 'Pro Plan',
                'amount' => 15000.00,
                'interval' => 'monthly',
            ],
        ];
        
        return view('subscriptions.plans', compact('plans'));
    }
    
    /**
     * User selects plan - redirect to payment
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_code' => 'required|string',
        ]);
        
        $planCode = $request->input('plan_code');
        $user = auth()->user();
        
        // Get plan details from Paystack
        $plan = Payment::subscription()
            ->plan($planCode)
            ->with('paystack')
            ->getPlan();
        
        // Redirect to payment page
        return Payment::amount($plan['amount'] / 100)
            ->currency($plan['currency'])
            ->email($user->email)
            ->callback(route('subscription.callback', [
                'plan_code' => $planCode,
                'user_id' => $user->id,
            ]))
            ->metadata([
                'plan_code' => $planCode,
                'user_id' => $user->id,
                'subscription_flow' => true,
            ])
            ->with('paystack')
            ->redirect();
    }
    
    /**
     * Payment callback - create subscription
     */
    public function callback(Request $request)
    {
        $reference = $request->input('reference');
        $planCode = $request->input('plan_code');
        $userId = $request->input('user_id');
        
        if (!$reference || !$planCode || !$userId) {
            return redirect()->route('subscription.failed')
                ->with('error', 'Invalid callback parameters.');
        }
        
        try {
            // Verify payment
            $verification = Payment::verify($reference, 'paystack');
            
            if (!$verification->isSuccessful()) {
                return redirect()->route('subscription.failed')
                    ->with('error', 'Payment verification failed.');
            }
            
            $user = \App\Models\User::findOrFail($userId);
            $authorizationCode = $verification->authorizationCode;
            
            // Create subscription
            $subscription = Payment::subscription()
                ->customer($user->email)
                ->plan($planCode)
                ->with('paystack');
            
            if ($authorizationCode) {
                $subscription->authorization($authorizationCode);
            }
            
            $subscriptionResult = $subscription->subscribe();
            
            // Save subscription
            DB::table('subscriptions')->insert([
                'user_id' => $userId,
                'subscription_code' => $subscriptionResult->subscriptionCode,
                'email_token' => $subscriptionResult->emailToken,
                'plan_code' => $planCode,
                'status' => $subscriptionResult->status,
                'amount' => $subscriptionResult->amount,
                'authorization_code' => $authorizationCode,
                'next_payment_date' => $subscriptionResult->nextPaymentDate,
                'created_at' => now(),
            ]);
            
            // Update user
            $user->update([
                'subscription_status' => 'active',
                'subscription_code' => $subscriptionResult->subscriptionCode,
            ]);
            
            return redirect()->route('subscription.success')
                ->with('subscription', $subscriptionResult);
                
        } catch (\Exception $e) {
            logger()->error('Subscription callback failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()->route('subscription.failed')
                ->with('error', 'Subscription creation failed. Please contact support.');
        }
    }
    
    /**
     * Success page
     */
    public function success()
    {
        $subscription = session('subscription');
        
        return view('subscriptions.success', compact('subscription'));
    }
    
    /**
     * Failed page
     */
    public function failed()
    {
        $error = session('error', 'An error occurred during subscription.');
        
        return view('subscriptions.failed', compact('error'));
    }
}
```

### Alternative: Direct Subscription (No Redirect)

```php
// Direct subscription creation (no redirect)
$subscription = Payment::subscription()
    ->customer('customer@example.com')
    ->plan('PLN_abc123')
    ->with('paystack')
    ->subscribe();
```

---

## Managing Subscriptions

### Get Subscription Details

```php
$subscription = Payment::subscription('SUB_xyz789')
    ->with('paystack')
    ->get();

// Check status
if ($subscription->isActive()) {
    // Active subscription
}

// Access properties
$subscription->subscriptionCode;
$subscription->status;
$subscription->nextPaymentDate;
$subscription->emailToken;
```

### Cancel Subscription

```php
$cancelled = Payment::subscription('SUB_xyz789')
    ->token('email_token_here')  // Required: token from subscription creation
    ->with('paystack')
    ->cancel();

// Or pass token as parameter
$cancelled = Payment::subscription('SUB_xyz789')
    ->with('paystack')
    ->cancel('email_token_here');
```

### Enable Cancelled Subscription

```php
$enabled = Payment::subscription('SUB_xyz789')
    ->token('email_token_here')
    ->with('paystack')
    ->enable();
```

### List Subscriptions

```php
// List all subscriptions
$subscriptions = Payment::subscription()
    ->with('paystack')
    ->list();

// With pagination
$subscriptions = Payment::subscription()
    ->perPage(20)
    ->page(2)
    ->with('paystack')
    ->list();

// Filter by customer
$subscriptions = Payment::subscription()
    ->with('paystack')
    ->list('customer@example.com');
```

---

## Plan Management

### Get Plan Details

```php
$plan = Payment::subscription()
    ->plan('PLN_abc123')
    ->with('paystack')
    ->getPlan();

// Access properties
$plan['plan_code'];
$plan['amount'];  // In kobo (minor units)
```

### Update Plan

```php
// Update plan name and amount
$updated = Payment::subscription()
    ->plan('PLN_abc123')
    ->planUpdates([
        'name' => 'Updated Plan Name',
        'amount' => 600000,  // Amount in kobo (₦6,000)
    ])
    ->with('paystack')
    ->updatePlan();

// Update only specific fields
$updated = Payment::subscription()
    ->plan('PLN_abc123')
    ->planUpdates([
        'description' => 'New description',
    ])
    ->with('paystack')
    ->updatePlan();
```

### List Plans

```php
$plans = Payment::subscription()
    ->with('paystack')
    ->listPlans();

// With pagination
$plans = Payment::subscription()
    ->perPage(20)
    ->page(1)
    ->with('paystack')
    ->listPlans();
```

---

## Complete Workflow Examples

### Example 1: Complete Subscription Setup

```php
// Step 1: Create plan
$planDTO = new SubscriptionPlanDTO(
    name: 'Pro Monthly',
    amount: 10000.00,
    interval: 'monthly',
    currency: 'NGN'
);

$plan = Payment::subscription()
    ->planData($planDTO)
    ->with('paystack')
    ->createPlan();

// Step 2: Create subscription
$subscription = Payment::subscription()
    ->customer(auth()->user()->email)
    ->plan($plan['plan_code'])
    ->trialDays(14)
    ->with('paystack')
    ->subscribe();

// Step 3: Save to database
DB::table('subscriptions')->insert([
    'user_id' => auth()->id(),
    'subscription_code' => $subscription->subscriptionCode,
    'email_token' => $subscription->emailToken,
    'plan_code' => $plan['plan_code'],
    'status' => $subscription->status,
]);
```

### Example 2: Subscription Management in Controller

```php
class SubscriptionController extends Controller
{
    public function create(Request $request)
    {
        $subscription = Payment::subscription()
            ->customer(auth()->user()->email)
            ->plan($request->plan_code)
            ->with('paystack')
            ->subscribe();

        // Save to database
        auth()->user()->subscriptions()->create([
            'subscription_code' => $subscription->subscriptionCode,
            'email_token' => $subscription->emailToken,
            'status' => $subscription->status,
        ]);
    }

    public function cancel(Request $request, string $code)
    {
        $cancelled = Payment::subscription($code)
            ->token($request->token)
            ->with('paystack')
            ->cancel();

        // Update database
        auth()->user()->subscriptions()
            ->where('subscription_code', $code)
            ->update(['status' => 'cancelled']);
    }
}
```

### Example 3: Subscription Status Check Middleware

```php
class CheckActiveSubscription
{
    public function handle($request, Closure $next)
    {
        $user = auth()->user();
        
        if (!$user->subscription_code) {
            return redirect()->route('subscription.create');
        }

        $subscription = Payment::subscription($user->subscription_code)
            ->with('paystack')
            ->get();

        if (!$subscription->isActive()) {
            return redirect()->route('subscription.expired');
        }

        return $next($request);
    }
}
```

### Example 4: Webhook Handler for Subscription Events

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Facades\Payment;
use Illuminate\Support\Facades\DB;

class SubscriptionWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Validate webhook (handled by PayZephyr middleware)
        $payload = $request->all();

        // Handle subscription events
        if ($payload['event'] === 'subscription.create') {
            $this->handleSubscriptionCreated($payload['data']);
        } elseif ($payload['event'] === 'subscription.disable') {
            $this->handleSubscriptionCancelled($payload['data']);
        } elseif ($payload['event'] === 'subscription.enable') {
            $this->handleSubscriptionEnabled($payload['data']);
        } elseif ($payload['event'] === 'invoice.payment_failed') {
            $this->handlePaymentFailed($payload['data']);
        } elseif ($payload['event'] === 'invoice.payment_succeeded') {
            $this->handlePaymentSucceeded($payload['data']);
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleSubscriptionCreated($data)
    {
        // Update subscription status in database
        DB::table('user_subscriptions')
            ->where('subscription_code', $data['subscription_code'])
            ->update([
                'status' => $data['status'],
                'next_payment_date' => $data['next_payment_date'],
            ]);
    }

    protected function handlePaymentSucceeded($data)
    {
        // Update subscription after successful payment
        try {
            $subscription = Payment::subscription($data['subscription']['subscription_code'])
                ->with('paystack')
                ->get();

            DB::table('user_subscriptions')
                ->where('subscription_code', $subscription->subscriptionCode)
                ->update([
                    'status' => $subscription->status,
                    'next_payment_date' => $subscription->nextPaymentDate,
                ]);
        } catch (\Exception $e) {
            logger()->error('Failed to update subscription from webhook', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    protected function handlePaymentFailed($data)
    {
        // Handle failed payment - maybe send notification
        logger()->warning('Subscription payment failed', [
            'subscription_code' => $data['subscription']['subscription_code'],
        ]);
    }
}
```

---

## Error Handling

### Try-Catch Examples

```php
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;

// Creating subscription with error handling
try {
    $subscription = Payment::subscription()
        ->customer('customer@example.com')
        ->plan('PLN_abc123')
        ->with('paystack')
        ->subscribe();
} catch (SubscriptionException $e) {
    // Handle subscription-specific errors
    logger()->error('Subscription creation failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    return response()->json([
        'error' => 'Failed to create subscription',
        'message' => $e->getMessage(),
    ], 400);
} catch (\Exception $e) {
    // Handle other errors
    return response()->json([
        'error' => 'An unexpected error occurred',
    ], 500);
}

// Creating plan with error handling
try {
    $plan = Payment::subscription()
        ->planData($planDTO)
        ->with('paystack')
        ->createPlan();
} catch (PlanException $e) {
    logger()->error('Plan creation failed', [
        'error' => $e->getMessage(),
    ]);
    
    return back()->withErrors(['plan' => $e->getMessage()]);
}
```

### Validation Before Operations

```php
// Validate subscription code exists before operations
try {
    $subscription = Payment::subscription($subscriptionCode)
        ->with('paystack')
        ->get();
} catch (SubscriptionException $e) {
    if (str_contains($e->getMessage(), 'not found')) {
        return response()->json([
            'error' => 'Subscription not found',
        ], 404);
    }
    
    throw $e;
}

// Validate token before cancel/enable
if (!$emailToken) {
    return response()->json([
        'error' => 'Email token is required',
    ], 400);
}

$cancelled = Payment::subscription($subscriptionCode)
    ->token($emailToken)
    ->with('paystack')
    ->cancel();
```

---

## Best Practices

### 1. Store Subscription Data

Always save subscription codes and email tokens to your database immediately after creation (see [Basic Subscription](#basic-subscription) example).

### 2. Use Provider Selection

Always specify the provider explicitly: `->with('paystack')`

### 3. Handle Webhooks

Set up webhook handlers to keep subscription status in sync (see [Example 4](#example-4-webhook-handler-for-subscription-events)).

### 4. Check Subscription Status Regularly

Use `$subscription->isActive()` to check status (see [Get Subscription Details](#get-subscription-details)).

### 5. Use Metadata for Tracking

Include relevant data in metadata (see [Subscription with Metadata](#subscription-with-metadata)).

### 6. Error Handling Pattern

Always wrap subscription operations in try-catch blocks (see [Error Handling](#error-handling) section).

---

## Security Considerations

### 1. Email Token Security

The email token is required for cancel/enable operations. Treat it as sensitive data:

- **Store securely**: Use encrypted database columns if possible
- **Never expose**: Don't include in URLs or client-side code
- **Validate ownership**: Always verify the user owns the subscription before allowing cancel/enable

### 2. Webhook Security

- **Enable signature verification**: Always verify webhook signatures in production
- **Use HTTPS**: All webhook URLs must use HTTPS
- **Validate events**: Verify event types before processing
- **Idempotency**: Handle duplicate webhook deliveries gracefully

### 3. Amount Validation

- **Validate amounts**: Always validate amounts before creating plans or subscriptions
- **Use DTOs**: The `SubscriptionPlanDTO` and `SubscriptionRequestDTO` automatically validate amounts
- **Check currency**: Ensure currency matches your business requirements

### 4. Error Handling

- **Don't expose internals**: Never expose internal error messages to users
- **Log securely**: Log errors with context but sanitize sensitive data
- **Monitor failures**: Set up alerts for subscription failures

---

## Developer Guide: Adding Subscription Support to a Driver

If you're a developer and want to add subscription support for a new driver, follow this guide.

### Prerequisites

1. **Understand the Architecture**: Read the [Architecture Guide](architecture.md) to understand how drivers work
2. **Review PaystackDriver**: Study `src/Drivers/PaystackDriver.php` and `src/Traits/PaystackSubscriptionMethods.php`
3. **Understand the Interface**: Review `src/Contracts/SupportsSubscriptionsInterface.php`

### Step 1: Implement the Interface

Your driver class must implement `SupportsSubscriptionsInterface`:

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;

final class YourDriver extends AbstractDriver implements SupportsSubscriptionsInterface
{
    // Your driver implementation
}
```

### Step 2: Create Subscription Methods Trait (Recommended)

Following the Single Responsibility Principle (SRP), create a trait for subscription methods:

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Throwable;

trait YourProviderSubscriptionMethods
{
    /**
     * Create a subscription plan
     *
     * @return PlanResponseDTO
     *
     * @throws PlanException If the plan creation fails
     */
    public function createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO
    {
        try {
            // Convert plan DTO to provider-specific format
            $payload = [
                'name' => $plan->name,
                'amount' => $plan->getAmountInMinorUnits(), // Use DTO method for conversion
                'interval' => $this->mapInterval($plan->interval), // Map to provider format
                'currency' => $plan->currency,
                // ... provider-specific fields
            ];

            $response = $this->makeRequest('POST', '/plans', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            // Validate response
            if (!isset($data['id'])) {
                throw new PlanException('Failed to create subscription plan');
            }

            $this->log('info', 'Subscription plan created', [
                'plan_id' => $data['id'],
                'name' => $plan->name,
            ]);

            // Return normalized format
            return [
                'plan_code' => $data['id'],
                'name' => $data['name'],
                'amount' => $data['amount'],
                'interval' => $this->normalizeInterval($data['interval']),
                'currency' => $data['currency'],
            ];
        } catch (PlanException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create plan', [
                'error' => $e->getMessage(),
            ]);
            throw new PlanException(
                'Failed to create plan: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create a subscription
     *
     * @throws SubscriptionException If subscription creation fails
     */
    public function createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO
    {
        try {
            $payload = [
                'customer' => $request->customer,
                'plan' => $request->plan,
                'quantity' => $request->quantity ?? 1,
                // ... provider-specific fields
            ];

            if ($request->trialDays) {
                $payload['trial_period_days'] = $request->trialDays;
            }

            if ($request->startDate) {
                $payload['start_date'] = $request->startDate;
            }

            if ($request->authorization) {
                $payload['authorization'] = $request->authorization;
            }

            if (!empty($request->metadata)) {
                $payload['metadata'] = $request->metadata;
            }

            $response = $this->makeRequest('POST', '/subscriptions', [
                'json' => $payload,
            ]);

            $data = $this->parseResponse($response);

            // Validate response
            if (!isset($data['id'])) {
                throw new SubscriptionException('Failed to create subscription');
            }

            $this->log('info', 'Subscription created', [
                'subscription_id' => $data['id'],
                'customer' => $request->customer,
                'plan' => $request->plan,
            ]);

            // Return normalized SubscriptionResponseDTO
            return new SubscriptionResponseDTO(
                subscriptionCode: $data['id'],
                status: $this->normalizeStatus($data['status']),
                customer: $data['customer']['email'] ?? $request->customer,
                plan: $data['plan']['name'] ?? $request->plan,
                amount: ($data['amount'] ?? 0) / 100, // Convert from minor to major units
                currency: $data['currency'] ?? 'USD',
                nextPaymentDate: $data['next_payment_date'] ?? null,
                emailToken: $data['email_token'] ?? null, // Provider-specific token
                metadata: $data['metadata'] ?? [],
                provider: $this->getName(),
            );
        } catch (SubscriptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to subscribe', [
                'error' => $e->getMessage(),
            ]);
            throw new SubscriptionException(
                'Failed to subscribe: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    // Implement other required methods:
    // - updatePlan()
    // - getPlan()
    // - listPlans()
    // - fetchSubscription()
    // - cancelSubscription()
    // - enableSubscription()
    // - listSubscriptions()

    /**
     * Map interval from unified format to provider format
     */
    protected function mapInterval(string $interval): string
    {
        return match ($interval) {
            'daily' => 'day',
            'weekly' => 'week',
            'monthly' => 'month',
            'annually' => 'year',
            default => $interval,
        };
    }

    /**
     * Normalize interval from provider format to unified format
     */
    protected function normalizeInterval(string $interval): string
    {
        return match (strtolower($interval)) {
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'year' => 'annually',
            default => $interval,
        };
    }

    /**
     * Normalize status from provider format to unified format
     */
    protected function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'enabled' => 'active',
            'cancelled', 'disabled', 'canceled' => 'cancelled',
            'completed', 'ended' => 'completed',
            default => strtolower($status),
        };
    }
}
```

### Step 3: Use the Trait in Your Driver

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\Traits\YourProviderSubscriptionMethods;

final class YourDriver extends AbstractDriver implements SupportsSubscriptionsInterface
{
    use YourProviderSubscriptionMethods;

    protected string $name = 'yourprovider';

    // Your driver implementation
}
```

### Step 4: Implement All Required Methods

The `SupportsSubscriptionsInterface` requires these methods:

1. **`createPlan(SubscriptionPlanDTO $plan): PlanResponseDTO`** - Create a subscription plan
2. **`updatePlan(string $planCode, array $updates): PlanResponseDTO`** - Update a plan
3. **`fetchPlan(string $planCode): PlanResponseDTO`** - Get plan details
4. **`listPlans(?int $perPage = 50, ?int $page = 1): array`** - List all plans
5. **`createSubscription(SubscriptionRequestDTO $request): SubscriptionResponseDTO`** - Create a subscription
6. **`fetchSubscription(string $subscriptionCode): SubscriptionResponseDTO`** - Fetch subscription details
7. **`cancelSubscription(string $subscriptionCode, string $token): SubscriptionResponseDTO`** - Cancel a subscription
8. **`enableSubscription(string $subscriptionCode, string $token): SubscriptionResponseDTO`** - Enable a cancelled subscription
9. **`listSubscriptions(?int $perPage = 50, ?int $page = 1, ?string $customer = null): array`** - List subscriptions

### Step 5: Handle Amount Conversion

**CRITICAL**: Always use the DTO's `getAmountInMinorUnits()` method for plan creation:

```php
$payload = [
    'amount' => $plan->getAmountInMinorUnits(), // ✅ Correct - converts to minor units
    // NOT: 'amount' => $plan->amount * 100, // ❌ Wrong
];
```

When returning amounts from API responses, convert from minor to major units:

```php
amount: ($result['amount'] ?? 0) / 100, // Convert from minor to major units
```

### Step 6: Error Handling

Always use the specific exceptions:

- **`PlanException`** for plan-related errors
- **`SubscriptionException`** for subscription-related errors

Never use generic `PaymentException` for subscription operations.

### Step 7: Write Tests

Create comprehensive tests for all subscription operations:

```php
<?php

use KenDeNigerian\PayZephyr\Drivers\YourDriver;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

test('your driver creates plan successfully', function () {
    $driver = new YourDriver($config);
    // ... test implementation
});

// Test all methods:
// - createPlan()
// - updatePlan()
// - getPlan()
// - listPlans()
// - createSubscription()
// - fetchSubscription()
// - cancelSubscription()
// - enableSubscription()
// - listSubscriptions()
```

### Step 8: Update Documentation

1. Update this file (`docs/SUBSCRIPTIONS.md`) to include your provider in the support table
2. Add provider-specific notes if needed
3. Update `docs/providers.md` if applicable

### Step 9: Submit a Pull Request

1. Ensure all tests pass
2. Run PHPStan: `composer analyse`
3. Run Pint: `composer format`
4. Submit your PR with:
   - Clear description of changes
   - Test coverage
   - Documentation updates

### Key Principles

1. **Follow SRP**: Use traits for subscription methods (like `PaystackSubscriptionMethods`)
2. **Use DTOs**: Always use `SubscriptionPlanDTO`, `SubscriptionRequestDTO`, and `SubscriptionResponseDTO`
3. **Normalize Data**: Convert provider-specific formats to unified formats
4. **Error Handling**: Use specific exceptions (`PlanException`, `SubscriptionException`)
5. **Amount Conversion**: Always use DTO methods for amount conversion
6. **Logging**: Log all operations for debugging
7. **Testing**: Write comprehensive tests

### Example: Complete Implementation

See `src/Drivers/PaystackDriver.php` and `src/Traits/PaystackSubscriptionMethods.php` for a complete reference implementation.

---

## Summary

Subscriptions in PayZephyr follow the same unified fluent builder pattern as payments:

- **Builder methods** return `$this` for chaining
- **Final action methods** execute the operation (use `subscribe()` for clarity - `create()` is also available as an alias)
- **Provider selection** uses `with()` or `using()` (same as Payment)
- **Consistent API** across all operations
- **Helper function support**: Both `Payment::subscription()` and `payment()->subscription()` work identically

**Current Status**: Only PaystackDriver supports subscriptions. Support for other providers will be added in future releases.

**For Developers**: If you want to add subscription support for a new driver, see the [Developer Guide](#developer-guide-adding-subscription-support-to-a-driver) section above.

This provides a seamless experience when working with both one-time payments and recurring subscriptions.
