<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeFlutterwaveRefundDriver(array $responses): FlutterwaveDriver
{
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('flutterwave refund succeeds with valid response', function () {
    $driver = makeFlutterwaveRefundDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'message' => 'Refund processed',
            'data' => [
                'id' => 987,
                'amount_refunded' => 5000,
                'status' => 'completed',
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: '123456'));

    expect($result->refundReference)->toBe('987')
        ->and($result->transactionReference)->toBe('123456')
        ->and($result->status)->toBe('completed')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->provider)->toBe('flutterwave');
});

test('flutterwave refund throws exception on api error', function () {
    $driver = makeFlutterwaveRefundDriver([
        new Response(200, [], json_encode(['status' => 'error', 'message' => 'Transaction not found'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid'));
})->throws(RefundException::class, 'Transaction not found');

test('flutterwave fetchRefund succeeds with valid response', function () {
    $driver = makeFlutterwaveRefundDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [[
                'id' => 987,
                'transaction_id' => 123456,
                'amount_refunded' => 5000,
                'status' => 'completed',
                'currency' => 'NGN',
            ]],
        ])),
    ]);

    $result = $driver->fetchRefund('987');

    expect($result->refundReference)->toBe('987')
        ->and($result->transactionReference)->toBe('123456')
        ->and($result->status)->toBe('completed');
});
