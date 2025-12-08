# Webhooks Guide - Complete Beginner's Tutorial

## What Are Webhooks? 🤔

Think of webhooks as **notifications** from payment providers to your application.

**Here's the flow:**
1. Customer clicks "Pay Now" on your website
2. They get redirected to Paystack/Stripe/etc. to complete payment
3. Customer pays successfully
4. **Payment provider sends a webhook** (a POST request) to your server saying "Hey! Payment completed!"
5. Your app receives the webhook and updates the order status, sends confirmation email, etc.

**Why webhooks?** Because sometimes customers close their browser before returning to your site. Webhooks ensure you always know when a payment succeeds, even if the customer never comes back.

---

## How Webhooks Work in This Package

### Step 1: Routes Are Automatically Set Up ✅

When you install this package, webhook routes are automatically created for all providers:

```
POST /payments/webhook/paystack     ← Paystack sends webhooks here
POST /payments/webhook/flutterwave  ← Flutterwave sends webhooks here
POST /payments/webhook/monnify      ← Monnify sends webhooks here
POST /payments/webhook/stripe       ← Stripe sends webhooks here
POST /payments/webhook/paypal       ← PayPal sends webhooks here
```

**You don't need to create these routes manually - they're already there!**

### Step 2: What Happens When a Webhook Arrives

Here's the complete flow when a payment provider sends a webhook:

```
1. Payment Provider → POST /payments/webhook/paystack
                    ↓
2. WebhookController receives the request
                    ↓
3. Controller verifies the webhook signature (security check)
   - Makes sure it's really from Paystack, not a hacker
                    ↓
4. Controller extracts the payment reference from the webhook data
                    ↓
5. Controller updates the payment record in your database
   - Changes status from 'pending' to 'success' or 'failed'
   - Saves payment method, timestamp, etc.
                    ↓
6. Controller fires Laravel events:
   - 'payments.webhook.paystack' (provider-specific)
   - 'payments.webhook' (generic, for all providers)
                    ↓
7. Your event listeners handle the webhook
   - Update order status
   - Send confirmation email
   - Process the order
   - Whatever you need!
                    ↓
8. Controller returns 200 OK to the provider
   - This tells the provider "Got it, thanks!"
```

### Step 3: Configure Webhook URLs in Provider Dashboards

You need to tell each payment provider where to send webhooks. Go to each provider's dashboard and add these URLs:

**Paystack Dashboard:**
- Go to: Settings → Webhooks
- Add URL: `https://yourdomain.com/payments/webhook/paystack`

**Flutterwave Dashboard:**
- Go to: Settings → Webhooks
- Add URL: `https://yourdomain.com/payments/webhook/flutterwave`

**Monnify Dashboard:**
- Go to: Settings → Webhooks
- Add URL: `https://yourdomain.com/payments/webhook/monnify`

**Stripe Dashboard:**
- Go to: Developers → Webhooks → Add endpoint
- Add URL: `https://yourdomain.com/payments/webhook/stripe`

**PayPal Dashboard:**
- Go to: Developer Dashboard → Webhooks
- Add URL: `https://yourdomain.com/payments/webhook/paypal`

**Important:** 
- Use `https://` (not `http://`) - most providers require HTTPS
- Replace `yourdomain.com` with your actual domain
- For local testing, use ngrok (see "Testing Locally" section below)

### Step 4: Configure Webhook Settings

In your `config/payments.php` file:

```php
'webhook' => [
    'verify_signature' => true,  // ALWAYS keep this true in production!
    'middleware' => ['api'],
    'path' => '/payments/webhook',
],
```

**What does `verify_signature` do?**
- It checks that the webhook is really from the payment provider
- Prevents hackers from sending fake webhooks
- **Never set this to false in production!**

---

## Handling Webhooks in Your Application

### Method 1: Using Event Listeners (Recommended)

This is the cleanest way to handle webhooks. You create listeners that react when webhooks arrive.

#### Step 1: Register Event Listeners

Open `app/Providers/EventServiceProvider.php` and add:

```php
protected $listen = [
    // Listen for Paystack webhooks specifically
    'payments.webhook.paystack' => [
        \App\Listeners\HandlePaystackWebhook::class,
    ],
    
    // Listen for Flutterwave webhooks
    'payments.webhook.flutterwave' => [
        \App\Listeners\HandleFlutterwaveWebhook::class,
    ],
    
    // Listen for ANY webhook (from any provider)
    'payments.webhook' => [
        \App\Listeners\HandleAnyWebhook::class,
    ],
];
```

