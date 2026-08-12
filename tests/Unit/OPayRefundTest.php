<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeOPayRefundDriver(array $responses): OPayDriver
{
    $config = [
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'secret_key' => 'SECRET_KEY_123',
        'base_url' => 'https://liveapi.opaycheckout.com',
        'currencies' => ['NGN'],
    ];

    $mock = new MockHandler($responses);
    $driver = new OPayDriver($config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('opay refund succeeds with valid response', function () {
    $driver = makeOPayRefundDriver([
        new Response(200, [], json_encode([
            'code' => '00000',
            'message' => 'SUCCESSFUL',
            'data' => [
                'refundNo' => 'ref_no_123',
                'orderNo' => 'opay_order_123',
                'status' => 'PENDING',
                'amount' => ['total' => 500000, 'currency' => 'NGN'],
            ],
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'opay_order_123', amount: 5000.00));

    expect($result->refundReference)->toBe('ref_no_123')
        ->and($result->transactionReference)->toBe('opay_order_123')
        ->and($result->status)->toBe('PENDING')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->provider)->toBe('opay');
});

test('opay refund throws exception on api error', function () {
    $driver = makeOPayRefundDriver([
        new Response(200, [], json_encode(['code' => '90001', 'message' => 'Order not found'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid'));
})->throws(RefundException::class, 'Order not found');

test('opay refund throws when secret key missing', function () {
    $driver = new OPayDriver([
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));

    $driver->refund(new RefundRequestDTO(transactionReference: 'opay_order_123'));
})->throws(RefundException::class);

test('opay fetchRefund succeeds with valid response', function () {
    $driver = makeOPayRefundDriver([
        new Response(200, [], json_encode([
            'code' => '00000',
            'data' => [
                'refundNo' => 'ref_no_123',
                'orderNo' => 'opay_order_123',
                'status' => 'SUCCESS',
                'amount' => ['total' => 500000, 'currency' => 'NGN'],
            ],
        ])),
    ]);

    $result = $driver->fetchRefund('ref_no_123');

    expect($result->refundReference)->toBe('ref_no_123')
        ->and($result->transactionReference)->toBe('opay_order_123')
        ->and($result->status)->toBe('SUCCESS');
});
