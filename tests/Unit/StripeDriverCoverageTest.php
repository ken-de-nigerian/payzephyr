<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;

test('stripe driver mapFromCheckoutSession handles paid status', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'paid',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
        'payment_intent' => null,
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');

    $result = $method->invoke($driver, $session);

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->paidAt)->not->toBeNull();
});

test('stripe driver mapFromCheckoutSession handles unpaid status', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'unpaid',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');

    $result = $method->invoke($driver, $session);

    expect($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull();
});

test('stripe driver mapFromCheckoutSession handles failed status', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'failed',
        'amount_total' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
        'payment_intent' => null,
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');

    $result = $method->invoke($driver, $session);

    expect($result->status)->toBe('failed');
});

test('stripe driver mapFromCheckoutSession uses payment intent amount when amount_total missing', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $pi = (object) ['amount' => 20000];
    $session = (object) [
        'id' => 'cs_test_123',
        'client_reference_id' => 'ref_123',
        'payment_status' => 'paid',
        'amount_total' => null,
        'payment_intent' => $pi,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'test@example.com',
        'metadata' => [],
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromCheckoutSession');

    $result = $method->invoke($driver, $session);

    expect($result->amount)->toBe(200.0);
});

test('stripe driver mapFromPaymentIntent handles succeeded status', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
        'metadata' => ['reference' => 'ref_123'],
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');

    $result = $method->invoke($driver, $intent);

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->paidAt)->not->toBeNull();
});

test('stripe driver mapFromPaymentIntent uses id when metadata reference missing', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $intent = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'receipt_email' => 'test@example.com',
        'metadata' => [],
    ];

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPaymentIntent');

    $result = $method->invoke($driver, $intent);

    expect($result->reference)->toBe('pi_test_123');
});

test('stripe driver healthCheck returns true for authentication exception', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow(new AuthenticationException('Invalid API key'));

    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);

    expect($driver->healthCheck())->toBeTrue();
});

test('stripe driver healthCheck returns true for 4xx errors', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $exception = Mockery::mock(ApiErrorException::class);
    $exception->shouldReceive('getHttpStatus')->andReturn(404);

    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow($exception);

    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);

    expect($driver->healthCheck())->toBeTrue();
});

test('stripe driver healthCheck returns false for 5xx errors', function (): void {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $stripeMock = Mockery::mock();
    $balanceMock = Mockery::mock();
    $exception = Mockery::mock(ApiErrorException::class);
    $exception->shouldReceive('getHttpStatus')->andReturn(500);
    $exception->shouldReceive('getMessage')->andReturn('Server error');

    $balanceMock->shouldReceive('retrieve')
        ->once()
        ->andThrow($exception);

    $stripeMock->balance = $balanceMock;
    $driver->setStripeClient($stripeMock);

    expect($driver->healthCheck())->toBeFalse();
});

test('stripe refuses a checkout session that came back without a url', function (): void {
    // Only a hosted session has somewhere to send the customer. Passing a
    // null on as the authorization url surfaced as a TypeError from the DTO.
    $driver = new StripeDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['USD']]);

    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')->once()->andReturn((object) ['id' => 'cs_test_no_url', 'url' => null]);
    $stripe = Mockery::mock();
    $stripe->checkout = (object) ['sessions' => $sessions];
    $driver->setStripeClient($stripe);

    expect(fn (): ChargeResponseDTO => $driver->charge(ChargeRequestDTO::fromArray([
        'amount' => 10, 'currency' => 'USD', 'email' => 'a@b.test',
        'reference' => 'STRIPE_NO_URL', 'callback_url' => 'https://shop.example.com/cb',
    ])))->toThrow(ChargeException::class, 'without a URL to redirect the customer to');
});

test('stripe sends metadata as strings, with nested values as json', function (): void {
    // Stripe metadata is string to string. A nested value sent as it is
    // becomes nested form fields, and Stripe rejects the whole charge.
    $driver = new StripeDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['USD']]);

    $sent = null;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')->once()->andReturnUsing(function (array $params) use (&$sent) {
        $sent = $params['metadata'];

        return (object) ['id' => 'cs_test_meta', 'url' => 'https://checkout.stripe.com/pay/cs_test_meta'];
    });
    $stripe = Mockery::mock();
    $stripe->checkout = (object) ['sessions' => $sessions];
    $driver->setStripeClient($stripe);

    $driver->charge(ChargeRequestDTO::fromArray([
        'amount' => 10, 'currency' => 'USD', 'email' => 'a@b.test',
        'reference' => 'STRIPE_META', 'callback_url' => 'https://shop.example.com/cb',
        'metadata' => ['order_id' => 991, 'gift' => true, 'note' => null, 'cart' => ['sku' => 'A1', 'qty' => 2], 'ratio' => 1.5],
    ]));

    expect($sent)->toBe([
        'order_id' => '991',
        'gift' => 'true',
        'note' => '',
        'cart' => '{"sku":"A1","qty":2}',
        'ratio' => '1.5',
        'reference' => 'STRIPE_META',
    ]);
});
