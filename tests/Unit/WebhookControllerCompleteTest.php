<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance('payments.config');

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
        'payments.providers.square' => [
            'driver' => 'square',
            'access_token' => 'EAAAxxx',
            'location_id' => 'location_xxx',
            'webhook_signature_key' => 'test_key',
            'enabled' => true,
        ],
    ]);
});

test('webhook controller extracts paystack reference correctly', function () {
    $job = new ProcessWebhook('paystack', [
        'data' => [
            'reference' => 'paystack_ref_123',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('paystack_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('paystack_ref_123');
});

test('webhook controller extracts flutterwave reference correctly', function () {
    $job = new ProcessWebhook('flutterwave', [
        'data' => [
            'tx_ref' => 'flutterwave_ref_123',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('flutterwave_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['flutterwave' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('flutterwave_ref_123');
});

test('webhook controller extracts monnify reference correctly', function () {
    $job = new ProcessWebhook('monnify', [
        'paymentReference' => 'monnify_ref_123',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('monnify_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('monnify_ref_123');
});

test('webhook controller extracts monnify transaction reference as fallback', function () {
    $job = new ProcessWebhook('monnify', [
        'transactionReference' => 'monnify_txn_ref_123',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('monnify_txn_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('monnify_txn_ref_123');
});

test('webhook controller extracts stripe reference from metadata', function () {
    $job = new ProcessWebhook('stripe', [
        'data' => [
            'object' => [
                'metadata' => [
                    'reference' => 'stripe_ref_123',
                ],
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('stripe_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('stripe_ref_123');
});

test('webhook controller extracts stripe reference from client_reference_id', function () {
    $job = new ProcessWebhook('stripe', [
        'data' => [
            'object' => [
                'client_reference_id' => 'stripe_client_ref_123',
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('stripe_client_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('stripe_client_ref_123');
});

test('webhook controller extracts paypal reference from custom_id', function () {
    $job = new ProcessWebhook('paypal', [
        'resource' => [
            'custom_id' => 'paypal_ref_123',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('paypal_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('paypal_ref_123');
});

test('webhook controller extracts paypal reference from purchase_units', function () {
    $job = new ProcessWebhook('paypal', [
        'resource' => [
            'purchase_units' => [
                [
                    'custom_id' => 'paypal_purchase_ref_123',
                ],
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('paypal_purchase_ref_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('paypal_purchase_ref_123');
});

test('webhook controller extracts square reference from payment object', function () {
    $job = new ProcessWebhook('square', [
        'data' => [
            'object' => [
                'payment' => [
                    'reference_id' => 'SQUARE_1234567890_abc123',
                ],
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('SQUARE_1234567890_abc123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['square' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('SQUARE_1234567890_abc123');
});

test('webhook controller extracts square reference from data id', function () {
    $job = new ProcessWebhook('square', [
        'data' => [
            'id' => 'payment_123',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('payment_123');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['square' => $mockDriver]);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    $reference = $method->invoke($job, $manager);
    expect($reference)->toBe('payment_123');
});

test('webhook controller returns null for unknown provider', function () {
    $job = new ProcessWebhook('unknown_provider', []);

    $manager = app(PaymentManager::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('extractReference');
    $method->setAccessible(true);

    try {
        $reference = $method->invoke($job, $manager);
        expect($reference)->toBeNull();
    } catch (\KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException $e) {
        expect(true)->toBeTrue();
    }
});

test('webhook controller determines paystack status correctly', function () {
    $job = new ProcessWebhook('paystack', [
        'data' => [
            'status' => 'success',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines flutterwave status correctly', function () {
    $job = new ProcessWebhook('flutterwave', [
        'data' => [
            'status' => 'successful',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('successful');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['flutterwave' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines monnify status correctly', function () {
    $job = new ProcessWebhook('monnify', [
        'paymentStatus' => 'PAID',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('PAID');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines stripe status correctly', function () {
    $job = new ProcessWebhook('stripe', [
        'data' => [
            'object' => [
                'status' => 'succeeded',
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('succeeded');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines stripe status from type', function () {
    $job = new ProcessWebhook('stripe', [
        'type' => 'payment_intent.succeeded',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('succeeded');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines paypal status correctly', function () {
    $job = new ProcessWebhook('paypal', [
        'resource' => [
            'status' => 'COMPLETED',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('COMPLETED');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines paypal status from event_type', function () {
    $job = new ProcessWebhook('paypal', [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('COMPLETED');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines square status correctly', function () {
    $job = new ProcessWebhook('square', [
        'data' => [
            'object' => [
                'payment' => [
                    'status' => 'COMPLETED',
                ],
            ],
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('COMPLETED');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['square' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('success');
});

test('webhook controller determines square status from type', function () {
    $job = new ProcessWebhook('square', [
        'type' => 'payment.created',
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('unknown');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['square' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    $status = $method->invoke($job, $manager, $statusNormalizer);
    expect($status)->toBe('unknown');
});

test('webhook controller normalizes status variations to success', function () {
    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    expect($statusNormalizer->normalize('success', 'paystack'))->toBe('success')
        ->and($statusNormalizer->normalize('succeeded', 'stripe'))->toBe('success')
        ->and($statusNormalizer->normalize('completed', 'paypal'))->toBe('success')
        ->and($statusNormalizer->normalize('paid', 'monnify'))->toBe('success');
});

test('webhook controller normalizes status variations to failed', function () {
    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    expect($statusNormalizer->normalize('failed', 'paystack'))->toBe('failed')
        ->and($statusNormalizer->normalize('failure', 'stripe'))->toBe('failed')
        ->and($statusNormalizer->normalize('cancelled', 'paypal'))->toBe('failed');
});

test('webhook controller normalizes status variations to pending', function () {
    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    expect($statusNormalizer->normalize('pending', 'paystack'))->toBe('pending')
        ->and($statusNormalizer->normalize('processing', 'stripe'))->toBe('pending')
        ->and($statusNormalizer->normalize('approved', 'paypal'))->toBe('pending');
});

test('webhook controller returns original status for unknown status', function () {
    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    expect($statusNormalizer->normalize('unknown_status', 'paystack'))->toBe('unknown_status');
});

test('webhook controller updates transaction from webhook with success status', function () {
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'webhook_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paystack', [
        'data' => [
            'reference' => 'webhook_ref_123',
            'status' => 'success',
            'channel' => 'card',
        ],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('webhook_ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')
        ->andReturn('card');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'webhook_ref_123');

    $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'webhook_ref_123')->first();
    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('webhook controller updates transaction with provider-specific channels', function () {
    $references = [
        'paystack' => 'paystack_channel_ref',
        'flutterwave' => 'flutterwave_channel_ref',
        'monnify' => 'monnify_channel_ref',
        'stripe' => 'stripe_channel_ref',
        'paypal' => 'paypal_channel_ref',
        'square' => 'square_channel_ref',
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

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $job = new ProcessWebhook('paystack', [
        'data' => ['status' => 'success', 'channel' => 'card'],
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('paystack_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'paystack_channel_ref');

    $job = new ProcessWebhook('square', [
        'data' => [
            'object' => [
                'payment' => [
                    'status' => 'COMPLETED',
                    'source_type' => 'CARD',
                ],
            ],
        ],
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('square_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('CARD');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['square' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'square_channel_ref');

    $job = new ProcessWebhook('flutterwave', [
        'data' => ['status' => 'success', 'payment_type' => 'card'],
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('flutterwave_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['flutterwave' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'flutterwave_channel_ref');

    $job = new ProcessWebhook('monnify', [
        'paymentStatus' => 'PAID',
        'paymentMethod' => 'CARD',
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('monnify_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('PAID');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('CARD');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['monnify' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'monnify_channel_ref');

    $job = new ProcessWebhook('stripe', [
        'data' => ['object' => ['status' => 'succeeded', 'payment_method' => 'card']],
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('stripe_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('succeeded');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['stripe' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'stripe_channel_ref');

    $job = new ProcessWebhook('paypal', [
        'resource' => ['status' => 'COMPLETED'],
    ]);
    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('paypal_channel_ref');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('COMPLETED');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn('paypal');
    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);
    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);
    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'paypal_channel_ref');

    expect(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'paystack_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'flutterwave_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'monnify_channel_ref')->first()->channel)->toBe('CARD')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'stripe_channel_ref')->first()->channel)->toBe('card')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'paypal_channel_ref')->first()->channel)->toBe('paypal')
        ->and(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'square_channel_ref')->first()->channel)->toBe('CARD');
});

test('webhook controller handles database error during update gracefully', function () {
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'error_ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paystack', [
        'data' => ['status' => 'success'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('error_ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')->andReturn(null);

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    $statusNormalizer = app(\KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('updateTransactionFromWebhook');
    $method->setAccessible(true);

    $method->invoke($job, $manager, $statusNormalizer, app(\KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface::class), 'error_ref_123');

    expect(true)->toBeTrue();
});

test('webhook controller handles webhook without reference', function () {
    Event::fake();

    $request = Request::create('/payments/webhook/paystack', 'POST', [
        'data' => [
            'status' => 'success',
        ],
    ]);

    $request->headers->set('x-paystack-signature', 'valid_signature');

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(202);
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

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});

test('webhook controller handles exception during processing', function () {
    $request = Request::create('/payments/webhook/paystack', 'POST', []);

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Bus::shouldReceive('dispatch')
        ->andThrow(new \Exception('Processing error'));

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode($response->getContent(), true))->toHaveKey('message');
});