#### Step 2: Create a Listener

Create a new file: `app/Listeners/HandlePaystackWebhook.php`

```php
<?php

namespace App\Listeners;

use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class HandlePaystackWebhook
{
    /**
     * Handle the webhook event.
     * 
     * This method is called automatically when Paystack sends a webhook.
     * The $payload contains all the webhook data from Paystack.
     */
    public function handle(array $payload): void
    {
        // Get the event type (charge.success, charge.failed, etc.)
        $event = $payload['event'] ?? null;
        
        // Handle different event types
        match($event) {
            'charge.success' => $this->handleSuccessfulPayment($payload['data']),
            'charge.failed' => $this->handleFailedPayment($payload['data']),
            default => Log::info("Unhandled Paystack webhook event: {$event}"),
        };
    }
    
    /**
     * What to do when a payment succeeds.
     */
    private function handleSuccessfulPayment(array $data): void
    {
        // Get the payment reference
        $reference = $data['reference'];
        
        // Find the order in your database
        $order = Order::where('payment_reference', $reference)->first();
        
        // Only process if order exists and hasn't been processed yet
        if ($order && $order->status === 'pending') {
            // Update order status
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            
            // Send confirmation email to customer
            Mail::to($order->customer_email)->send(new OrderConfirmation($order));
            
            // Dispatch a job to process the order (fulfillment, etc.)
            ProcessOrder::dispatch($order);
            
            Log::info("Order {$order->id} marked as paid via webhook", [
                'reference' => $reference,
            ]);
        }
    }
    
    /**
     * What to do when a payment fails.
     */
    private function handleFailedPayment(array $data): void
    {
        $reference = $data['reference'];
        
        $order = Order::where('payment_reference', $reference)->first();
        
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
     * Each provider puts it in a different place!
     */
    private function extractReference(string $provider, array $payload): ?string
    {
        return match($provider) {
            'paystack' => $payload['data']['reference'] ?? null,
            'flutterwave' => $payload['data']['tx_ref'] ?? null,
            'monnify' => $payload['paymentReference'] ?? null,
            'stripe' => $payload['data']['object']['metadata']['reference'] ?? null,
            'paypal' => $payload['resource']['custom_id'] ?? null,
            default => null,
        };
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
- `data.amount`: Amount in smallest currency unit (50000 = ₦500.00)

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

---

## Security Best Practices 🔒

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

Providers sometimes send the same webhook multiple times. Make sure you don't process the same payment twice:

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
1. ✅ Verify webhook secret is correct in `.env` file
2. ✅ Make sure `PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true` in config
3. ✅ Check provider documentation for correct header name
4. ✅ Ensure raw request body is used (not parsed JSON)
5. ✅ Check for extra whitespace in secret key (copy/paste carefully)

### Problem: Duplicate Webhooks Processed

**Symptoms:** Same order gets processed multiple times.

**Solutions:**
1. ✅ Add idempotency check (see "Security Best Practices" above)
2. ✅ Use database transactions
3. ✅ Check order status before processing
4. ✅ Use database unique constraints on payment_reference

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
        'amount' => 50000,
        'status' => 'pending',
        'payment_reference' => 'ORDER_' . time(),
    ]);
    
    // Redirect to payment
    return Payment::amount(50000)
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

## Webhook Events Reference

Different providers send different event types. Here's what to expect:

### Paystack Events
- `charge.success` - Payment succeeded
- `charge.failed` - Payment failed
- `transfer.success` - Transfer succeeded
- `transfer.failed` - Transfer failed

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

---

## Quick Start Checklist ✅

1. ✅ Install package and run migrations
2. ✅ Configure provider credentials in `.env`
3. ✅ Add webhook URLs to provider dashboards
4. ✅ Set `verify_signature => true` in config
5. ✅ Create event listeners in `EventServiceProvider`
6. ✅ Create listener classes to handle webhooks
7. ✅ Test with ngrok (local) or deploy to production
8. ✅ Monitor logs for webhook activity
9. ✅ Handle errors gracefully
10. ✅ Implement idempotency checks

---

**Need Help?** Check the main [README.md](../README.md) or open an issue on GitHub!
