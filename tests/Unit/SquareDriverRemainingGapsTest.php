<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/**
 * Covers the remaining untested branches of SquareDriver after
 * SquareDriverCoverageTest, SquareDriverEdgeCasesTest and
 * SquareDriverIntegrationTest.
 *
 * NOTE on dead code found while writing these tests:
 * AbstractDriver::makeRequest() catches *any* GuzzleException (the common
 * interface implemented by ClientException, ServerException and
 * ConnectException) and rewraps it into a ChargeException before it can
 * ever reach the calling driver method. Because every HTTP call in
 * SquareDriver goes through makeRequest(), the following bare
 * `catch (ClientException $e)` blocks that sit directly after a
 * `catch (ChargeException $e) { ... throw $e; }` block are unreachable:
 *   - charge()                    lines 139-168 (the whole ClientException catch)
 *   - verifyByPaymentId()         lines 266-271
 *   - verifyByPaymentLinkId()     lines 314-319
 *   - searchOrders()              lines 387-392
 *   - healthCheck()               lines 566-569 (both catch(ClientException) and catch(ConnectException))
 * These are not exercised here - see final report for details.
 */
function createSquareDriverForRemainingGaps(array $responses, array $config = []): SquareDriver
{
    $defaultConfig = [
        'access_token' => 'test_token',
        'location_id' => 'test_location',
        'base_url' => 'https://connect.squareup.com',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ];

    $mock = new MockHandler($responses);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $driver = new SquareDriver(array_merge($defaultConfig, $config));
    $driver->setClient($client);

    return $driver;
}

test('square driver verify falls through to payment link lookup and returns its result', function () {
    // 24 chars, does not start with "payment_" and length !== 32, so
    // verifyByPaymentId() short-circuits to null without any HTTP call.
    $reference = 'JE6RV44VZEML32Z2ABCDEFG';

    $driver = createSquareDriverForRemainingGaps([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'link_123',
                'order_id' => 'order_456',
            ],
        ])),
        new Response(200, [], json_encode([
            'order' => [
                'id' => 'order_456',
                'reference_id' => 'SQUARE_9999',
                'tenders' => [
                    ['payment_id' => 'payment_789'],
                ],
            ],
        ])),
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_789',
                'reference_id' => 'SQUARE_9999',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 15000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
            ],
        ])),
    ]);

    $result = $driver->verify($reference);

    expect($result->status)->toBe('success')
        ->and($result->reference)->toBe('SQUARE_9999')
        ->and($result->amount)->toBe(150.0);
});

test('square driver verify surfaces a detailed message for non-404 client errors', function () {
    // Starts with "payment_" so verifyByPaymentId() attempts the direct lookup.
    $reference = 'payment_abc123';

    $driver = createSquareDriverForRemainingGaps([
        new Response(400, [], json_encode([
            'errors' => [
                ['code' => 'BAD_REQUEST', 'detail' => 'Malformed payment id'],
            ],
        ])),
    ]);

    expect(fn () => $driver->verify($reference))
        ->toThrow(VerificationException::class, 'Payment verification failed: Malformed payment id');
});

test('square driver verify wraps unexpected non-Guzzle throwables', function () {
    $reference = 'payment_abc123';

    $driver = createSquareDriverForRemainingGaps([
        new \RuntimeException('unexpected boom'),
    ]);

    expect(fn () => $driver->verify($reference))
        ->toThrow(VerificationException::class, 'Payment verification failed: unexpected boom');
});

test('square driver verifyByPaymentId returns null when response has no payment key', function () {
    $driver = createSquareDriverForRemainingGaps([
        new Response(200, [], json_encode(['foo' => 'bar'])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'payment_abc123');

    expect($result)->toBeNull();
});

test('square driver getOrderById throws VerificationException when order is missing', function () {
    $driver = createSquareDriverForRemainingGaps([
        new Response(200, [], json_encode(['foo' => 'bar'])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getOrderById');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($driver, 'order_missing'))
        ->toThrow(VerificationException::class, 'Order not found for ID [order_missing]');
});

test('square driver getPaymentFromOrder throws VerificationException when payment_id is missing', function () {
    $driver = createSquareDriverForRemainingGaps([]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getPaymentFromOrder');
    $method->setAccessible(true);

    $order = ['tenders' => [['amount' => 1000]]];

    expect(fn () => $method->invoke($driver, $order, 'order_456'))
        ->toThrow(VerificationException::class, 'Payment ID not found for order [order_456]');
});

test('square driver validateWebhook rejects a valid signature with an unrecognized timestamp (replay protection)', function () {
    $driver = createSquareDriverForRemainingGaps([]);

    // Valid signature, but no recognizable timestamp field in the body.
    $body = json_encode(['test' => 'data']);
    $expectedSignature = base64_encode(hash_hmac('sha256', $body, 'test_secret_key', true));

    $result = $driver->validateWebhook(['x-square-signature' => [$expectedSignature]], $body);

    expect($result)->toBeFalse();
});
