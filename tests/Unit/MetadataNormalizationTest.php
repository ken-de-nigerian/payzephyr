<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

/**
 * Regression: a provider returning metadata as an empty string.
 *
 * A plain null-coalesce only catches null and missing keys. Paystack returns
 * an empty string when a transaction has no metadata, which passes straight
 * through into VerificationResponseDTO's typed array parameter and fatals
 * with a TypeError before the constructor body runs.
 *
 * Found in production: every verify of a charge created without metadata
 * crashed, while the identical charge created with metadata verified fine.
 */
function jsonClient(array $body): Client
{
    return new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode($body)),
    ]))]);
}

test('paystack verify survives metadata returned as an empty string', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(jsonClient([
        'status' => true,
        'data' => [
            'reference' => 'ref_1',
            'status' => 'abandoned',
            'amount' => 500000,
            'currency' => 'NGN',
            'metadata' => '',
        ],
    ]));

    $result = $driver->verify('ref_1');

    expect($result->metadata)->toBe([])
        ->and($result->reference)->toBe('ref_1')
        ->and($result->amount)->toBe(5000.0);
});

test('paystack verify still returns real metadata when present', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(jsonClient([
        'status' => true,
        'data' => [
            'reference' => 'ref_2',
            'status' => 'success',
            'amount' => 500000,
            'currency' => 'NGN',
            'metadata' => ['order_id' => 991],
        ],
    ]));

    expect($driver->verify('ref_2')->metadata)->toBe(['order_id' => 991]);
});

test('paystack verify decodes metadata sent as a JSON string rather than discarding it', function () {
    // Paystack encodes metadata as a JSON string in some responses. Treating
    // every non-array as empty would silently lose the caller's own values.
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(jsonClient([
        'status' => true,
        'data' => [
            'reference' => 'ref_3',
            'status' => 'success',
            'amount' => 500000,
            'currency' => 'NGN',
            'metadata' => '{"order_id":991}',
        ],
    ]));

    expect($driver->verify('ref_3')->metadata)->toBe(['order_id' => 991]);
});

test('flutterwave verify survives meta returned as an empty string', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(jsonClient([
        'status' => 'success',
        'data' => [
            'tx_ref' => 'ref_1',
            'status' => 'successful',
            'amount' => 5000,
            'currency' => 'NGN',
            'meta' => '',
        ],
    ]));

    expect($driver->verify('ref_1')->metadata)->toBe([]);
});

test('monnify verify survives metaData returned as an empty string', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'MK_TEST', 'secret_key' => 'SK_TEST',
        'contract_code' => 'C1', 'base_url' => 'https://sandbox.monnify.com',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'tok', 'expiresIn' => 3600],
        ])),
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'paymentReference' => 'ref_1',
                'paymentStatus' => 'PAID',
                'amountPaid' => 5000,
                'currencyCode' => 'NGN',
                'metaData' => '',
            ],
        ])),
    ]))]));

    expect($driver->verify('ref_1')->metadata)->toBe([]);
});

test('opay verify survives metadata returned as an empty string', function () {
    $driver = new OPayDriver([
        'merchant_id' => 'M1', 'public_key' => 'PK', 'secret_key' => 'SK',
        'base_url' => 'https://liveapi.opaycheckout.com', 'currencies' => ['NGN'],
    ]);
    $driver->setClient(jsonClient([
        'code' => '00000',
        'data' => [
            'reference' => 'ref_1',
            'status' => 'SUCCESS',
            'amount' => ['total' => 500000, 'currency' => 'NGN'],
            'metadata' => '',
        ],
    ]));

    expect($driver->verify('ref_1')->metadata)->toBe([]);
});

test('mollie verify survives metadata returned as an empty string', function () {
    $driver = new MollieDriver(['api_key' => 'test_key', 'currencies' => ['EUR']]);
    $driver->setClient(jsonClient([
        'id' => 'tr_1',
        'status' => 'paid',
        'amount' => ['value' => '50.00', 'currency' => 'EUR'],
        'metadata' => '',
    ]));

    expect($driver->verify('tr_1')->metadata)->toBe([]);
});

test('every DTO factory accepts a non-array metadata value without fataling', function () {
    // fromArray() is public API. Anyone rebuilding a DTO from a provider
    // payload or from stored JSON hits the same crash.
    expect(VerificationResponseDTO::fromArray(['metadata' => ''])->metadata)->toBe([])
        ->and(ChargeResponseDTO::fromArray(['metadata' => ''])->metadata)->toBe([])
        ->and(RefundResponseDTO::fromArray(['metadata' => ''])->metadata)->toBe([])
        ->and(SubscriptionResponseDTO::fromArray(['metadata' => ''])->metadata)->toBe([])
        ->and(RefundRequestDTO::fromArray([
            'transaction_reference' => 't1', 'metadata' => '',
        ])->metadata)->toBe([])
        ->and(ChargeRequestDTO::fromArray([
            'amount' => 10, 'currency' => 'NGN', 'email' => 'a@b.test', 'metadata' => '',
        ])->metadata)->toBe([]);
});

test('DTO factories decode a JSON-string metadata value', function () {
    expect(VerificationResponseDTO::fromArray(['metadata' => '{"a":1}'])->metadata)->toBe(['a' => 1])
        ->and(ChargeResponseDTO::fromArray(['metadata' => '{"a":1}'])->metadata)->toBe(['a' => 1]);
});
