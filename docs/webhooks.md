# Webhooks

Webhooks are notifications from payment providers to your application.

## Routes

Routes are automatically created:
- `POST /payments/webhook/paystack`
- `POST /payments/webhook/flutterwave`
- `POST /payments/webhook/monnify`
- `POST /payments/webhook/stripe`
- `POST /payments/webhook/paypal`
- `POST /payments/webhook/square`
- `POST /payments/webhook/opay`
- `POST /payments/webhook/mollie`
- `POST /payments/webhook/nowpayments`

## Flow

1. Provider sends webhook → Controller verifies signature
2. Webhook queued → Returns 202 Accepted
3. Queue worker processes → Updates database → Fires events

**Important:** Queue workers must be running for webhooks to process.

## Configuration

Add webhook URLs in provider dashboards:
- Paystack: `https://yourdomain.com/payments/webhook/paystack`
- Stripe: `https://yourdomain.com/payments/webhook/stripe`
- NOWPayments: `https://yourdomain.com/payments/webhook/nowpayments`
- See routes above for all providers

```php
// config/payments.php
'webhook' => [
    'verify_signature' => true,
    'max_payload_size' => 1048576, // 1MB
    'max_retries' => 3,              // Maximum webhook processing retries (default: 3)
    'retry_backoff' => 60,           // Seconds to wait before retry (default: 60)
],
```

**Environment Variables:**
```env
PAYMENTS_WEBHOOK_MAX_RETRIES=3      # Maximum webhook processing retries
PAYMENTS_WEBHOOK_RETRY_BACKOFF=60   # Seconds to wait before retry
```

**Note:** Webhook retry settings control how many times a failed webhook will be retried and how long to wait between retries. These settings help ensure reliable webhook delivery even when temporary errors occur.

---

## Queue Workers (Required)

Webhooks are processed asynchronously. Queue workers must be running.

### Setup

```env
# Development
QUEUE_CONNECTION=sync

# Production
QUEUE_CONNECTION=database
```

If using `database`, create jobs table:
```bash
php artisan queue:table
php artisan migrate
```

Run queue worker:
```bash
php artisan queue:work
```

### Production Setup

**Supervisor:**
```ini
# /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
command=php /path/to/project/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
```

**Systemd:**
```ini
# /etc/systemd/system/laravel-worker.service
[Service]
ExecStart=/usr/bin/php /path/to/project/artisan queue:work --sleep=3 --tries=3
Restart=always
```

**Verify:** `php artisan queue:monitor` or `php artisan queue:failed`

**Troubleshooting:**
- Jobs not processing: Check `ps aux | grep queue:work`, verify `QUEUE_CONNECTION` in `.env`
- Jobs failing: Check `storage/logs/laravel.log`, retry with `php artisan queue:retry all`

---

## Handling Webhooks

### Event Listeners

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
];

// app/Listeners/HandlePaystackWebhook.php
class HandlePaystackWebhook
{
    public function handle(array $payload): void
    {
        match($payload['event']) {
            'charge.success' => $this->handleSuccess($payload['data']),
            'charge.failed' => $this->handleFailure($payload['data']),
            default => logger()->info("Unhandled event: {$payload['event']}"),
        };
    }
    
    private function handleSuccess(array $data): void
    {
        $order = Order::where('payment_reference', $data['reference'])->first();
        
        if ($order && $order->status === 'pending') {
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            Mail::to($order->customer_email)->send(new OrderConfirmation($order));
        }
    }
        
        if ($order) {
            // Mark order as failed
            $order->update(['status' => 'failed']);
            
            // Notify customer that payment failed
            Mail::to($order->customer_email)->send(new PaymentFailed($order));
            
            Log::warning("Payment failed for order {$order->id}", [
                'reference' => $reference,
            ]);
        }
    }
}
```

#### Step 3: Create a Generic Handler (Optional)

If you want one listener that handles webhooks from ALL providers, create `app/Listeners/HandleAnyWebhook.php`:

```php
<?php

namespace App\Listeners;

use App\Models\Order;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use Illuminate\Support\Facades\Log;

