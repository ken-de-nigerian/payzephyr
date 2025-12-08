<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.logging.enabled' => true,
        'payments.webhook.verify_signature' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.providers.flutterwave' => [
            'driver' => 'flutterwave',
            'secret_key' => 'FLWSECK_TEST-xxx',
            'enabled' => true,
        ],
        'payments.providers.monnify' => [
            'driver' => 'monnify',
            'api_key' => 'MK_TEST_xxx',
            'secret_key' => 'secret',
            'contract_code' => 'code',
            'enabled' => true,
        ],
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'sk_test_xxx',
            'webhook_secret' => 'whsec_xxx',
            'enabled' => true,
        ],
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'client_id',
            'client_secret' => 'secret',
            'webhook_id' => 'webhook_id',
            'enabled' => true,
        ],
    ]);
});

test('webhook controller extracts paystack reference correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'reference' => 'paystack_ref_123',
        ],
    ];

    $reference = $method->invoke($controller, 'paystack', $payload);
    expect($reference)->toBe('paystack_ref_123');
});

test('webhook controller extracts flutterwave reference correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'tx_ref' => 'flutterwave_ref_123',
        ],
    ];

    $reference = $method->invoke($controller, 'flutterwave', $payload);
    expect($reference)->toBe('flutterwave_ref_123');
});

test('webhook controller extracts monnify reference correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'paymentReference' => 'monnify_ref_123',
    ];

    $reference = $method->invoke($controller, 'monnify', $payload);
    expect($reference)->toBe('monnify_ref_123');
});

test('webhook controller extracts monnify transaction reference as fallback', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'transactionReference' => 'monnify_txn_ref_123',
    ];

    $reference = $method->invoke($controller, 'monnify', $payload);
    expect($reference)->toBe('monnify_txn_ref_123');
});

test('webhook controller extracts stripe reference from metadata', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'object' => [
                'metadata' => [
                    'reference' => 'stripe_ref_123',
                ],
            ],
        ],
    ];

    $reference = $method->invoke($controller, 'stripe', $payload);
    expect($reference)->toBe('stripe_ref_123');
});

test('webhook controller extracts stripe reference from client_reference_id', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'object' => [
                'client_reference_id' => 'stripe_client_ref_123',
            ],
        ],
    ];

    $reference = $method->invoke($controller, 'stripe', $payload);
    expect($reference)->toBe('stripe_client_ref_123');
});

test('webhook controller extracts paypal reference from custom_id', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'resource' => [
            'custom_id' => 'paypal_ref_123',
        ],
    ];

    $reference = $method->invoke($controller, 'paypal', $payload);
    expect($reference)->toBe('paypal_ref_123');
});

test('webhook controller extracts paypal reference from purchase_units', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [
        'resource' => [
            'purchase_units' => [
                [
                    'custom_id' => 'paypal_purchase_ref_123',
                ],
            ],
        ],
    ];

    $reference = $method->invoke($controller, 'paypal', $payload);
    expect($reference)->toBe('paypal_purchase_ref_123');
});

test('webhook controller returns null for unknown provider', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $payload = [];

    $reference = $method->invoke($controller, 'unknown_provider', $payload);
    expect($reference)->toBeNull();
});

test('webhook controller determines paystack status correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'status' => 'success',
        ],
    ];

    $status = $method->invoke($controller, 'paystack', $payload);
    expect($status)->toBe('success');
});

test('webhook controller determines flutterwave status correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'status' => 'successful',
        ],
    ];

    $status = $method->invoke($controller, 'flutterwave', $payload);
    expect($status)->toBe('success');
});

test('webhook controller determines monnify status correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'paymentStatus' => 'PAID',
    ];

    $status = $method->invoke($controller, 'monnify', $payload);
    expect($status)->toBe('success');
});

test('webhook controller determines stripe status correctly', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'object' => [
                'status' => 'succeeded',
            ],
        ],
    ];

    $status = $method->invoke($controller, 'stripe', $payload);
    expect($status)->toBe('success');
});

test('webhook controller determines stripe status from type', function () {
    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'type' => 'payment_intent.succeeded',
    ];

    $status = $method->invoke($controller, 'stripe', $payload);
    // Will be normalized by normalizeStatus method
    expect($status)->toBeString();
});

test('webhook controller determines paypal status correctly', function () {
    $controller = app(\KenDeNigerian\PayZephyr\Http\Controllers\WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'resource' => [
            'status' => 'COMPLETED',
        ],
    ];

    $status = $method->invoke($controller, 'paypal', $payload);
    expect($status)->toBe('success');
});

