<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeMonnifyRefundDriver(array $responses, ?array &$history = null): MonnifyDriver
{
    $config = [
        'api_key' => 'MK_TEST_xxx',
        'secret_key' => 'SK_TEST_xxx',
        'contract_code' => 'CONTRACT123',
        'base_url' => 'https://sandbox.monnify.com',
        'currencies' => ['NGN'],
    ];

    $oauthResponse = new Response(200, [], json_encode([
        'requestSuccessful' => true,
        'responseBody' => ['accessToken' => 'bearer_token_xyz', 'expiresIn' => 3600],
    ]));

    $mock = new MockHandler([$oauthResponse, ...$responses]);
    $handlerStack = HandlerStack::create($mock);

    if ($history !== null) {
        $handlerStack->push(Middleware::history($history));
    }

    $driver = new MonnifyDriver($config);
    $driver->setClient(new Client(['handler' => $handlerStack]));

    return $driver;
}

test('monnify refund succeeds with valid response', function () {
    $driver = makeMonnifyRefundDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'refundReference' => 'REFUND_001',
                'transactionReference' => 'mn_ref_123',
                'refundStatus' => 'PENDING',
                'refundAmount' => 5000.0,
                'currencyCode' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'mn_ref_123', amount: 5000.00));

    expect($result->refundReference)->toBe('REFUND_001')
        ->and($result->transactionReference)->toBe('mn_ref_123')
        ->and($result->status)->toBe('PENDING')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->provider)->toBe('monnify');
});

test('monnify refund throws exception on api error', function () {
    $driver = makeMonnifyRefundDriver([
        new Response(200, [], json_encode(['requestSuccessful' => false, 'responseMessage' => 'Transaction not found'])),
    ]);

    // An explicit amount is passed so this exercises the initiate-refund
    // error path directly, without first going through the full-refund
    // original-transaction lookup (covered separately below).
    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid', amount: 10.0));
})->throws(RefundException::class, 'Transaction not found');

test('a full monnify refund (no explicit amount) looks up the original transaction to determine refundAmount', function () {
    // Regression: Monnify's initiate-refund API documents refundAmount as
    // required with no "omit for a full refund" semantics ("for a full
    // refund, set this to the full transaction amount") - omitting it used
    // to send a payload Monnify would reject outright.
    $history = [];
    $driver = makeMonnifyRefundDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'paymentReference' => 'mn_ref_full',
                'paymentStatus' => 'PAID',
                'amountPaid' => 7500.0,
                'currency' => 'NGN',
            ],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'refundReference' => 'REFUND_FULL',
                'transactionReference' => 'mn_ref_full',
                'refundStatus' => 'PENDING',
                'refundAmount' => 7500.0,
                'currencyCode' => 'NGN',
            ],
        ])),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'mn_ref_full'));

    expect($history)->toHaveCount(3); // oauth + verify + refund

    $refundRequest = $history[2]['request'];
    $sentBody = json_decode((string) $refundRequest->getBody(), true);
    expect((float) $sentBody['refundAmount'])->toBe(7500.0);

    expect($result->amount)->toBe(7500.0);
});

test('a full monnify refund fails clearly when the original transaction cannot be looked up', function () {
    $driver = makeMonnifyRefundDriver([
        new Response(200, [], json_encode(['requestSuccessful' => false, 'responseMessage' => 'Transaction not found'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'mn_ref_missing'));
})->throws(RefundException::class, 'Cannot issue a full refund for Monnify transaction [mn_ref_missing]');

test('monnify fetchRefund succeeds with valid response', function () {
    $driver = makeMonnifyRefundDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'refundReference' => 'REFUND_001',
                'transactionReference' => 'mn_ref_123',
                'refundStatus' => 'COMPLETED',
                'refundAmount' => 5000.0,
                'currencyCode' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->fetchRefund('REFUND_001');

    expect($result->refundReference)->toBe('REFUND_001')
        ->and($result->transactionReference)->toBe('mn_ref_123')
        ->and($result->status)->toBe('COMPLETED');
});
