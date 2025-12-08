<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

beforeEach(function () {
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    try {
        \Illuminate\Support\Facades\Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    \Illuminate\Support\Facades\Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference');
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 15, 2);
        $table->string('currency');
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
});

test('webhook controller determineStatus handles all provider status formats', function () {
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);
    
    // Test Paystack
    $paystackDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paystackDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $manager->shouldReceive('driver')->with('paystack')->andReturn($paystackDriver);
    $status = $method->invoke($controller, 'paystack', ['data' => ['status' => 'success']]);
    expect($status)->toBe('success');
    
    // Test Flutterwave
    $flutterwaveDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $flutterwaveDriver->shouldReceive('extractWebhookStatus')->andReturn('successful');
    $manager->shouldReceive('driver')->with('flutterwave')->andReturn($flutterwaveDriver);
    $status = $method->invoke($controller, 'flutterwave', ['data' => ['status' => 'successful']]);
    expect($status)->toBe('success');
    
    // Test Monnify
    $monnifyDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $monnifyDriver->shouldReceive('extractWebhookStatus')->andReturn('PAID');
    $manager->shouldReceive('driver')->with('monnify')->andReturn($monnifyDriver);
    $status = $method->invoke($controller, 'monnify', ['paymentStatus' => 'PAID']);
    expect($status)->toBe('success');
    
    // Test Stripe with object status
    $stripeDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $stripeDriver->shouldReceive('extractWebhookStatus')->andReturn('succeeded');
    $manager->shouldReceive('driver')->with('stripe')->andReturn($stripeDriver);
    $status = $method->invoke($controller, 'stripe', ['data' => ['object' => ['status' => 'succeeded']]]);
    expect($status)->toBe('success');
    
    // Test Stripe with type
    $stripeDriver2 = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $stripeDriver2->shouldReceive('extractWebhookStatus')->andReturn('payment_intent.succeeded');
    $manager->shouldReceive('driver')->with('stripe')->andReturn($stripeDriver2);
    $status = $method->invoke($controller, 'stripe', ['type' => 'payment_intent.succeeded']);
    expect($status)->toBe('success'); // Type is normalized to success
    
    // Test PayPal with resource status
    $paypalDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paypalDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $manager->shouldReceive('driver')->with('paypal')->andReturn($paypalDriver);
    $status = $method->invoke($controller, 'paypal', ['resource' => ['status' => 'COMPLETED']]);
    expect($status)->toBe('success');
    
    // Test PayPal with event_type
    $paypalDriver2 = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paypalDriver2->shouldReceive('extractWebhookStatus')->andReturn('PAYMENT.CAPTURE.COMPLETED');
    $manager->shouldReceive('driver')->with('paypal')->andReturn($paypalDriver2);
    $status = $method->invoke($controller, 'paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);
    // event_type is normalized to success
    expect($status)->toBe('success');
    
    // Test unknown provider
    $manager->shouldReceive('driver')->with('unknown')->andThrow(new \KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException('Unknown driver'));
    $status = $method->invoke($controller, 'unknown', []);
    expect($status)->toBe('unknown');
});

test('webhook controller updateTransactionFromWebhook updates with channel', function () {
    config(['payments.logging.enabled' => true]);
    
    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $paystackDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paystackDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $paystackDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $manager->shouldReceive('driver')->with('paystack')->andReturn($paystackDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    $method->invoke($controller, 'paystack', 'ref_123', [
        'data' => [
            'status' => 'success',
            'channel' => 'card',
        ],
    ]);
    
    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();
    
    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('webhook controller updateTransactionFromWebhook handles database error gracefully', function () {
    config(['payments.logging.enabled' => true]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $paystackDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paystackDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $paystackDriver->shouldReceive('extractWebhookChannel')->andReturn(null);
    $manager->shouldReceive('driver')->with('paystack')->andReturn($paystackDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    // Should not throw exception even if transaction doesn't exist
    $method->invoke($controller, 'paystack', 'nonexistent_ref', [
        'data' => ['status' => 'success'],
    ]);
    
    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});

test('webhook controller updateTransactionFromWebhook handles different provider channels', function () {
    config(['payments.logging.enabled' => true]);
    
    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'flutterwave',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $flutterwaveDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $flutterwaveDriver->shouldReceive('extractWebhookStatus')->andReturn('successful');
    $flutterwaveDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $manager->shouldReceive('driver')->with('flutterwave')->andReturn($flutterwaveDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    $method->invoke($controller, 'flutterwave', 'ref_123', [
        'data' => [
            'status' => 'successful',
            'payment_type' => 'card',
        ],
    ]);
    
    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();
    
    expect($transaction->channel)->toBe('card');
});

test('webhook controller updateTransactionFromWebhook handles monnify channel', function () {
    config(['payments.logging.enabled' => true]);
    
    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'monnify',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $monnifyDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $monnifyDriver->shouldReceive('extractWebhookStatus')->andReturn('PAID');
    $monnifyDriver->shouldReceive('extractWebhookChannel')->andReturn('CARD');
    $manager->shouldReceive('driver')->with('monnify')->andReturn($monnifyDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    $method->invoke($controller, 'monnify', 'ref_123', [
        'paymentStatus' => 'PAID',
        'paymentMethod' => 'CARD',
    ]);
    
    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();
    
    expect($transaction->channel)->toBe('CARD');
});

test('webhook controller updateTransactionFromWebhook handles stripe channel', function () {
    config(['payments.logging.enabled' => true]);
    
    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'stripe',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $stripeDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $stripeDriver->shouldReceive('extractWebhookStatus')->andReturn('succeeded');
    $stripeDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $manager->shouldReceive('driver')->with('stripe')->andReturn($stripeDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    $method->invoke($controller, 'stripe', 'ref_123', [
        'data' => [
            'object' => [
                'status' => 'succeeded',
                'payment_method' => 'card',
            ],
        ],
    ]);
    
    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();
    
    expect($transaction->channel)->toBe('card');
});

test('webhook controller updateTransactionFromWebhook handles paypal channel', function () {
    config(['payments.logging.enabled' => true]);
    
    PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paypal',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
    
    $normalizer = new StatusNormalizer();
    $manager = Mockery::mock(PaymentManager::class);
    $paypalDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $paypalDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $paypalDriver->shouldReceive('extractWebhookChannel')->andReturn('paypal');
    $manager->shouldReceive('driver')->with('paypal')->andReturn($paypalDriver);
    $controller = new WebhookController($manager, $normalizer);
    
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    
    $method->invoke($controller, 'paypal', 'ref_123', [
        'resource' => ['status' => 'COMPLETED'],
    ]);
    
    $transaction = PaymentTransaction::where('reference', 'ref_123')->first();
    
    expect($transaction->channel)->toBe('paypal');
});