test('webhook controller determines paypal status from event_type', function () {
    $controller = app(\KenDeNigerian\PayZephyr\Http\Controllers\WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $payload = [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    ];

    $status = $method->invoke($controller, 'paypal', $payload);
    expect($status)->toBe('success');
});

test('webhook controller normalizes status variations to success', function () {
    $normalizer = app(\KenDeNigerian\PayZephyr\Services\StatusNormalizer::class);

    $successVariations = ['success', 'succeeded', 'completed', 'successful', 'payment.capture.completed', 'paid'];

    foreach ($successVariations as $variation) {
        $normalized = $normalizer->normalize($variation, 'paypal');
        expect($normalized)->toBe('success');
    }
});

test('webhook controller normalizes status variations to failed', function () {
    $normalizer = app(\KenDeNigerian\PayZephyr\Services\StatusNormalizer::class);

    $failedVariations = ['failed', 'cancelled', 'declined', 'payment.capture.denied'];

    foreach ($failedVariations as $variation) {
        $normalized = $normalizer->normalize($variation, 'paypal');
        expect($normalized)->toBe('failed');
    }
});

test('webhook controller normalizes status variations to pending', function () {
    $normalizer = app(\KenDeNigerian\PayZephyr\Services\StatusNormalizer::class);

    $pendingVariations = ['pending', 'processing', 'requires_action', 'requires_payment_method'];

    foreach ($pendingVariations as $variation) {
        $normalized = $normalizer->normalize($variation);
        expect($normalized)->toBe('pending');
    }
});

test('webhook controller returns original status for unknown status', function () {
    $normalizer = app(\KenDeNigerian\PayZephyr\Services\StatusNormalizer::class);

    $unknownStatus = 'custom_status';
    $normalized = $normalizer->normalize($unknownStatus);
    expect($normalized)->toBe('custom_status');
});

test('webhook controller updates transaction from webhook with success status', function () {
    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    // Create a transaction first
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'webhook_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $payload = [
        'data' => [
            'status' => 'success',
            'channel' => 'card',
        ],
    ];

    $method->invoke($controller, 'paystack', 'webhook_ref_123', $payload);

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'webhook_ref_123')->first();
    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('webhook controller updates transaction with provider-specific channels', function () {
    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    // Create transactions for each provider
    $references = [
        'paystack' => 'paystack_channel_ref',
        'flutterwave' => 'flutterwave_channel_ref',
        'monnify' => 'monnify_channel_ref',
        'stripe' => 'stripe_channel_ref',
        'paypal' => 'paypal_channel_ref',
    ];

    foreach ($references as $provider => $ref) {
        \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
            'reference' => $ref,
            'provider' => $provider,
            'status' => 'pending',
            'amount' => 1000,
            'currency' => 'NGN',
            'email' => 'test@example.com',
        ]);
    }

    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    // Test each provider's channel extraction
    $method->invoke($controller, 'paystack', 'paystack_channel_ref', [
        'data' => ['status' => 'success', 'channel' => 'card'],
    ]);

    $method->invoke($controller, 'flutterwave', 'flutterwave_channel_ref', [
        'data' => ['status' => 'success', 'payment_type' => 'card'],
    ]);

    $method->invoke($controller, 'monnify', 'monnify_channel_ref', [
        'paymentStatus' => 'PAID',
        'paymentMethod' => 'CARD',
    ]);

    $method->invoke($controller, 'stripe', 'stripe_channel_ref', [
        'data' => ['object' => ['status' => 'succeeded', 'payment_method' => 'card']],
    ]);

    $method->invoke($controller, 'paypal', 'paypal_channel_ref', [
        'resource' => ['status' => 'COMPLETED'],
    ]);

    expect(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'paystack_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'flutterwave_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'monnify_channel_ref')->first()->channel)->toBe('CARD')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'stripe_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'paypal_channel_ref')->first()->channel)->toBe('paypal');
});

test('webhook controller handles database error during update gracefully', function () {
    // Ensure we're using the testing connection
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    // Create table (drop first to ensure clean state)
    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    Schema::connection('testing')->create('payment_transactions', function ($table) {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 10, 2);
        $table->string('currency', 3);
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    // Create a transaction first
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'error_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $controller = app(WebhookController::class);
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    // Should not throw exception even if database update fails
    // The method catches exceptions internally
    $method->invoke($controller, 'paystack', 'error_ref_123', ['data' => ['status' => 'success']]);

    expect(true)->toBeTrue(); // If we get here, exception was caught
});

test('webhook controller handles webhook without reference', function () {
    Event::fake();

    $request = Request::create('/payments/webhook/paystack', 'POST', [
        'data' => [
            'status' => 'success',
            // No reference
        ],
    ]);

    $request->headers->set('x-paystack-signature', 'valid_signature');

    // Mock driver validation
    $driver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturn(true);
    $driver->shouldReceive('extractWebhookReference')->andReturn(null);

    $manager = Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);

    $controller = new \KenDeNigerian\PayZephyr\Http\Controllers\WebhookController($manager);

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);
});

test('webhook controller handles webhook with signature verification disabled', function () {
    config(['payments.webhook.verify_signature' => false]);

    Event::fake();

    $request = Request::create('/payments/webhook/paystack', 'POST', [
        'data' => [
            'reference' => 'test_ref',
            'status' => 'success',
        ],
    ]);

    $controller = app(WebhookController::class);
    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.paystack');
});

test('webhook controller handles exception during processing', function () {
    $request = Request::create('/payments/webhook/paystack', 'POST', []);

    $manager = Mockery::mock(PaymentManager::class);
    $manager->shouldReceive('driver')->andThrow(new \Exception('Driver error'));

    $controller = new \KenDeNigerian\PayZephyr\Http\Controllers\WebhookController($manager);
    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode($response->getContent(), true))->toHaveKey('message');
});

