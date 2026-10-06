<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/*
 * What SquareDriver sends to Square, what it makes of the answers, and what
 * it logs - payment links, the three ways a payment is found, and Square's
 * own error details.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function squareContractDriver(array $responses = [], array &$history = [], array $config = []): SquareDriver
{
    $driver = new SquareDriver($config + [
        'access_token' => 'sq_token', 'location_id' => 'LOC_1', 'base_url' => 'https://connect.squareupsandbox.com',
        'webhook_signature_key' => 'sq_sig', 'webhook_url' => 'https://shop.test/payments/webhook/square', 'currencies' => ['USD'],
    ]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function squareLinkCreated(): Response
{
    return new Response(200, [], '{"payment_link":{"id":"PL_1","url":"https://square.link/u/x","order_id":"ORD_1"}}');
}

function squareCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 10.5, 'currency' => 'USD', 'email' => 'a@b.com', 'reference' => 'SQUARE_1', 'callbackUrl' => 'https://shop.test/return']);
}

function squareClientError(int $status, string $body = '{}', string $uri = '/v2/x'): ClientException
{
    return new ClientException("Client error $status", new Request('GET', $uri), new Response($status, [], $body));
}

function squarePayment(array $overrides = []): array
{
    return $overrides + ['id' => 'payment_1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1050, 'currency' => 'usd'], 'order_id' => 'ORD_1'];
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Square a full payment link, authenticated and versioned', function () {
    $history = [];
    $result = squareContractDriver([squareLinkCreated()], $history)->charge(squareCharge(['description' => 'Order 9', 'idempotencyKey' => 'idem-1']));

    $request = $history[0]['request'];
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'idempotency_key' => 'idem-1',
        'order' => [
            'location_id' => 'LOC_1',
            'reference_id' => 'SQUARE_1',
            'line_items' => [['name' => 'Order 9', 'quantity' => '1', 'base_price_money' => ['amount' => 1050, 'currency' => 'USD']]],
        ],
        'redirect_url' => 'https://shop.test/return?reference=SQUARE_1',
        'buyer_email_address' => 'a@b.com',
    ])
        ->and((string) $request->getUri())->toEndWith('/v2/online-checkout/payment-links')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer sq_token')
        ->and($request->getHeaderLine('Square-Version'))->toBe('2024-10-18')
        ->and($result->metadata)->toBe(['payment_link_id' => 'PL_1', 'order_id' => 'ORD_1']);
});

test('a charge without a key of its own gets a fresh one, and without a description says "Payment"', function () {
    $history = [];
    squareContractDriver([squareLinkCreated()], $history)->charge(squareCharge());

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['idempotency_key'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($sent['order']['line_items'][0]['name'])->toBe('Payment');
});

test('an initialized charge is logged with its reference and whether it was idempotent', function (?string $key, bool $idempotent) {
    $logs = captureLogs();
    squareContractDriver([squareLinkCreated()])->charge(squareCharge(['idempotencyKey' => $key]));

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'SQUARE_1', 'idempotent' => $idempotent]);
})->with([
    'with a key' => ['idem-2', true],
    'without' => [null, false],
]);

test('a link Square does not return is refused with its error detail, and logged', function (string $body, string $message, array $errors) {
    $logs = captureLogs();

    expect(fn () => squareContractDriver([new Response(200, [], $body)])->charge(squareCharge()))->toThrow(ChargeException::class, $message)
        ->and(loggedEntry($logs, 'Failed to create payment link')['context'])->toBe(['reference' => 'SQUARE_1', 'errors' => $errors]);
})->with([
    'detail' => ['{"errors":[{"detail":"Invalid location"}]}', 'Invalid location', [['detail' => 'Invalid location']]],
    'nothing' => ['{}', 'Failed to create Square payment link', []],
]);

test('a charge Square refuses is raised with its detail and a hint for the environment, coded 0, and logged', function (string $baseUrl, int $status, string $body, string $message) {
    $logs = captureLogs();

    try {
        squareContractDriver([squareClientError($status, $body)], config: ['base_url' => $baseUrl])->charge(squareCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: '.$message)
            ->and($e->getCode())->toBe(0)
            ->and($e->getPrevious())->toBeInstanceOf(ClientException::class);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe([
        'reference' => 'SQUARE_1', 'status_code' => $status, 'error' => $message,
        'errors' => json_decode($body, true)['errors'] ?? [], 'error_class' => ClientException::class,
    ]);
})->with([
    'sandbox token' => ['https://connect.squareupsandbox.com', 401, '{"errors":[{"detail":"Unauthorized"}]}', 'Unauthorized Make sure you are using a sandbox access token. Check Square Dashboard → Applications → Your App → Sandbox → Access Tokens.'],
    'production token' => ['https://connect.squareup.com', 403, '{"errors":[{"code":"FORBIDDEN"}]}', 'FORBIDDEN Make sure you are using a production access token. Check Square Dashboard → Applications → Your App → Production → Access Tokens.'],
    'another host' => ['http://localhost', 401, '{"errors":[{"detail":"Unauthorized"}]}', 'Unauthorized'],
    'not auth' => ['https://connect.squareup.com', 400, '{}', 'Client error 400'],
]);

test('a charge refused without a base URL is raised without a hint', function () {
    try {
        squareContractDriver([squareClientError(401)], config: ['base_url' => null])->charge(squareCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: Client error 401');
    }
});

test('a charge failing for any other reason is rethrown as it was, or logged and wrapped', function () {
    $logs = captureLogs();
    $down = new ServerException('Service Unavailable', new Request('POST', '/x'), new Response(503));

    try {
        squareContractDriver([$down])->charge(squareCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getPrevious())->toBe($down);
    }

    try {
        squareContractDriver([fn () => throw new LogicException('handler blew up')])->charge(squareCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])
        ->toBe(['reference' => 'SQUARE_1', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

test('a charge does not leave its key behind for the next request', function () {
    $history = [];
    $driver = squareContractDriver([squareLinkCreated(), new Response(200, [], (string) json_encode(['payment' => squarePayment()]))], $history);

    $driver->charge(squareCharge(['idempotencyKey' => 'idem-3']));
    $driver->verify('payment_1');

    expect($history[1]['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a payment id is looked up directly, and read in full', function (string $reference) {
    $history = [];
    $result = squareContractDriver([new Response(200, [], (string) json_encode(['payment' => squarePayment([
        'id' => $reference, 'reference_id' => 'SQUARE_V', 'status' => ' approved ', 'updated_at' => '2026-10-01T10:00:00Z',
        'source_type' => 'WALLET', 'card_details' => ['card' => ['card_brand' => 'VISA']], 'buyer_email_address' => 'a@b.com',
    ])]))], $history)->verify($reference);

    expect((string) $history[0]['request']->getUri())->toEndWith('/v2/payments/'.rawurlencode($reference))
        ->and($result->reference)->toBe('SQUARE_V')
        ->and($result->status)->toBe('success')
        ->and($result->currency)->toBe('USD')
        ->and($result->amount)->toBe(10.5)
        ->and($result->paidAt)->toBe('2026-10-01T10:00:00Z')
        ->and($result->metadata)->toBe(['payment_id' => $reference, 'order_id' => 'ORD_1'])
        ->and($result->channel)->toBe('WALLET')
        ->and($result->cardType)->toBe('VISA')
        ->and($result->customer)->toBe(['email' => 'a@b.com']);
})->with([
    'payment_ prefix' => ['payment_abc'],
    'a 32-character id' => [str_repeat('p', 32)],
]);

test('a reference neither prefixed nor 32 characters long is not tried as a payment id', function (int $length) {
    $history = [];
    squareContractDriver([
        new Response(200, [], '{"payment_link":{"order_id":"ORD_1"}}'),
        new Response(200, [], '{"order":{"tenders":[{"payment_id":"payment_1"}]}}'),
        new Response(200, [], (string) json_encode(['payment' => squarePayment()])),
    ], $history)->verify(str_repeat('p', $length));

    expect((string) $history[0]['request']->getUri())->toContain('/v2/online-checkout/payment-links/');
})->with([31, 33]);

test('a payment link is followed to its order and payment, under the order\'s reference', function () {
    $history = [];
    $result = squareContractDriver([
        new Response(200, [], '{"payment_link":{"order_id":"ORD 1"}}'),
        new Response(200, [], '{"order":{"reference_id":"SQUARE_L","tenders":[{"payment_id":"pay 1"}]}}'),
        new Response(200, [], (string) json_encode(['payment' => squarePayment(['status' => 'PENDING'])])),
    ], $history)->verify('PL 1');

    expect(array_map(fn (array $h): string => (string) $h['request']->getUri(), $history))->sequence(
        fn ($uri) => $uri->toEndWith('/v2/online-checkout/payment-links/PL%201'),
        fn ($uri) => $uri->toEndWith('/v2/orders/ORD%201'),
        fn ($uri) => $uri->toEndWith('/v2/payments/pay%201'),
    )
        ->and($result->reference)->toBe('SQUARE_L')
        ->and($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull();
});

test('a payment link whose order has no reference of its own is read by the one asked for', function () {
    $result = squareContractDriver([
        new Response(200, [], '{"payment_link":{"order_id":"ORD_1"}}'),
        new Response(200, [], '{"order":{"tenders":[{"payment_id":"pay_1"}]}}'),
        new Response(200, [], (string) json_encode(['payment' => squarePayment()])),
    ])->verify('PL_ASKED');

    expect($result->reference)->toBe('PL_ASKED');
});

test('a reference is searched for among recent orders, then followed to its payment', function () {
    $history = [];
    $result = squareContractDriver([
        squareClientError(404),
        new Response(200, [], '{"orders":[{"id":"ORD_X","reference_id":"other"}],"cursor":"c2"}'),
        new Response(200, [], '{"orders":[{"id":"ORD_2","reference_id":"SQUARE_R"}]}'),
        new Response(200, [], '{"order":{"tenders":[{"payment_id":"pay_2"}]}}'),
        new Response(200, [], (string) json_encode(['payment' => squarePayment()])),
    ], $history)->verify('SQUARE_R');

    expect(json_decode((string) $history[1]['request']->getBody(), true))->toBe([
        'location_ids' => ['LOC_1'],
        'limit' => 500,
        'query' => [
            'filter' => ['state_filter' => ['states' => ['OPEN', 'COMPLETED', 'CANCELED']]],
            'sort' => ['sort_field' => 'CREATED_AT', 'sort_order' => 'DESC'],
        ],
    ])
        ->and(json_decode((string) $history[2]['request']->getBody(), true)['cursor'])->toBe('c2')
        ->and($result->reference)->toBe('SQUARE_R');
});

test('a reference not in the orders searched is refused, saying how far the search went', function (string $last, array $config, string $message) {
    $responses = [squareClientError(404), new Response(200, [], '{"orders":[{"id":"O1"}],"cursor":"c2"}'), new Response(200, [], $last)];

    expect(fn () => squareContractDriver($responses, config: $config)->verify('SQUARE_GONE'))->toThrow(VerificationException::class, $message);
})->with([
    'no more pages' => ['{"orders":[{"id":"O2"}]}', [], 'Payment not found for reference [SQUARE_GONE]'],
    'an empty cursor' => ['{"orders":[{"id":"O2"}],"cursor":""}', [], 'Payment not found for reference [SQUARE_GONE]'],
    'page limit reached' => ['{"orders":[{"id":"O2"}],"cursor":"c3"}', ['verify_search_pages' => 2], 'Payment not found for reference [SQUARE_GONE] in the 2 most recent Square orders. Square cannot search orders by reference, so older orders are not read. Verify with the payment link id instead, or raise verify_search_pages.'],
]);

test('a verification Square refuses is raised with its detail, coded 0, and logged', function () {
    $logs = captureLogs();

    try {
        squareContractDriver([squareClientError(401, '{"errors":[{"detail":"Unauthorized"}]}')])->verify('payment_1');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: Unauthorized')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])->toBe([
        'reference' => 'payment_1', 'status_code' => 401, 'error' => 'Unauthorized',
        'errors' => [['detail' => 'Unauthorized']], 'error_class' => ClientException::class,
    ]);
});

test('a verification failing without an answer from Square is wrapped, coded 0', function (string $kind, string $message) {
    $logs = captureLogs();
    $failure = $kind === 'unexpected'
        ? fn () => throw new LogicException('handler blew up')
        : new ConnectException('Connection refused', new Request('GET', '/v2/payments/payment_1'));

    try {
        squareContractDriver([$failure])->verify('payment_1');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toStartWith('Payment verification failed: '.$message)->and($e->getCode())->toBe(0);
    }

    if ($kind === 'unexpected') {
        expect(loggedEntry($logs, 'Verification failed')['context'])
            ->toBe(['reference' => 'payment_1', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
    }
})->with([
    'unreachable' => ['unreachable', 'Unable to connect'],
    'unexpected' => ['unexpected', 'handler blew up'],
]);

test('a lookup that fails for any reason but not-found is not mistaken for one', function (int $step) {
    $unreachable = new ConnectException('Connection refused', new Request('GET', '/x'));
    $responses = match ($step) {
        1 => [$unreachable],
        2 => [$unreachable],
        3 => [squareClientError(404), $unreachable],
    };

    expect(fn () => squareContractDriver($responses)->verify($step === 1 ? 'payment_1' : 'SQUARE_X'))
        ->toThrow(VerificationException::class, 'Unable to connect');
})->with([
    'by payment id' => [1],
    'by payment link' => [2],
    'searching orders' => [3],
]);

// ---------------------------------------------------------------------------
// Webhooks and health
// ---------------------------------------------------------------------------

test('each webhook outcome is logged, and each refusal is final', function () {
    $logs = captureLogs();
    $body = (string) json_encode(['created_at' => now()->toIso8601String(), 'type' => 'payment.updated']);
    $sign = fn (string $body): array => ['x-square-hmacsha256-signature' => [base64_encode(hash_hmac('sha256', 'https://shop.test/payments/webhook/square'.$body, 'sq_sig', true))]];
    $old = (string) json_encode(['created_at' => now()->subDays(10)->toIso8601String()]);

    expect(squareContractDriver()->validateWebhook([], $body))->toBeFalse()
        ->and(squareContractDriver(config: ['webhook_signature_key' => null])->validateWebhook(['x-square-hmacsha256-signature' => ['x']], $body))->toBeFalse()
        ->and(squareContractDriver()->validateWebhook(['x-square-hmacsha256-signature' => ['forged']], $body))->toBeFalse()
        ->and(squareContractDriver()->validateWebhook($sign($old), $old))->toBeFalse()
        ->and(squareContractDriver()->validateWebhook($sign($body), $body))->toBeTrue();

    $square = array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[square]')));
    expect($square)->toContain(
        '[square] Webhook signature missing',
        '[square] Webhook signature key not configured',
        '[square] Webhook validation failed',
        '[square] Webhook timestamp validation failed - potential replay attack',
        '[square] Webhook validated successfully',
    )
        ->and(loggedEntry($logs, 'Webhook signature key not configured')['context']['hint'])->toContain('SQUARE_WEBHOOK_SIGNATURE_KEY')
        ->and(loggedEntry($logs, 'Webhook validation failed')['context']['hint'])->toContain('SQUARE_WEBHOOK_URL');
});

test('the health check is up on a client error, and down on no connection or anything else, and logs which', function () {
    $logs = captureLogs();
    $request = new Request('GET', '/v2/locations');

    expect(squareContractDriver([new Response(200, [], '{}')])->healthCheck())->toBeTrue()
        ->and(squareContractDriver([squareClientError(401)])->healthCheck())->toBeTrue()
        ->and(squareContractDriver([new ConnectException('refused', $request)])->healthCheck())->toBeFalse()
        ->and(squareContractDriver([new ServerException('Service Unavailable', $request, new Response(503))])->healthCheck())->toBeFalse();

    $failed = array_values(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Health check failed')));
    expect(loggedEntry($logs, 'expected client-error response')['context']['error'])->toBeString()->not->toBeEmpty()
        ->and(array_keys($failed[0]['context']))->toBe(['error'])
        ->and(array_keys($failed[1]['context']))->toBe(['error', 'error_class'])
        ->and($failed[1]['context']['error_class'])->toBe(ChargeException::class);
});

test('a charge\'s round trip to Square is recorded on its own timeline', function () {
    config(['payments.features.trace' => true, 'payments.trace.async' => false]);
    app()->forgetInstance('payments.config');

    squareContractDriver([squareLinkCreated()])->charge(squareCharge());

    expect(KenDeNigerian\PayZephyr\Models\PaymentTraceEvent::where('reference', 'SQUARE_1')->count())->toBe(2);
});
