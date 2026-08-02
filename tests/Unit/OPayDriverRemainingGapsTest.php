<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/**
 * Closes remaining coverage gaps in OPayDriver that are not exercised by
 * OPayDriverCoverageTest or OPayDriverIntegrationTest.
 */
function opayRemainingGapsConfig(array $overrides = []): array
{
    return array_merge([
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'secret_key' => 'SECRET_KEY_123',
        'currencies' => ['NGN'],
    ], $overrides);
}

function opayRemainingGapsDriver(array $responses, array $configOverrides = []): OPayDriver
{
    $mock = new MockHandler($responses);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $driver = new OPayDriver(opayRemainingGapsConfig($configOverrides));
    $driver->setClient($client);

    return $driver;
}

test('opay driver throws invalid configuration exception when merchant id is missing', function () {
    new OPayDriver(['public_key' => 'PUBLIC_KEY_123', 'currencies' => ['NGN']]);
})->throws(InvalidConfigurationException::class, 'OPay merchant ID is required');

test('opay driver throws invalid configuration exception when public key is missing', function () {
    new OPayDriver(['merchant_id' => 'MERCHANT123', 'currencies' => ['NGN']]);
})->throws(InvalidConfigurationException::class, 'OPay public key is required');

test('opay driver verify throws when the private key needed for status API auth is missing', function () {
    // verify() requires a distinct 'secret_key' entry (used to HMAC-sign the
    // status request) that validateConfig() does not check for, so this is
    // reachable even on an otherwise fully-configured driver.
    $driver = new OPayDriver([
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'currencies' => ['NGN'],
    ]);

    $driver->verify('OPAY_REF_123');
})->throws(VerificationException::class, 'OPay secret key (private key) is required for status API authentication');

test('opay driver verify normalizes an unmapped status through the default branch', function () {
    // 'SUCCESS'/'PENDING' (and their synonyms) are special-cased directly in
    // the match(); anything else - like 'FAILED' - falls through to the
    // `default => $this->normalizeStatus($opayStatus)` arm.
    $driver = opayRemainingGapsDriver([
        new Response(200, [], json_encode([
            'code' => '00000',
            'message' => 'Success',
            'data' => [
                'reference' => 'OPAY_FAILED_TXN',
                'amount' => ['total' => 20000, 'currency' => 'NGN'],
                'status' => 'FAILED',
            ],
        ])),
    ]);

    $result = $driver->verify('OPAY_FAILED_TXN');

    expect($result->status)->toBe('failed');
});

test('opay driver healthCheck returns true when a ClientException carries a 400/404 response', function () {
    // Drives the exception through the real Guzzle client (rather than a
    // Mockery double that throws ClientException directly) so
    // AbstractDriver::makeRequest() wraps it as
    // ChargeException(msg, 0, $clientException) first, exercising
    // healthCheck()'s getPrevious() chain-walk loop that locates the
    // original ClientException.
    $request = new Request('POST', '/api/v1/international/cashier/status');
    $response = new Response(400, [], json_encode(['code' => '00001', 'message' => 'Bad request']));

    $driver = opayRemainingGapsDriver([
        new ClientException('Bad Request', $request, $response),
    ]);

    expect($driver->healthCheck())->toBeTrue();
});