class HandleAnyWebhook
{
    /**
     * Handle webhooks from any payment provider.
     * 
     * @param  string  $provider  Which provider sent it ('paystack', 'stripe', etc.)
     * @param  array  $payload  The webhook data
     */
    public function handle(string $provider, array $payload): void
    {
        // Extract the payment reference (each provider structures data differently)
        $reference = $this->extractReference($provider, $payload);
        
        if (!$reference) {
            Log::warning("No reference found in webhook", [
                'provider' => $provider,
            ]);
            return;
        }
        
        try {
            // Double-check the payment status with the provider
            // (This is a security best practice - don't trust webhooks blindly!)
            $verification = Payment::verify($reference, $provider);
            
            if ($verification->isSuccessful()) {
                $this->updateOrder($reference, $verification);
            }
        } catch (\Exception $e) {
            Log::error("Webhook processing failed", [
                'provider' => $provider,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Find the payment reference in the webhook data.
     * Delegates to the driver to extract the reference according to its webhook format.
     * This follows the Open/Closed Principle - each driver handles its own data extraction.
     */
    private function extractReference(string $provider, array $payload): ?string
    {
        try {
            $manager = app(PaymentManager::class);
            $driver = $manager->driver($provider);
            return $driver->extractWebhookReference($payload);
        } catch (\KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException $e) {
            // Unknown provider - return null
            return null;
        }
    }
    
    /**
     * Update the order when payment succeeds.
     */
    private function updateOrder(string $reference, $verification): void
    {
        $order = Order::where('payment_reference', $reference)->first();
        
        if ($order) {
            $order->update([
                'status' => 'paid',
                'amount_paid' => $verification->amount,
                'paid_at' => $verification->paidAt,
                'payment_channel' => $verification->channel,  // card, bank_transfer, etc.
                'payment_provider' => $verification->provider,  // paystack, stripe, etc.
            ]);
            
            Log::info("Order updated from webhook", [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);
        }
    }
}
```

---

## Understanding Webhook Data Structures

Each payment provider sends webhook data in a slightly different format. Here's what to expect:

### Paystack Webhook Format

```json
{
  "event": "charge.success",
  "data": {
    "reference": "ref_1234567890",
    "amount": 50000,
    "currency": "NGN",
    "status": "success",
    "customer": {
      "email": "customer@example.com"
    },
    "channel": "card",
    "paid_at": "2024-01-15T10:30:00.000Z"
  }
}
```

**Key fields:**
- `event`: What happened (`charge.success`, `charge.failed`, etc.)
- `data.reference`: The transaction reference you can use to verify
- `data.status`: Payment status
- `data.amount`: Amount in minor currency unit (50000 = ₦500.00) - returned by provider

### Flutterwave Webhook Format

```json
{
  "event": "charge.completed",
  "data": {
    "id": 123456,
    "tx_ref": "FLW_ref_1234567890",
    "amount": 100.00,
    "currency": "NGN",
    "status": "successful",
    "payment_type": "card"
  }
}
```

**Key fields:**
- `event`: Event type
- `data.tx_ref`: Transaction reference (this is what you use!)
- `data.status`: Payment status

### Monnify Webhook Format

```json
{
  "eventType": "SUCCESSFUL_TRANSACTION",
  "paymentReference": "MON_ref_1234567890",
  "amountPaid": "25000.00",
  "paymentStatus": "PAID",
  "paymentMethod": "CARD"
}
```

**Key fields:**
- `eventType`: What happened
- `paymentReference`: Transaction reference
- `paymentStatus`: Status of payment

### Stripe Webhook Format

```json
{
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_xxx",
      "amount": 10000,
      "currency": "usd",
      "status": "succeeded",
      "metadata": {
        "reference": "ORDER_123"
      }
    }
  }
}
```

**Key fields:**
- `type`: Event type
- `data.object.metadata.reference`: Your custom reference
- `data.object.status`: Payment status

### PayPal Webhook Format

```json
{
  "event_type": "PAYMENT.CAPTURE.COMPLETED",
  "resource": {
    "id": "xxx",
    "custom_id": "PAYPAL_ref_123",
    "amount": {
      "value": "100.00",
      "currency_code": "USD"
    },
    "status": "COMPLETED"
  }
}
```

**Key fields:**
- `event_type`: What happened
- `resource.custom_id`: Your custom reference
- `resource.status`: Payment status

### Square Webhook Format

```json
{
  "merchant_id": "xxx",
  "type": "payment.updated",
  "event_id": "xxx",
  "created_at": "2024-01-15T10:30:00.000Z",
  "data": {
    "type": "payment",
    "id": "xxx",
    "object": {
      "payment": {
        "id": "xxx",
        "order_id": "xxx",
        "reference_id": "ORDER_123",
        "status": "COMPLETED"
      }
    }
  }
}
```

**Key fields:**
- `type`: Event type (`payment.created`, `payment.updated`, etc.)
- `data.object.payment.reference_id`: Your custom reference
- `data.object.payment.status`: Payment status

### OPay Webhook Format

```json
{
  "orderNo": "ORDER_123",
  "status": "SUCCESS",
  "amount": "100.00",
  "currency": "NGN",
  "paymentMethod": "CARD",
  "transTime": "2024-01-15T10:30:00.000Z"
}
```

**Key fields:**
- `orderNo`: Your transaction reference
- `status`: Payment status (`SUCCESS`, `FAILED`, etc.)
- `amount`: Payment amount

### Mollie Webhook Format

```json
{
  "id": "tr_WDqYK6vllg",
  "mode": "test",
  "createdAt": "2024-01-15T10:30:00.000Z",
  "status": "paid",
  "amount": {
    "value": "10.00",
    "currency": "EUR"
  },
  "metadata": {
    "reference": "ORDER_123"
  },
  "method": "ideal"
}
```

**Key fields:**
- `id`: Mollie payment ID
- `status`: Payment status (`paid`, `open`, `pending`, `failed`, `canceled`, `expired`, `authorized`)
- `metadata.reference`: Your custom reference (if provided)
- `amount.value`: Payment amount
- `amount.currency`: Currency code

**Note:** Mollie supports signature-based webhook validation using HMAC SHA-256 when `MOLLIE_WEBHOOK_SECRET` is configured. If not configured, PayZephyr automatically fetches payment details from the Mollie API to verify webhooks (fallback method).

---

## Security Best Practices

### 1. Always Verify Webhook Signatures

**Never disable signature verification in production!**

```php
// config/payments.php
'webhook' => [
    'verify_signature' => true,  // ← Keep this true!
],
```

**Why?** Without signature verification, anyone could send fake webhooks to your server and trick your app into thinking payments succeeded when they didn't!

### 2. Always Re-Verify Payment Status

Don't just trust the webhook data - double-check with the provider:

```php
// In your webhook listener
$verification = Payment::verify($reference, $provider);

if ($verification->isSuccessful()) {
    // Now you're sure - process the order
    $order->update(['status' => 'paid']);
}
```

**Why?** Webhooks can be delayed, lost, or duplicated. Verifying ensures you have the latest, accurate status.

### 3. Handle Duplicate Webhooks (Idempotency)

PayZephyr automatically protects against race conditions in transaction updates using database row locking (`lockForUpdate()`). However, providers sometimes send the same webhook multiple times, so you should still implement idempotency checks in your application logic:

```php
$order = Order::where('payment_reference', $reference)->first();

// Check if already processed
if ($order->status === 'paid') {
    Log::info("Order already processed, ignoring duplicate webhook", [
        'reference' => $reference,
    ]);
    return;  // Exit early - don't process again
}

// Process the order...
$order->update(['status' => 'paid']);
```

### 4. Log Everything

Log all webhook activity for debugging:

```php
Log::info("Webhook received", [
    'provider' => $provider,
    'event' => $payload['event'] ?? 'unknown',
    'reference' => $reference,
    'ip' => $request->ip(),
]);
```

### 5. Handle Errors Gracefully

Always catch exceptions and return 200 OK to the provider:

```php
try {
    // Process webhook
    $this->handlePayment($payload);
} catch (\Exception $e) {
    Log::error("Webhook processing failed", [
        'error' => $e->getMessage(),
        'provider' => $provider,
        'reference' => $reference,
    ]);
    
    // Return 200 OK so provider doesn't retry
    // (You'll handle the error manually later)
    return response()->json(['status' => 'received'], 200);
}
```

**Why return 200?** If you return an error code (400, 500), the provider will keep retrying the webhook. Return 200 to say "I got it" (even if processing failed), then fix the issue manually.

---

## Testing Webhooks Locally

### Option 1: Using ngrok (Recommended)

ngrok creates a secure tunnel to your local server so payment providers can send webhooks to it.

**Step 1: Install ngrok**
- Download from https://ngrok.com
- Or use: `brew install ngrok` (Mac) or `choco install ngrok` (Windows)

**Step 2: Start your Laravel server**
```bash
php artisan serve
# Server runs on http://localhost:8000
```

**Step 3: Start ngrok**
```bash
ngrok http 8000
```

You'll see output like:
```
Forwarding  https://abc123.ngrok.io -> http://localhost:8000
```

**Step 4: Use the ngrok URL in provider dashboard**
- Go to Paystack dashboard → Settings → Webhooks
- Add URL: `https://abc123.ngrok.io/payments/webhook/paystack`
- Now Paystack can send webhooks to your local server!

**Important:** The ngrok URL changes every time you restart ngrok (unless you have a paid plan). Update the webhook URL in the provider dashboard each time.

### Option 2: Manual Testing with curl

You can manually send a test webhook to your local server:

```bash
curl -X POST http://localhost:8000/payments/webhook/paystack \
  -H "Content-Type: application/json" \
  -H "x-paystack-signature: YOUR_SIGNATURE_HERE" \
  -d '{
    "event": "charge.success",
    "data": {
      "reference": "test_ref_123",
      "amount": 50000,
      "currency": "NGN",
      "status": "success"
    }
  }'
```

**Note:** For real testing, you need the correct signature. Generate it using your Paystack secret key and the request body.

---

## Troubleshooting Common Issues

### Problem: Webhook Not Received

**Symptoms:** Payment succeeds but your webhook listener never runs.

**Solutions:**
1. ✅ Check provider dashboard - look for webhook delivery status
2. ✅ Verify webhook URL is correct (check for typos)
3. ✅ Make sure URL is accessible (test with curl or Postman)
4. ✅ Check if HTTPS is required (most providers need HTTPS)
5. ✅ Check server firewall - make sure it's not blocking incoming requests
6. ✅ Check Laravel logs: `storage/logs/laravel.log`

### Problem: Signature Validation Fails

**Symptoms:** Webhook returns 403 Forbidden error.

**Solutions:**
1. Verify webhook secret is correct in `.env` file
2. Ensure `PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true` in config
3. Check provider documentation for correct header name
4. Ensure raw request body is used (not parsed JSON)
5. Check for extra whitespace in secret key

### Problem: Duplicate Webhooks Processed

**Symptoms:** Same order gets processed multiple times.

**Solutions:**
1. Add idempotency check (see "Security Best Practices" above)
2. Use database transactions
3. Check order status before processing
4. Use database unique constraints on payment_reference

### Problem: Webhook Arrives Before Customer Returns

**This is normal!** Webhooks are often faster than the customer's browser redirect.

**Solution:** Handle both scenarios:
- If webhook arrives first: Update order status, send email
- If customer returns first: Check webhook status, or verify payment manually

---

## Complete Webhook Flow Example

Here's a complete example showing the entire payment and webhook flow:

### 1. Customer Initiates Payment

```php
// In your controller
public function checkout(Request $request)
{
    $order = Order::create([
        'user_id' => auth()->id(),
        'amount' => 500.00, // Store in major currency unit
        'status' => 'pending',
        'payment_reference' => 'ORDER_' . time(),
    ]);
    
    // Redirect to payment
    return Payment::amount(500.00)
        ->currency('NGN')
        ->email($request->user()->email)
        ->reference($order->payment_reference)
        ->metadata(['order_id' => $order->id])
        ->callback(route('payment.callback'))
        ->redirect();
}
```

### 2. Customer Completes Payment on Provider's Site

Customer enters card details and pays on Paystack's checkout page.

### 3. Customer Returns to Your Site (Callback)

```php
// routes/web.php
Route::get('/payment/callback', [PaymentController::class, 'callback'])
    ->name('payment.callback');

// In your controller
public function callback(Request $request)
{
    $reference = $request->input('reference');
    
    try {
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            $order = Order::where('payment_reference', $reference)->first();
            
            // Double-check status (webhook might have already updated it)
            if ($order->status !== 'paid') {
                $order->update(['status' => 'paid', 'paid_at' => now()]);
            }
            
            return view('payment.success', ['order' => $order]);
        }
        
        return view('payment.failed');
    } catch (\Exception $e) {
        Log::error('Payment verification failed', [
            'reference' => $reference,
            'error' => $e->getMessage(),
        ]);
        
        return view('payment.error');
    }
}
```

### 4. Webhook Arrives (May Happen Before or After Step 3)

```php
// app/Listeners/HandlePaystackWebhook.php
public function handle(array $payload): void
{
    $event = $payload['event'] ?? null;
    
    if ($event === 'charge.success') {
        $reference = $payload['data']['reference'];
        
        // Find order
        $order = Order::where('payment_reference', $reference)->first();
        
        if ($order && $order->status !== 'paid') {
            // Update order
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            
            // Send email
            Mail::to($order->user->email)->send(new OrderConfirmation($order));
            
            // Process order (fulfillment, inventory, etc.)
            ProcessOrder::dispatch($order);
        }
    }
}
```

---

## Subscription Webhooks

PayZephyr automatically handles subscription-related webhooks and dispatches Laravel events for subscription lifecycle changes.

### Subscription Webhook Events

#### Paystack Subscription Events

Paystack sends the following subscription events:

- `subscription.create` - New subscription created
- `subscription.disable` - Subscription cancelled
- `subscription.enable` - Cancelled subscription reactivated
- `subscription.not_renew` - Subscription set to non-renewing
- `invoice.payment_failed` - Subscription payment failed
- `invoice.payment_failed` - Subscription payment succeeded (renewal)

### Handling Subscription Webhooks

#### Using Event Listeners

```php
// app/Providers/EventServiceProvider.php
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;

protected $listen = [
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
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

#### Complete Webhook Handler Example

```php
// app/Listeners/HandlePaystackWebhook.php
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

class HandlePaystackWebhook
{
    public function handle(array $payload): void
    {
        $event = $payload['event'] ?? null;
        
        match($event) {
            // Payment events
            'charge.success' => $this->handlePaymentSuccess($payload['data']),
            'charge.failed' => $this->handlePaymentFailure($payload['data']),
            
            // Subscription events
            'subscription.create' => $this->handleSubscriptionCreated($payload['data']),
            'subscription.disable' => $this->handleSubscriptionCancelled($payload['data']),
            'subscription.enable' => $this->handleSubscriptionEnabled($payload['data']),
            'subscription.not_renew' => $this->handleSubscriptionNotRenew($payload['data']),
            'invoice.payment_failed' => $this->handleSubscriptionPaymentFailed($payload['data']),
            'invoice.payment_success' => $this->handleSubscriptionRenewed($payload['data']),
            
            default => logger()->info("Unhandled event: {$event}"),
        };
    }
    
    private function handleSubscriptionCreated(array $data): void
    {
        // Dispatch Laravel event
        SubscriptionCreated::dispatch(
            $data['subscription_code'],
            'paystack',
            $data
        );
        
        // Update subscription transaction (if logging enabled)
        SubscriptionTransaction::updateOrCreate(
            ['subscription_code' => $data['subscription_code']],
            [
                'provider' => 'paystack',
                'status' => $data['status'] ?? 'active',
                'plan_code' => $data['plan']['plan_code'] ?? $data['plan']['code'] ?? null,
                'customer_email' => $data['customer']['email'] ?? null,
                'amount' => ($data['amount'] ?? 0) / 100,
                'currency' => $data['currency'] ?? 'NGN',
                'next_payment_date' => $data['next_payment_date'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]
        );
    }
    
    private function handleSubscriptionRenewed(array $data): void
    {
        $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
        
        if ($subscriptionCode) {
            SubscriptionRenewed::dispatch(
                $subscriptionCode,
                'paystack',
                $data['reference'] ?? null,
                $data
            );
            
            // Update subscription transaction
            SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                ->update([
                    'status' => 'active',
                    'next_payment_date' => $data['subscription']['next_payment_date'] ?? null,
                ]);
        }
    }
    
    private function handleSubscriptionCancelled(array $data): void
    {
        SubscriptionCancelled::dispatch(
            $data['subscription_code'],
            'paystack',
            $data
        );
        
        // Update subscription transaction
        SubscriptionTransaction::where('subscription_code', $data['subscription_code'])
            ->update(['status' => 'cancelled']);
    }
    
    private function handleSubscriptionPaymentFailed(array $data): void
    {
        $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
        
        if ($subscriptionCode) {
            SubscriptionPaymentFailed::dispatch(
                $subscriptionCode,
                'paystack',
                $data['gateway_response'] ?? 'Payment failed',
                $data
            );
            
            // Update subscription transaction
            SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                ->update(['status' => 'attention']);
        }
    }
    
    // ... other handlers
}
```

### Subscription Webhook Configuration

Configure which subscription events to handle in `config/payments.php`:

```php
'subscriptions' => [
    'webhook_events' => [
        'subscription.create',
        'subscription.disable',
        'subscription.enable',
        'subscription.not_renew',
        'invoice.payment_failed',
        'invoice.payment_success',
    ],
],
```

### Testing Subscription Webhooks

#### Using ngrok

```bash
# Start ngrok
ngrok http 8000

# Use the ngrok URL in Paystack dashboard
# https://your-ngrok-url.ngrok.io/payments/webhook/paystack
```

#### Manual Testing with curl

```bash
# Test subscription.create event
curl -X POST http://localhost:8000/payments/webhook/paystack \
  -H "Content-Type: application/json" \
  -H "x-paystack-signature: your-signature" \
  -d '{
    "event": "subscription.create",
    "data": {
      "subscription_code": "SUB_test123",
      "status": "active",
      "customer": {"email": "test@example.com"},
      "plan": {"plan_code": "PLN_test", "name": "Test Plan"},
      "amount": 500000,
      "currency": "NGN"
    }
  }'
```

### Subscription Event Reference

| Event                     | When It Fires            | Event Class                 | Properties                                                 |
|---------------------------|--------------------------|-----------------------------|------------------------------------------------------------|
| `subscription.create`     | New subscription created | `SubscriptionCreated`       | `subscriptionCode`, `provider`, `data`                     |
| `invoice.payment_success` | Subscription renewed     | `SubscriptionRenewed`       | `subscriptionCode`, `provider`, `invoiceReference`, `data` |
| `subscription.disable`    | Subscription cancelled   | `SubscriptionCancelled`     | `subscriptionCode`, `provider`, `data`                     |
| `invoice.payment_failed`  | Payment failed           | `SubscriptionPaymentFailed` | `subscriptionCode`, `provider`, `reason`, `data`           |

---

## Webhook Events Reference

Different providers send different event types. Here's what to expect:

### Paystack Events
- `charge.success` - Payment succeeded
- `charge.failed` - Payment failed
- `transfer.success` - Transfer succeeded
- `transfer.failed` - Transfer failed
- `subscription.create` - Subscription created
- `subscription.disable` - Subscription cancelled
- `subscription.enable` - Subscription reactivated
- `subscription.not_renew` - Subscription set to non-renewing
- `invoice.payment_failed` - Subscription payment failed
- `invoice.payment_success` - Subscription payment succeeded (renewal)

### Flutterwave Events
- `charge.completed` - Payment completed
- `transfer.completed` - Transfer completed

### Monnify Events
- `SUCCESSFUL_TRANSACTION` - Payment succeeded
- `FAILED_TRANSACTION` - Payment failed
- `OVERPAID_TRANSACTION` - Customer paid more than required

### Stripe Events
- `payment_intent.succeeded` - Payment succeeded
- `payment_intent.payment_failed` - Payment failed
- `charge.succeeded` - Charge succeeded
- `charge.failed` - Charge failed

### PayPal Events
- `PAYMENT.CAPTURE.COMPLETED` - Payment completed
- `PAYMENT.CAPTURE.DENIED` - Payment denied
- `CHECKOUT.ORDER.APPROVED` - Order approved

### Square Events
- `payment.created` - Payment created
- `payment.updated` - Payment status updated
- `payment.completed` - Payment completed
- `payment.failed` - Payment failed

### OPay Events
- Payment status updates sent via webhook
- Status values: `SUCCESS`, `FAILED`, `PENDING`

### Mollie Events
- `payment.paid` - Payment completed successfully
- `payment.pending` - Payment is pending
- `payment.failed` - Payment failed
- `payment.canceled` - Payment was canceled
- `payment.expired` - Payment expired
- `payment.authorized` - Payment authorized (for certain payment methods)
- `hook.ping` - Test webhook event (automatically accepted)

### NOWPayments Events
NOWPayments sends Instant Payment Notifications (IPN) for payment status changes:
- Payment status updates with the following statuses:
  - `waiting` - Payment is waiting for customer action
  - `confirming` - Payment is being confirmed on blockchain
  - `sending` - Payment is being sent
  - `partially_paid` - Partial payment received
  - `finished` - Payment completed successfully
  - `confirmed` - Payment confirmed on blockchain
  - `failed` - Payment failed
  - `refunded` - Payment was refunded
  - `expired` - Payment expired

**Note:** Webhooks are validated using HMAC SHA-512 signature with your IPN secret key.

---

## Quick Start Checklist

1. Install package and run migrations
2. Configure provider credentials in `.env`
3. Add webhook URLs to provider dashboards
4. Set `verify_signature => true` in config
5. Create event listeners in `EventServiceProvider`
6. Create listener classes to handle webhooks
7. Test with ngrok (local) or deploy to production
8. Monitor logs for webhook activity
9. Handle errors gracefully
10. Implement idempotency checks

---

**Need Help?** Check the main [README.md](../README.md) or open an issue on GitHub!
