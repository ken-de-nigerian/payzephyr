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

test('opay charge says so when a success body carries no checkout url', function () {
    // OPay answered 00000 but gave nothing to redirect the customer to.
    $driver = opayRemainingGapsDriver([
        new Response(200, [], (string) json_encode(['code' => '00000', 'data' => ['orderNo' => 'ORD1']])),
    ]);

    expect(fn () => $driver->charge(\KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'customer@example.com',
        'reference' => 'OPAY_NO_URL_1',
    ])))->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class, 'returned no checkout URL');
});

test('opay reads the reference, status and channel from the webhook payload branch', function () {
    // OPay sends {"payload": {...}, "sha512": ..., "type": ...}. The
    // extractors read the top level, so the reference was null and the
    // transaction was never updated from a webhook.
    $driver = new OPayDriver(opayRemainingGapsConfig());
    $body = ['type' => 'transaction-status', 'sha512' => 'x', 'payload' => [
        'reference' => 'ORDER_77', 'status' => 'SUCCESS', 'instrumentType' => 'BankCard',
    ]];

    expect($driver->extractWebhookReference($body))->toBe('ORDER_77')
        ->and($driver->extractWebhookStatus($body))->toBe('SUCCESS')
        ->and($driver->extractWebhookChannel($body))->toBe('BankCard');
});

test('opay still reads a flat webhook body, with its fallback field names', function () {
    $driver = new OPayDriver(opayRemainingGapsConfig());

    expect($driver->extractWebhookReference(['orderNo' => 'ORD_9']))->toBe('ORD_9')
        ->and($driver->extractWebhookStatus(['orderStatus' => 'PENDING']))->toBe('PENDING')
        ->and($driver->extractWebhookStatus(['payload' => []]))->toBe('unknown')
        ->and($driver->extractWebhookChannel(['payload' => ['paymentChannel' => 'USSD']]))->toBe('USSD')
        ->and($driver->extractWebhookReference([]))->toBeNull();
});

test('an opay webhook updates the transaction it names', function () {
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'ORDER_88', 'provider' => 'opay', 'status' => 'pending',
        'amount' => 5000, 'currency' => 'NGN', 'email' => 'a@b.test',
    ]);

    app()->call([new \KenDeNigerian\PayZephyr\Jobs\ProcessWebhook('opay', ['type' => 'transaction-status', 'payload' => [
        'reference' => 'ORDER_88', 'status' => 'SUCCESS',
    ]]), 'handle']);

    expect(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'ORDER_88')->value('status'))->toBe('success');
});

test('opay does not verify a webhook against the public key', function () {
    // With no secret key configured, validation used to fall back to the
    // public key. Anyone can know that, so anyone could sign a webhook with it.
    $driver = new OPayDriver(opayRemainingGapsConfig(['secret_key' => null]));
    $body = (string) json_encode(['payload' => ['reference' => 'FORGED', 'status' => 'SUCCESS'], 'type' => 'transaction-status']);
    $signedWithPublicKey = hash_hmac('sha256', $body, 'PUBLIC_KEY_123');

    expect($driver->validateWebhook(['x-opay-signature' => [$signedWithPublicKey]], $body))->toBeFalse();
});

test('opay charge wraps a failure that is not an http error in a charge exception', function () {
    $driver = opayRemainingGapsDriver([new RuntimeException('stream wrapper failed')]);

    try {
        $driver->charge(\KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO::fromArray([
            'amount' => 100, 'currency' => 'NGN', 'email' => 'customer@example.com', 'reference' => 'OPAY_BOOM',
        ]));
        $this->fail('Expected a ChargeException');
    } catch (\KenDeNigerian\PayZephyr\Exceptions\ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: stream wrapper failed')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

test('opay rejects a webhook whose signature does not match the secret key', function () {
    $driver = new OPayDriver(opayRemainingGapsConfig());
    $body = (string) json_encode(['payload' => ['reference' => 'R1', 'status' => 'SUCCESS'], 'type' => 'transaction-status']);

    expect($driver->validateWebhook(['x-opay-signature' => [hash_hmac('sha256', $body, 'not-the-secret')]], $body))->toBeFalse()
        ->and($driver->validateWebhook(['x-opay-signature' => [hash_hmac('sha256', $body, 'SECRET_KEY_123')]], $body))->toBeTrue();
});
