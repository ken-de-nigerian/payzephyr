<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeSquareRefundDriver(array $responses, ?array &$history = null): SquareDriver
{
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD', 'CAD'],
    ]);
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);

    if ($history !== null) {
        $handlerStack->push(Middleware::history($history));
    }

    $driver->setClient(new Client(['handler' => $handlerStack]));

    return $driver;
}

test('square refund succeeds with valid response', function () {
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode([
            'refund' => [
                'id' => 'refund_123',
                'payment_id' => 'payment_abc',
                'status' => 'PENDING',
                'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
                'reason' => 'requested by customer',
            ],
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'payment_abc', reason: 'requested by customer'));

    expect($result->refundReference)->toBe('refund_123')
        ->and($result->transactionReference)->toBe('payment_abc')
        ->and($result->status)->toBe('PENDING')
        ->and($result->amount)->toBe(50.0)
        ->and($result->provider)->toBe('square');
});

test('square refund sends the explicit request currency, not just the first configured currency', function () {
    // The merchant is configured for USD *and* CAD (multi-currency), and
    // the original charge being refunded was in CAD - if the driver fell
    // back to config['currencies'][0] ('USD') instead of the request's
    // explicit currency, this refund would silently be submitted in the
    // wrong currency.
    $history = [];
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode([
            'refund' => [
                'id' => 'refund_cad',
                'payment_id' => 'payment_cad',
                'status' => 'PENDING',
                'amount_money' => ['amount' => 5000, 'currency' => 'CAD'],
            ],
        ])),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'payment_cad', amount: 50.00, currency: 'CAD'));

    $sentBody = json_decode((string) $history[0]['request']->getBody(), true);

    expect($sentBody['amount_money']['currency'])->toBe('CAD')
        ->and($result->currency)->toBe('CAD');
});

test('square refund throws exception on api error', function () {
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode(['errors' => [['detail' => 'Payment not found', 'code' => 'NOT_FOUND']]])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid'));
})->throws(RefundException::class, 'Payment not found');

test('square fetchRefund succeeds with valid response', function () {
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode([
            'refund' => [
                'id' => 'refund_123',
                'payment_id' => 'payment_abc',
                'status' => 'COMPLETED',
                'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            ],
        ])),
    ]);

    $result = $driver->fetchRefund('refund_123');

    expect($result->refundReference)->toBe('refund_123')
        ->and($result->transactionReference)->toBe('payment_abc')
        ->and($result->status)->toBe('COMPLETED');
});
