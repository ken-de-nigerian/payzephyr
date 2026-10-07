<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
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

test('square driver verify falls through to payment link lookup and returns its result', function (): void {
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

test('square driver verify surfaces a detailed message for non-404 client errors', function (): void {
    // Starts with "payment_" so verifyByPaymentId() attempts the direct lookup.
    $reference = 'payment_abc123';

    $driver = createSquareDriverForRemainingGaps([
        new Response(400, [], json_encode([
            'errors' => [
                ['code' => 'BAD_REQUEST', 'detail' => 'Malformed payment id'],
            ],
        ])),
    ]);

    expect(fn (): VerificationResponseDTO => $driver->verify($reference))
        ->toThrow(VerificationException::class, 'Payment verification failed: Malformed payment id');
});

test('square driver verify wraps unexpected non-Guzzle throwables', function (): void {
    $reference = 'payment_abc123';

    $driver = createSquareDriverForRemainingGaps([
        new \RuntimeException('unexpected boom'),
    ]);

    expect(fn (): VerificationResponseDTO => $driver->verify($reference))
        ->toThrow(VerificationException::class, 'Payment verification failed: unexpected boom');
});

test('square driver verifyByPaymentId returns null when response has no payment key', function (): void {
    $driver = createSquareDriverForRemainingGaps([
        new Response(200, [], json_encode(['foo' => 'bar'])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');

    $result = $method->invoke($driver, 'payment_abc123');

    expect($result)->toBeNull();
});

test('square driver getOrderById throws VerificationException when order is missing', function (): void {
    $driver = createSquareDriverForRemainingGaps([
        new Response(200, [], json_encode(['foo' => 'bar'])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getOrderById');

    expect(fn (): mixed => $method->invoke($driver, 'order_missing'))
        ->toThrow(VerificationException::class, 'Order not found for ID [order_missing]');
});

test('square driver getPaymentFromOrder throws VerificationException when payment_id is missing', function (): void {
    $driver = createSquareDriverForRemainingGaps([]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getPaymentFromOrder');

    $order = ['tenders' => [['amount' => 1000]]];

    expect(fn (): mixed => $method->invoke($driver, $order, 'order_456'))
        ->toThrow(VerificationException::class, 'Payment ID not found for order [order_456]');
});

test('square driver validateWebhook rejects a valid signature with an unrecognized timestamp (replay protection)', function (): void {
    $driver = createSquareDriverForRemainingGaps([]);

    // Valid signature, but no recognizable timestamp field in the body.
    $body = json_encode(['test' => 'data']);
    $expectedSignature = squareWebhookSignature($body, 'test_secret_key');

    $result = $driver->validateWebhook(['x-square-hmacsha256-signature' => [$expectedSignature]], $body);

    expect($result)->toBeFalse();
});

test('square verify reports payment not found when the order search itself is not found', function (): void {
    // Neither a payment id nor a payment link: the reference falls through
    // to the order search, and a 404 there is a clean "not found" rather
    // than a generic verification failure.
    $driver = createSquareDriverForRemainingGaps([
        new Response(404, [], (string) json_encode(['errors' => [['code' => 'NOT_FOUND']]])),
        new Response(404, [], (string) json_encode(['errors' => [['code' => 'NOT_FOUND', 'detail' => 'Location not found']]])),
    ]);

    try {
        $driver->verify('SQUARE_REF_1');
        $this->fail('Expected a VerificationException');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment not found')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('square verify reports a server error from the order search as a verification failure', function (): void {
    $driver = createSquareDriverForRemainingGaps([
        new Response(404, [], (string) json_encode(['errors' => [['code' => 'NOT_FOUND']]])),
        new Response(503, [], '{}'),
    ]);

    try {
        $driver->verify('SQUARE_REF_1');
        $this->fail('Expected a VerificationException');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toStartWith('Payment verification failed: ')
            ->and($e->getMessage())->not->toBe('Payment not found')
            ->and($e->getPrevious())->toBeInstanceOf(ChargeException::class);
    }
});

/*
 * Square's documented scheme: base64(HMAC-SHA256(signature key, notification
 * URL . body)) in x-square-hmacsha256-signature. The driver used to read the
 * legacy x-square-signature header and sign the body alone - neither of
 * Square's schemes - and these tests' predecessors built their signatures
 * the same wrong way, so they agreed with the bug.
 */

function squareSignatureDriver(array $overrides = []): SquareDriver
{
    return new SquareDriver(array_merge([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'sq_sig_key',
        'webhook_url' => 'https://shop.example.com/payments/webhook/square',
        'currencies' => ['USD'],
    ], $overrides));
}

function squareSignedBody(): string
{
    return (string) json_encode([
        'merchant_id' => 'M1',
        'type' => 'payment.updated',
        'event_id' => 'evt-1',
        'created_at' => now()->toIso8601String(),
        'data' => ['type' => 'payment', 'id' => 'pay_1'],
    ]);
}

test('square accepts a signature over the configured notification url and the body', function (): void {
    $body = squareSignedBody();
    $signature = base64_encode(hash_hmac('sha256', 'https://shop.example.com/payments/webhook/square'.$body, 'sq_sig_key', true));

    expect(squareSignatureDriver()->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body))->toBeTrue();
});

test('square rejects a signature over the body alone', function (): void {
    $body = squareSignedBody();
    $bodyOnly = base64_encode(hash_hmac('sha256', $body, 'sq_sig_key', true));

    expect(squareSignatureDriver()->validateWebhook(['x-square-hmacsha256-signature' => [$bodyOnly]], $body))->toBeFalse();
});

test('square rejects a signature made for a different notification url', function (): void {
    // Square signs the URL registered in its dashboard. A trailing slash, a
    // different host or http instead of https is a different signature.
    $body = squareSignedBody();
    $signature = base64_encode(hash_hmac('sha256', 'https://shop.example.com/payments/webhook/square/'.$body, 'sq_sig_key', true));

    expect(squareSignatureDriver()->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body))->toBeFalse();
});

test('square does not accept the legacy x-square-signature header in place of the sha256 one', function (): void {
    $body = squareSignedBody();
    $signature = base64_encode(hash_hmac('sha256', 'https://shop.example.com/payments/webhook/square'.$body, 'sq_sig_key', true));

    expect(squareSignatureDriver()->validateWebhook(['x-square-signature' => [$signature]], $body))->toBeFalse();
});

test('square signs against the package webhook route when no notification url is configured', function (): void {
    $body = squareSignedBody();
    $signature = base64_encode(hash_hmac('sha256', route('payments.webhook', ['provider' => 'square']).$body, 'sq_sig_key', true));

    expect(squareSignatureDriver(['webhook_url' => null])->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body))->toBeTrue()
        ->and(squareSignatureDriver(['webhook_url' => ''])->validateWebhook(['x-square-hmacsha256-signature' => [$signature]], $body))->toBeTrue();
});

/*
 * Verify-by-reference is the last resort: the charge's payment link id is
 * normally used instead. Square cannot search orders by reference, so orders
 * are read newest-first until one matches. It used to read a single page.
 */

function squareSearchDriver(array $responses, array &$history, array $config = []): SquareDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $driver = new SquareDriver(array_merge([
        'access_token' => 'test_token',
        'location_id' => 'test_location',
        'currencies' => ['USD'],
    ], $config));
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function squareNotFound(): Response
{
    return new Response(404, [], (string) json_encode(['errors' => [['code' => 'NOT_FOUND']]]));
}

function squareOrdersPage(array $referenceIds, ?string $cursor): Response
{
    return new Response(200, [], (string) json_encode(array_filter([
        'orders' => array_map(fn (string $ref): array => ['id' => 'order_'.$ref, 'reference_id' => $ref], $referenceIds),
        'cursor' => $cursor,
    ], fn ($value): bool => $value !== null)));
}

function squareSearchBodies(array $history): array
{
    return array_values(array_map(
        fn (array $entry): mixed => json_decode((string) $entry['request']->getBody(), true),
        array_filter($history, fn (array $entry): bool => str_ends_with($entry['request']->getUri()->getPath(), '/v2/orders/search'))
    ));
}

test('square finds an order on a later page of the search by following the cursor', function (): void {
    $history = [];
    $driver = squareSearchDriver([
        squareNotFound(), // payment link lookup
        squareOrdersPage(['SQ_NEWER_1', 'SQ_NEWER_2'], 'cursor_page_2'),
        squareOrdersPage(['SQ_TARGET'], null),
        new Response(200, [], (string) json_encode(['order' => ['id' => 'order_SQ_TARGET', 'reference_id' => 'SQ_TARGET', 'tenders' => [['payment_id' => 'pay_1']]]])),
        new Response(200, [], (string) json_encode(['payment' => [
            'id' => 'pay_1', 'reference_id' => 'SQ_TARGET', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 1500, 'currency' => 'USD'], 'source_type' => 'CARD',
        ]])),
    ], $history);

    expect($driver->verify('SQ_TARGET')->status)->toBe('success');

    $searches = squareSearchBodies($history);
    expect($searches)->toHaveCount(2)
        ->and($searches[0])->not->toHaveKey('cursor')
        ->and($searches[0]['query']['sort'])->toBe(['sort_field' => 'CREATED_AT', 'sort_order' => 'DESC'])
        ->and($searches[1]['cursor'])->toBe('cursor_page_2');
});

test('square reports not found once the search runs out of orders', function (): void {
    $history = [];
    $driver = squareSearchDriver([
        squareNotFound(),
        squareOrdersPage(['SQ_OTHER'], 'cursor_page_2'),
        squareOrdersPage([], null),
    ], $history);

    expect(fn (): VerificationResponseDTO => $driver->verify('SQ_MISSING'))
        ->toThrow(VerificationException::class, 'Payment not found for reference [SQ_MISSING]');
});

test('square stops after the configured number of pages and says how far back it looked', function (): void {
    $history = [];
    $driver = squareSearchDriver([
        squareNotFound(),
        squareOrdersPage(['A', 'B'], 'c2'),
        squareOrdersPage(['C', 'D'], 'c3'),
        squareOrdersPage(['E'], 'c4'), // never requested
    ], $history, ['verify_search_pages' => 2]);

    expect(fn (): VerificationResponseDTO => $driver->verify('SQ_OLD'))
        ->toThrow(VerificationException::class, 'in the 4 most recent Square orders');

    expect(squareSearchBodies($history))->toHaveCount(2);
});

test('a page limit that is not a positive number still searches one page', function (): void {
    $history = [];
    $driver = squareSearchDriver([
        squareNotFound(),
        squareOrdersPage(['A'], 'c2'),
    ], $history, ['verify_search_pages' => 0]);

    expect(fn (): VerificationResponseDTO => $driver->verify('SQ_X'))->toThrow(VerificationException::class, 'in the 1 most recent Square orders');
    expect(squareSearchBodies($history))->toHaveCount(1);
});

test('square searches ten pages of five hundred orders by default', function (): void {
    $history = [];
    $pages = [squareNotFound()];
    foreach (range(1, 11) as $page) {
        $pages[] = squareOrdersPage(["ORDER_$page"], 'cursor_'.($page + 1)); // the eleventh is never requested
    }

    $driver = squareSearchDriver($pages, $history);

    expect(fn (): VerificationResponseDTO => $driver->verify('SQ_OLD'))
        ->toThrow(VerificationException::class, 'in the 10 most recent Square orders');

    $searches = squareSearchBodies($history);

    expect($searches)->toHaveCount(10)
        ->and(array_unique(array_column($searches, 'limit')))->toBe([500]);
});
