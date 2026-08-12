<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeMollieRefundDriver(array $responses): MollieDriver
{
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $mock = new MockHandler($responses);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('mollie refund succeeds with valid response', function () {
    $driver = makeMollieRefundDriver([
        new Response(200, [], json_encode([
            'id' => 're_123',
            'status' => 'pending',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'description' => 'requested by customer',
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'tr_abc', amount: 10.00, reason: 'requested by customer'));

    expect($result->refundReference)->toBe('tr_abc:re_123')
        ->and($result->transactionReference)->toBe('tr_abc')
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(10.0)
        ->and($result->provider)->toBe('mollie');
});

test('mollie refund throws exception on api error', function () {
    $driver = makeMollieRefundDriver([
        new Response(422, [], json_encode(['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'Refund amount exceeds remaining balance'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'tr_abc', amount: 999.00));
})->throws(RefundException::class);

test('mollie fetchRefund succeeds with a composite reference', function () {
    $driver = makeMollieRefundDriver([
        new Response(200, [], json_encode([
            'id' => 're_123',
            'status' => 'refunded',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
        ])),
    ]);

    $result = $driver->fetchRefund('tr_abc:re_123');

    expect($result->refundReference)->toBe('tr_abc:re_123')
        ->and($result->transactionReference)->toBe('tr_abc')
        ->and($result->status)->toBe('refunded');
});

test('mollie fetchRefund rejects a malformed reference', function () {
    $driver = makeMollieRefundDriver([]);

    $driver->fetchRefund('not-a-composite-reference');
})->throws(RefundException::class, 'Invalid Mollie refund reference');
