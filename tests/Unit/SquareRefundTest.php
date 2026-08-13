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

test('square refund succeeds with an explicit amount, without looking up the original payment', function () {
    $history = [];
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
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'payment_abc', amount: 50.00, reason: 'requested by customer'));

    expect($result->refundReference)->toBe('refund_123')
        ->and($result->transactionReference)->toBe('payment_abc')
        ->and($result->status)->toBe('PENDING')
        ->and($result->amount)->toBe(50.0)
        ->and($result->provider)->toBe('square')
        ->and($history)->toHaveCount(1);
});

test('a full square refund (no explicit amount) fetches the original payment to determine amount_money', function () {
    // Regression: Square's CreateRefund API requires amount_money
    // unconditionally - there is no "omit amount for a full refund"
    // semantics the way Stripe/Paystack/PayPal/Mollie/Flutterwave work.
    // Omitting it used to send a payload Square would reject outright.
    $history = [];
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_full',
                'amount_money' => ['amount' => 12345, 'currency' => 'USD'],
            ],
        ])),
        new Response(200, [], json_encode([
            'refund' => [
                'id' => 'refund_full',
                'payment_id' => 'payment_full',
                'status' => 'PENDING',
                'amount_money' => ['amount' => 12345, 'currency' => 'USD'],
            ],
        ])),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'payment_full'));

    expect($history)->toHaveCount(2);

    $lookupRequest = $history[0]['request'];
    expect($lookupRequest->getMethod())->toBe('GET')
        ->and((string) $lookupRequest->getUri())->toContain('/v2/payments/payment_full');

    $refundRequest = $history[1]['request'];
    $sentBody = json_decode((string) $refundRequest->getBody(), true);
    expect($sentBody['amount_money'])->toBe(['amount' => 12345, 'currency' => 'USD']);

    expect($result->amount)->toBe(123.45);
});

test('a full square refund fails clearly when the original payment cannot be looked up', function () {
    $driver = makeSquareRefundDriver([
        new Response(200, [], json_encode(['errors' => [['detail' => 'Payment not found', 'code' => 'NOT_FOUND']]])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'payment_missing'));
})->throws(RefundException::class, "Cannot issue a full refund for Square payment [payment_missing]: the original payment's amount could not be determined");

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

    // An explicit amount is passed so this exercises the POST /v2/refunds
    // error path directly, without first going through the full-refund
    // original-payment lookup (covered separately above).
    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid', amount: 10.0));
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
