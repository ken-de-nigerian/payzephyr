# Getting Started with PayZephyr

A beginner-friendly guide to installing and using PayZephyr in your Laravel application.

---

## 🎯 Who This Guide Is For

- ✅ Complete beginners to payment processing
- ✅ Developers new to Laravel packages
- ✅ Anyone setting up PayZephyr for the first time
- ✅ Developers who want a step-by-step walkthrough

---

## 📋 Prerequisites

Before you start, make sure you have:

1. **PHP 8.2 or higher** installed
   ```bash
   php -v  # Should show 8.2.0 or higher
   ```

2. **Composer** installed
   ```bash
   composer --version  # Should show Composer version
   ```

3. **Laravel 10.x, 11.x, or 12.x** project
   ```bash
   php artisan --version  # Should show Laravel version
   ```

4. **A payment provider account** (at least one):
   - [Paystack](https://paystack.com) (Recommended for beginners - easiest to set up)
   - [Stripe](https://stripe.com)
   - [Flutterwave](https://flutterwave.com)
   - [Monnify](https://monnify.com)
   - [PayPal](https://paypal.com)

---

## 🚀 Step-by-Step Installation

### Step 1: Install the Package

Open your terminal in your Laravel project directory and run:

```bash
composer require kendenigerian/payzephyr
```

**What this does:** Downloads and installs the PayZephyr package into your Laravel project.

**Expected output:**
```
Using version ^1.0 for kendenigerian/payzephyr
...
Package manifest generated successfully.
```

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=payments-config
```

**What this does:** Creates a `config/payments.php` file in your project where you'll configure payment providers.

**Expected output:**
```
Copied File [/vendor/kendenigerian/payzephyr/config/payments.php] To [/config/payments.php]
Publishing complete.
```

### Step 3: Publish Migrations

```bash
php artisan vendor:publish --tag=payments-migrations
```

**What this does:** Copies the migration file to create the `payment_transactions` table.

**Expected output:**
```
Copied File [/vendor/.../migrations/..._create_payment_transactions_table.php] To [/database/migrations/...]
Publishing complete.
```

### Step 4: Run Migrations

```bash
php artisan migrate
```

**What this does:** Creates the `payment_transactions` table in your database to store payment records.

**Expected output:**
```
Running migrations...
2024_01_01_000000_create_payment_transactions_table .......... DONE
```

**⚠️ Troubleshooting:** If you get an error, make sure:
- Your database is configured in `.env`
- Database connection is working (`php artisan migrate:status`)

---

## ⚙️ Step-by-Step Configuration

### Step 1: Get Your Payment Provider Credentials

#### For Paystack (Recommended for Beginners):

1. Go to [https://paystack.com](https://paystack.com)
2. Sign up for an account
3. Go to **Settings** → **API Keys & Webhooks**
4. Copy your **Test Secret Key** (starts with `sk_test_`)
5. Copy your **Test Public Key** (starts with `pk_test_`)

**💡 Tip:** Use test keys first! Only use live keys when you're ready for production.

#### For Stripe:

1. Go to [https://stripe.com](https://stripe.com)
2. Sign up and go to **Developers** → **API Keys**
3. Copy your **Test Secret Key** (starts with `sk_test_`)
4. Copy your **Test Publishable Key** (starts with `pk_test_`)

### Step 2: Add Credentials to `.env` File

Open your `.env` file and add your credentials:

```env
# Default Payment Provider (use 'paystack' for beginners)
PAYMENTS_DEFAULT_PROVIDER=paystack

# Paystack Configuration (Required: secret_key, public_key)
PAYSTACK_SECRET_KEY=sk_test_your_secret_key_here
PAYSTACK_PUBLIC_KEY=pk_test_your_public_key_here
PAYSTACK_CALLBACK_URL=http://localhost:8000/payment/callback
PAYSTACK_ENABLED=true
```

**⚠️ Important:**
- Never commit your `.env` file to Git
- Replace `your_secret_key_here` with your actual key
- Use test keys during development

### Step 3: Clear Configuration Cache

```bash
php artisan config:clear
```

**What this does:** Makes sure Laravel reads your new `.env` values.

---

## 💳 Your First Payment (Complete Example)

Let's create a simple payment page from scratch!

### Step 1: Create a Route

Open `routes/web.php` and add:

```php
use Illuminate\Support\Facades\Route;
use KenDeNigerian\PayZephyr\Facades\Payment;

// Payment page
Route::get('/payment', function () {
    return view('payment');
})->name('payment.page');

// Process payment
Route::post('/payment/process', function () {
    $request = request();
    
    return Payment::amount(10000)  // ₦100.00 (in kobo)
        ->email($request->email)
        ->currency('NGN')
        ->description('Test Payment')
        ->callback(route('payment.callback'))
        ->redirect();
})->name('payment.process');

// Payment callback (after customer pays)
Route::get('/payment/callback', function () {
    $reference = request()->input('reference');
    
    if (!$reference) {
        return redirect()->route('payment.page')
            ->with('error', 'No payment reference found');
    }
    
    try {
        $verification = Payment::verify($reference);
        
        if ($verification->isSuccessful()) {
            return redirect()->route('payment.page')
                ->with('success', 'Payment successful! Amount: ₦' . $verification->amount);
        }
        
        return redirect()->route('payment.page')
            ->with('error', 'Payment failed or is pending');
            
    } catch (\Exception $e) {
        return redirect()->route('payment.page')
            ->with('error', 'Error verifying payment: ' . $e->getMessage());
    }
})->name('payment.callback');
```

### Step 2: Create a Payment View

Create `resources/views/payment.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <title>Payment Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <h1>💳 Test Payment</h1>
    
    @if(session('success'))
        <div class="alert alert-success">
            ✅ {{ session('success') }}
        </div>
    @endif
    
    @if(session('error'))
        <div class="alert alert-error">
            ❌ {{ session('error') }}
        </div>
    @endif
    
    <form method="POST" action="{{ route('payment.process') }}">
        @csrf
        
        <div class="form-group">
            <label for="email">Email Address *</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                value="{{ old('email', 'test@example.com') }}" 
                required
                placeholder="customer@example.com"
            >
        </div>
        
        <div class="form-group">
            <label>Amount</label>
            <input type="text" value="₦100.00" disabled>
            <small style="color: #666;">This is a test payment of ₦100.00</small>
        </div>
        
        <button type="submit">Pay Now</button>
    </form>
    
    <hr style="margin: 30px 0;">
    
    <h3>📚 What Happens Next?</h3>
    <ol>
        <li>Click "Pay Now" button</li>
        <li>You'll be redirected to Paystack's secure payment page</li>
        <li>Use test card: <code>4084084084084081</code> (any future expiry, any CVV)</li>
        <li>Complete the payment</li>
        <li>You'll be redirected back to see the result</li>
    </ol>
    
    <h3>🧪 Test Cards</h3>
    <p>For Paystack test mode, use:</p>
    <ul>
        <li><strong>Card:</strong> 4084084084084081</li>
        <li><strong>Expiry:</strong> Any future date (e.g., 12/25)</li>
        <li><strong>CVV:</strong> Any 3 digits (e.g., 123)</li>
        <li><strong>PIN:</strong> Any 4 digits (e.g., 0000)</li>
    </ul>
</body>
</html>
```

### Step 3: Test Your Payment

1. Start your Laravel server:
   ```bash
   php artisan serve
   ```

2. Open your browser:
   ```
   http://localhost:8000/payment
   ```

3. Enter an email and click "Pay Now"

4. You'll be redirected to Paystack's payment page

5. Use the test card details shown on the page

6. Complete the payment

7. You'll be redirected back to see the result!

**🎉 Congratulations!** You just processed your first payment!

---

## 🔍 Understanding the Code

Let's break down what each part does:

### The Payment Flow

```php
Payment::amount(10000)  // Amount in smallest currency unit (kobo for NGN)
    ->email($request->email)  // Customer email (required)
    ->currency('NGN')  // Currency code
    ->description('Test Payment')  // What the payment is for
    ->callback(route('payment.callback'))  // Where to return after payment
    ->redirect();  // Execute and redirect to payment page
```

**What happens:**
1. `amount(10000)` = ₦100.00 (10000 kobo)
2. `email()` = Customer's email address
3. `currency('NGN')` = Nigerian Naira
4. `callback()` = URL to return to after payment
5. `redirect()` = Sends user to payment provider's checkout page

### Verifying Payment

```php
$verification = Payment::verify($reference);

if ($verification->isSuccessful()) {
    // Payment succeeded!
}
```

**What happens:**
1. `verify($reference)` = Checks payment status with provider
2. `isSuccessful()` = Returns true if payment succeeded
3. You can then update your database, send emails, etc.

---

## 🐛 Common Issues & Solutions

### Issue 1: "Driver not found" Error

**Error:**
```
DriverNotFoundException: Payment driver [paystack] not found or disabled
```

**Solution:**
1. Check `.env` has `PAYSTACK_ENABLED=true`
2. Check `.env` has `PAYSTACK_SECRET_KEY=...`
3. Run `php artisan config:clear`
4. Check `config/payments.php` exists

### Issue 2: "Invalid credentials" Error

**Error:**
```
InvalidConfigurationException: Paystack secret key is required
```

**Solution:**
1. Make sure you copied the full key (including `sk_test_` prefix)
2. Check for extra spaces in `.env`
3. Don't use quotes around the key in `.env`
4. Run `php artisan config:clear`

### Issue 3: Payment Page Not Loading

**Error:** Blank page or redirect loop

**Solution:**
1. Check Laravel logs: `storage/logs/laravel.log`
2. Make sure you're using test keys (not live keys)
3. Check callback URL is accessible
4. Verify provider is enabled in config

### Issue 4: "Table not found" Error

**Error:**
```
SQLSTATE[42S02]: Base table or view not found: payment_transactions
```

**Solution:**
1. Run migrations: `php artisan migrate`
2. Check database connection in `.env`
3. Verify database exists

---

## 📚 Next Steps

Now that you've made your first payment:

1. **Read the Full Documentation**
   - [Complete Documentation](DOCUMENTATION.md)
   - [API Reference](../README.md#api-reference)

2. **Learn About Webhooks**
   - [Webhook Guide](webhooks.md)
   - Webhooks are more reliable than callbacks

3. **Explore Advanced Features**
   - Multiple providers with fallback
   - Custom metadata
   - Transaction logging

4. **Production Checklist**
   - Switch to live API keys
   - Enable webhook signature verification
   - Set up proper error handling
   - Review [Security Guide](SECURITY_AUDIT.md)

---

## 💡 Tips for Beginners

1. **Always test first** - Use test/sandbox keys before going live
2. **Start simple** - Get basic payments working before adding complexity
3. **Read error messages** - They usually tell you exactly what's wrong
4. **Check logs** - `storage/logs/laravel.log` has detailed error information
5. **Use one provider first** - Master Paystack before adding others

---

## 🆘 Need Help?

- 📧 **Email**: ken.de.nigerian@gmail.com
- 🐛 **GitHub Issues**: [Report a bug](https://github.com/ken-de-nigerian/payzephyr/issues)
- 💬 **Discussions**: [Ask questions](https://github.com/ken-de-nigerian/payzephyr/discussions)
- 📖 **Documentation**: [Full docs](DOCUMENTATION.md)

---

**Happy Coding! 🚀**
