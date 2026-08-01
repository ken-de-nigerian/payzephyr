# Your First Payment

Let's build something real: a checkout flow for a small online coffee shop. By the end of this chapter, a customer will be able to click "Buy," pay through Paystack, and land back on your site with an order marked as paid.

If you haven't installed PayZephyr yet, do that first: see [Installation](installation.md). This chapter assumes `config/payments.php` exists and Paystack (or whichever provider you chose) has valid test credentials in `.env`.

## What we're building

The coffee shop sells bags of coffee. A customer picks a bag, clicks "Buy," gets sent to Paystack's hosted checkout page, pays, and gets redirected back. We'll:

1. Create an `Order` to represent what's being bought.
2. Start a payment for that order.
3. Handle the customer coming back from the payment page.
4. Confirm (correctly, not just optimistically) that the payment actually succeeded.

## Step 1: A place to record orders

You don't strictly need your own `orders` table to use PayZephyr (it keeps its own `payment_transactions` log automatically), but in a real app you'll want your own model to represent "this customer bought this coffee," separate from PayZephyr's generic transaction log. Let's create one:

```bash
php artisan make:model Order -m
```

```php
// database/migrations/..._create_orders_table.php
public function up(): void
{
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('payment_reference')->unique();
        $table->string('customer_email');
        $table->string('coffee_name');
        $table->decimal('amount', 10, 2);
        $table->string('status')->default('pending'); // pending, paid, failed
        $table->timestamps();
    });
});
```

```bash
php artisan migrate
```

`payment_reference` is the important column here: it's what links your `Order` back to the payment PayZephyr processed.

## Step 2: Starting the payment

When the customer clicks "Buy," we need to: create an `Order` row (as `pending`), then hand off to PayZephyr to redirect them to the payment page.

```php
// app/Http/Controllers/CheckoutController.php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Facades\Payment;

class CheckoutController extends Controller
{
    public function buy(Request $request)
    {
        $order = Order::create([
            'payment_reference' => 'ORDER_'.uniqid(),
            'customer_email' => $request->input('email'),
            'coffee_name' => 'Ethiopian Yirgacheffe',
            'amount' => 15.00,
            'status' => 'pending',
        ]);

        return Payment::amount($order->amount)
            ->email($order->customer_email)
            ->reference($order->payment_reference)
            ->description('Coffee order: '.$order->coffee_name)
            ->callback(route('checkout.callback'))
            ->metadata(['order_id' => $order->id])
            ->redirect();
    }
}
```

Let's go through what each line is doing, because none of it is arbitrary:

- **`Payment::amount($order->amount)`**: starts building the payment request. Amounts are always in your currency's *major* unit (dollars, not cents; naira, not kobo); PayZephyr converts to whatever minor-unit format each provider's API actually expects internally, so you never have to think about it.
- **`->email(...)`**: every provider PayZephyr supports requires a customer email to initiate a charge; it's how the provider identifies the payer and where their own receipt goes.
- **`->reference($order->payment_reference)`**: this is the crucial link. If you don't supply your own reference, PayZephyr generates one for you, but generating your own means you can tie *your* `Order` row directly to the payment without an extra database lookup later.
- **`->description(...)`**: shows up on the provider's payment page and the customer's statement. Optional, but customers trust a payment more when it clearly says what they're buying.
- **`->callback(route('checkout.callback'))`**: where the provider sends the customer back to *after* they pay (or cancel). We'll build this route next.
- **`->metadata(['order_id' => $order->id])`**: arbitrary data you want echoed back to you later, attached to this specific payment. Handy for anything you don't want to encode into the reference string itself.
- **`->redirect()`**: the final call in the chain. Everything above it just builds up the request; `redirect()` is what actually calls the provider's API and returns a `RedirectResponse` sending the customer to the provider's hosted payment page.

Wire up the route:

```php
// routes/web.php
use App\Http\Controllers\CheckoutController;

Route::post('/checkout', [CheckoutController::class, 'buy'])->name('checkout.buy');
Route::get('/checkout/callback', [CheckoutController::class, 'callback'])->name('checkout.callback');
```

## Step 3: Handling the return trip

After paying (or cancelling), the customer's browser is redirected to your callback URL, with the payment reference attached as a query parameter. **Don't mark the order as paid here**: a redirect happening is not proof a payment succeeded (the customer could cancel and still land on this page, or the URL could be replayed). What you do here is *look up what actually happened*:

```php
// app/Http/Controllers/CheckoutController.php (continued)

use App\Models\Order;
use KenDeNigerian\PayZephyr\Facades\Payment;

public function callback(Request $request)
{
    $reference = $request->query('reference');
    $order = Order::where('payment_reference', $reference)->firstOrFail();

    $verification = Payment::verify($reference);

    if ($verification->isSuccessful()) {
        $order->update(['status' => 'paid']);
        return view('checkout.success', ['order' => $order]);
    }

    $order->update(['status' => 'failed']);
    return view('checkout.failed', ['order' => $order]);
}
```

`Payment::verify($reference)` makes a real API call to the provider and asks, authoritatively, "did this payment succeed?" That's the only source of truth PayZephyr trusts: never the fact that a redirect happened, and never anything a query parameter *claims* about the payment's status (a URL is trivially editable by anyone). The full reasoning for this (and what specifically can go wrong if you skip it) is in [Payment Verification](verification.md).

## Step 4: Try it

With Paystack test keys in `.env`, submit a POST to `/checkout` (a simple form works fine for testing):

```html
<form action="{{ route('checkout.buy') }}" method="POST">
    @csrf
    <input type="email" name="email" placeholder="you@example.com" required>
    <button type="submit">Buy Ethiopian Yirgacheffe, $15.00</button>
</form>
```

Submit it, and you should land on Paystack's test checkout page. Paystack's [test card numbers](https://paystack.com/docs/payments/test-payments/) let you simulate a successful or failed payment without moving real money. Complete the test payment, and you'll be redirected back to `/checkout/callback`, where your `Order` gets updated based on what `Payment::verify()` actually found.

## What this tutorial is missing (on purpose)

This flow works, but it has one real gap: **it only updates the order if the customer's browser makes it all the way back to your callback URL.** If their connection drops, or they close the tab right after paying, your `Order` stays stuck at `pending` forever, even though the customer *did* pay.

That's exactly what webhooks solve: a provider will tell your app about a successful payment independently of whatever the customer's browser does. A production checkout flow uses both: the callback for showing the customer an immediate "thanks!" page, and a webhook for guaranteeing your database is eventually correct no matter what happens to their browser. See [Understanding Payment Flow](payment-flow.md) for the full picture, then [Webhooks](webhooks.md) to add that missing piece.

## Next steps

- [Understanding Payment Flow](payment-flow.md): see the whole picture, including webhooks, before wiring them up
- [Payment Verification](verification.md): a closer look at `verify()`, including the mistakes people commonly make with it
- [Webhooks](webhooks.md): make this flow reliable even when the customer's browser doesn't cooperate
