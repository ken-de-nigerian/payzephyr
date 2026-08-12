<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

function makeMonnifyRefundDriver(array $responses): MonnifyDriver
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
    $driver = new MonnifyDriver($config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

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

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid'));
})->throws(RefundException::class, 'Transaction not found');

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
