<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeFlutterwaveRefundDriver(array $responses, ?array &$history = null): FlutterwaveDriver
{
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);

    if ($history !== null) {
        $handlerStack->push(Middleware::history($history));
    }

    $driver->setClient(new Client(['handler' => $handlerStack]));

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

test('flutterwave fetchRefund queries the refund-by-id endpoint, not the transaction-scoped refund list', function () {
    // Regression: GET /v3/transactions/{id}/refunds is a *transaction*-id
    // keyed endpoint that lists every refund against that transaction. The
    // parameter fetchRefund() receives is a *refund* reference (a different
    // ID sequence per Flutterwave's API) - calling the transaction-scoped
    // endpoint with a refund ID could silently return refunds for an
    // unrelated transaction that happens to share that numeric ID. The
    // correct endpoint for a single refund by its own ID is
    // GET /v3/refunds/{id}.
    $history = [];
    $driver = makeFlutterwaveRefundDriver([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'id' => 987,
                'transaction_id' => 123456,
                'amount_refunded' => 5000,
                'status' => 'completed',
                'currency' => 'NGN',
            ],
        ])),
    ], $history);

    $driver->fetchRefund('987');

    expect($history)->toHaveCount(1);
    $uri = (string) $history[0]['request']->getUri();

    expect($uri)->toContain('refunds/987')
        ->and($uri)->not->toContain('transactions/987/refunds');
});
