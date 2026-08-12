<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makePayPalRefundDriver(array $responses): PayPalDriver
{
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $oauthResponse = new Response(200, [], json_encode([
        'access_token' => 'A21_test_token',
        'token_type' => 'Bearer',
        'expires_in' => 32400,
    ]));

    $mock = new MockHandler([$oauthResponse, ...$responses]);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

test('paypal refund succeeds against a capture id', function () {
    $driver = makePayPalRefundDriver([
        new Response(200, [], json_encode([
            'id' => '1JU08902781691411',
            'status' => 'COMPLETED',
            'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
            'note_to_payer' => 'requested by customer',
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'capture_abc', amount: 50.00, reason: 'requested by customer'));

    expect($result->refundReference)->toBe('1JU08902781691411')
        ->and($result->transactionReference)->toBe('capture_abc')
        ->and($result->status)->toBe('COMPLETED')
        ->and($result->amount)->toBe(50.0)
        ->and($result->provider)->toBe('paypal');
});

test('paypal refund throws exception on api error', function () {
    $driver = makePayPalRefundDriver([
        new Response(422, [], json_encode(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Capture not found'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid'));
})->throws(RefundException::class);

test('paypal fetchRefund resolves the capture id from the "up" link', function () {
    $driver = makePayPalRefundDriver([
        new Response(200, [], json_encode([
            'id' => 're_123',
            'status' => 'COMPLETED',
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.paypal.com/v2/payments/refunds/re_123'],
                ['rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/capture_abc'],
            ],
        ])),
    ]);

    $result = $driver->fetchRefund('re_123');

    expect($result->refundReference)->toBe('re_123')
        ->and($result->transactionReference)->toBe('capture_abc')
        ->and($result->status)->toBe('COMPLETED');
});
