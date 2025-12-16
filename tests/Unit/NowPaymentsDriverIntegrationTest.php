<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\NowPaymentsDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createNowPaymentsDriverWithMock(array $responses): NowPaymentsDriver
{
    $config = [
        'api_key' => 'TEST_API_KEY_123',
        'ipn_secret' => 'TEST_IPN_SECRET_123',
        'base_url' => 'https://api.nowpayments.io',
        'currencies' => ['USD', 'NGN', 'EUR', 'BTC', 'ETH'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new NowPaymentsDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('nowpayments charge succeeds with valid response', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'order_description' => 'Payment for services',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'ipn_callback_url' => 'https://example.com/callback',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', 'NOW_1234567890_abc123def456', 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('NOW_1234567890_abc123def456')
        ->and($response->authorizationUrl)->toBe('https://nowpayments.io/payment/?iid=4522625843')
        ->and($response->accessCode)->toBe('4522625843')
        ->and($response->status)->toBe('pending');
});

test('nowpayments charge generates reference when not provided', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '50.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(50.00, 'USD', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('NOW_')
        ->and($response->authorizationUrl)->toBe('https://nowpayments.io/payment/?iid=4522625843');
});

test('nowpayments charge handles metadata', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback', ['order_id' => 12345]);
    $response = $driver->charge($request);

    expect($response->metadata)->toHaveKey('order_id', 12345)
        ->and($response->metadata)->toHaveKey('invoice_id')
        ->and($response->metadata)->toHaveKey('payment_status');
});

test('nowpayments charge throws exception when id is missing', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'message' => 'Invalid request',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback'));
})->throws(ChargeException::class);

test('nowpayments charge handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/v1/invoice')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new NowPaymentsDriver([
        'api_key' => 'test',
        'ipn_secret' => 'test_secret',
        'currencies' => ['USD'],
    ]);
    $driver->setClient($client);

    $driver->charge(new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback'));
})->throws(ChargeException::class);

test('nowpayments verify returns success', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'payment_id' => '5745459419',
            'payment_status' => 'finished',
            'pay_address' => '3EZ2uTdVDAMFXTfc6uLDDKR6o8qKBZXVkj',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'pay_amount' => 0.001,
            'pay_currency' => 'btc',
            'order_id' => 'NOW_1234567890_abc123def456',
            'order_description' => 'Payment for services',
            'purchase_id' => '5837122679',
            'created_at' => '2020-12-22T15:00:22.742Z',
            'updated_at' => '2020-12-22T15:30:22.742Z',
            'network' => 'btc',
        ])),
    ]);

    $result = $driver->verify('NOW_1234567890_abc123def456');

    expect($result->status)->toBe('finished')
        ->and($result->amount)->toBe(100.0)
        ->and($result->currency)->toBe('USD')
        ->and($result->reference)->toBe('NOW_1234567890_abc123def456')
        ->and($result->channel)->toBe('btc');
});

test('nowpayments verify returns waiting status', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'waiting',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('waiting')
        ->and($result->isPending())->toBeTrue();
});

test('nowpayments verify returns failed status', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'payment_id' => '5745459419',
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'failed',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:00:22.742Z',
            'updated_at' => '2020-12-22T15:30:22.742Z',
        ])),
    ]);

    $result = $driver->verify('NOW_1234567890_abc123def456');

    expect($result->status)->toBe('failed')
        ->and($result->isFailed())->toBeTrue();
});

test('nowpayments verify throws exception when payment_id is missing', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'message' => 'Payment not found',
        ])),
    ]);

    $driver->verify('invalid_id');
})->throws(VerificationException::class);

test('nowpayments verify handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('GET', '/v1/payment/12345678')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new NowPaymentsDriver([
        'api_key' => 'test',
        'ipn_secret' => 'test_secret',
        'currencies' => ['USD'],
    ]);
    $driver->setClient($client);

    $driver->verify('12345678');
})->throws(VerificationException::class);

test('nowpayments verify extracts metadata correctly', function () {
    $driver = createNowPaymentsDriverWithMock([
        new Response(200, [], json_encode([
            'payment_id' => '5745459419',
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:00:22.742Z',
            'updated_at' => '2020-12-22T15:30:22.742Z',
            'pay_currency' => 'btc',
            'pay_amount' => 0.001,
            'pay_address' => '3EZ2uTdVDAMFXTfc6uLDDKR6o8qKBZXVkj',
            'purchase_id' => '5837122679',
            'network' => 'btc',
            'amount_received' => 0.001,
        ])),
    ]);

    $result = $driver->verify('NOW_1234567890_abc123def456');

    expect($result->metadata)->toHaveKey('payment_id', '5745459419')
        ->and($result->metadata)->toHaveKey('pay_currency', 'btc')
        ->and($result->metadata)->toHaveKey('pay_amount', 0.001)
        ->and($result->metadata)->toHaveKey('pay_address')
        ->and($result->metadata)->toHaveKey('purchase_id', '5837122679')
        ->and($result->channel)->toBe('btc');
});
