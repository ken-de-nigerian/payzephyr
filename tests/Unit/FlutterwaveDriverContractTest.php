<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/*
 * What FlutterwaveDriver sends to Flutterwave, what it makes of the answers,
 * and what it logs on the way.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function flutterwaveContractDriver(array $responses, array &$history = [], array $config = []): FlutterwaveDriver
{
    $driver = new FlutterwaveDriver($config + ['secret_key' => 'FLWSECK_TEST-1', 'webhook_secret' => 'hash-1', 'currencies' => ['NGN']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function flutterwaveLink(): Response
{
    return new Response(200, [], '{"status":"success","data":{"link":"https://checkout.flutterwave.com/x"}}');
}

function flutterwaveCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + [
        'amount' => 150.5, 'currency' => 'NGN', 'email' => 'a@b.com', 'reference' => 'FLW_1', 'callbackUrl' => 'https://shop.test/return',
    ]);
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Flutterwave its full payload, authenticated, as JSON', function () {
    $history = [];
    $driver = flutterwaveContractDriver([flutterwaveLink()], $history);

    $driver->charge(flutterwaveCharge([
        'description' => 'Order 9', 'customer' => ['name' => 'Ada'], 'metadata' => ['order' => 9], 'channels' => ['card'], 'idempotencyKey' => 'idem-1',
    ]));

    $request = $history[0]['request'];
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'tx_ref' => 'FLW_1',
        'amount' => 150.5,
        'currency' => 'NGN',
        'redirect_url' => 'https://shop.test/return?reference=FLW_1',
        'customer' => ['email' => 'a@b.com', 'name' => 'Ada'],
        'customizations' => ['title' => 'Order 9', 'description' => 'Order 9'],
        'meta' => ['order' => 9],
        'payment_options' => ['card'],
    ])
        ->and((string) $request->getUri())->toEndWith('payments')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer FLWSECK_TEST-1')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-1');
});

test('a charge without a name or description says so in Flutterwave\'s own words', function () {
    $history = [];
    flutterwaveContractDriver([flutterwaveLink()], $history)->charge(flutterwaveCharge());

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['customer']['name'])->toBe('Customer')
        ->and($sent['customizations'])->toBe(['title' => 'Payment', 'description' => 'Payment for services'])
        ->and($sent)->not->toHaveKey('payment_options');
});

test('a charge without a callback URL is refused, saying how to set one', function () {
    expect(fn () => flutterwaveContractDriver([])->charge(flutterwaveCharge(['callbackUrl' => null])))->toThrow(
        InvalidConfigurationException::class,
        'Flutterwave requires a callback URL for its redirect flow. Please use ->callback() in your payment chain to set the callback URL.'
    );
});

test('an initialized charge is logged with its reference and whether it was idempotent', function (?string $key, bool $idempotent) {
    $logs = captureLogs();

    flutterwaveContractDriver([flutterwaveLink()])->charge(flutterwaveCharge(['idempotencyKey' => $key]));

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'FLW_1', 'idempotent' => $idempotent]);
})->with([
    'with a key' => ['idem-2', true],
    'without' => [null, false],
]);

test('a charge Flutterwave does not answer with success is refused with its message', function (string $body) {
    expect(fn () => flutterwaveContractDriver([new Response(200, [], $body)])->charge(flutterwaveCharge()))
        ->toThrow(ChargeException::class, 'Invalid amount');
})->with([
    'error' => ['{"status":"error","message":"Invalid amount"}'],
    'no status' => ['{"message":"Invalid amount"}'],
]);

test('an unexpected failure inside a charge is logged and wrapped, coded 0, and leaves no key behind', function () {
    $logs = captureLogs();
    $history = [];
    $driver = flutterwaveContractDriver([fn () => throw new LogicException('handler blew up'), new Response(200, [], '{}')], $history);

    try {
        $driver->charge(flutterwaveCharge(['idempotencyKey' => 'idem-3']));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    try {
        $driver->verify('FLW_AFTER');
    } catch (Throwable) {
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class])
        // The failed request never completed, so the verify is the only one recorded.
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a payment is verified by its reference and read in full', function () {
    $logs = captureLogs();
    $history = [];
    $driver = flutterwaveContractDriver([new Response(200, [], (string) json_encode(['status' => 'success', 'data' => [
        'tx_ref' => 'FLW_V', 'status' => 'successful', 'amount' => 250, 'currency' => 'NGN', 'created_at' => '2026-10-01T10:00:00Z',
        'meta' => ['order' => 1], 'payment_type' => 'card', 'card' => ['type' => 'VISA', 'issuer' => 'Test Bank'], 'customer' => ['email' => 'a@b.com', 'name' => 'Ada'],
    ]]))], $history);

    $result = $driver->verify('FLW_V');

    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query)->toBe(['tx_ref' => 'FLW_V'])
        ->and($result->status)->toBe('success')
        ->and($result->metadata)->toBe(['order' => 1])
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada'])
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'FLW_V', 'status' => 'successful']);
});

test('a verification Flutterwave does not answer with success is refused with its message', function (string $body) {
    expect(fn () => flutterwaveContractDriver([new Response(200, [], $body)])->verify('FLW_X'))
        ->toThrow(VerificationException::class, 'No transaction found');
})->with([
    'error' => ['{"status":"error","message":"No transaction found"}'],
    'no status' => ['{"message":"No transaction found"}'],
]);

test('a verification that fails on the way is logged and wrapped, coded 0', function () {
    $logs = captureLogs();

    try {
        flutterwaveContractDriver([fn () => throw new LogicException('handler blew up')])->verify('FLW_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'FLW_B', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks, health, extraction
// ---------------------------------------------------------------------------

test('the verif-hash header is read in either case', function (string $header) {
    expect(flutterwaveContractDriver([])->validateWebhook([$header => ['hash-1']], '{}'))->toBeTrue();
})->with(['verif-hash', 'Verif-Hash']);

test('each webhook outcome is logged with what an operator needs', function () {
    $logs = captureLogs();
    $driver = flutterwaveContractDriver([]);

    $driver->validateWebhook(['content-type' => ['application/json']], '{}');
    $driver->validateWebhook(['verif-hash' => ['wrong']], '{}');
    $driver->validateWebhook(['verif-hash' => ['hash-1']], '{}');
    flutterwaveContractDriver([], config: ['webhook_secret' => null])->validateWebhook(['verif-hash' => ['x']], '{}');

    expect(loggedEntry($logs, 'verif-hash) missing')['context'])->toBe([
        'available_headers' => ['content-type'],
        'hint' => 'Flutterwave webhooks must include the "verif-hash" header',
    ])
        ->and(loggedEntry($logs, 'Webhook validation failed')['context'])->toMatchArray([
            'reason' => 'Secret hash mismatch', 'received_hash_length' => 5, 'expected_hash_length' => 6,
        ])
        ->and(loggedEntry($logs, 'Webhook validation failed')['context']['hint'])->toContain('FLUTTERWAVE_WEBHOOK_SECRET')
        ->and(loggedEntry($logs, 'no Secret Hash configured')['context']['hint'])->toContain('FLUTTERWAVE_WEBHOOK_SECRET')
        ->and(loggedEntry($logs, 'Webhook validated successfully')['level'])->toBe('info');
});

test('the health check counts the 400 and 404 Flutterwave answers with as healthy, and says so', function (int $status) {
    $logs = captureLogs();
    $driver = flutterwaveContractDriver([new ClientException('Client error', new Request('GET', 'banks/NG'), new Response($status))]);

    expect($driver->healthCheck())->toBeTrue()
        ->and(loggedEntry($logs, 'Health check successful')['level'])->toBe('info');
})->with([400, 404]);

test('the health check counts a client error it did not wrap itself as down', function () {
    $cause = new ClientException('Client error', new Request('GET', 'banks/NG'), new Response(400));

    expect(flutterwaveContractDriver([fn () => throw new RuntimeException('not ours', 0, $cause)])->healthCheck())->toBeFalse();
});

test('a webhook\'s status and channel are read from its data', function () {
    $driver = flutterwaveContractDriver([]);

    expect($driver->extractWebhookStatus(['data' => ['status' => 'successful']]))->toBe('successful')
        ->and($driver->extractWebhookStatus(['data' => []]))->toBe('unknown')
        ->and($driver->extractWebhookChannel(['data' => ['payment_type' => 'card']]))->toBe('card')
        ->and($driver->extractWebhookChannel(['data' => []]))->toBeNull();
});
