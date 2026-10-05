<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/*
 * What MonnifyDriver sends to Monnify, what it makes of the answers, and what
 * it logs - including the access token it logs in for and reuses.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function monnifyContractDriver(array $responses, array &$history = []): MonnifyDriver
{
    $driver = new MonnifyDriver(['api_key' => 'MK_TEST', 'secret_key' => 'SK_TEST', 'contract_code' => 'CC_1', 'currencies' => ['NGN']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function monnifyLogin(?int $expiresIn = 3600, string $token = 'tok-1'): Response
{
    return new Response(200, [], (string) json_encode(['requestSuccessful' => true, 'responseBody' => array_filter(['accessToken' => $token, 'expiresIn' => $expiresIn], fn ($v) => $v !== null)]));
}

function monnifyInitialized(): Response
{
    return new Response(200, [], '{"requestSuccessful":true,"responseBody":{"checkoutUrl":"https://sandbox.monnify.com/checkout/x","transactionReference":"MNFY|1"}}');
}

function monnifyCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 150.5, 'currency' => 'NGN', 'email' => 'a@b.com', 'reference' => 'MON_1', 'callbackUrl' => 'https://shop.test/return']);
}

afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// Configuration and authentication
// ---------------------------------------------------------------------------

test('a Monnify driver needs its API key, secret key and contract code', function (array $config) {
    expect(fn () => new MonnifyDriver($config + ['currencies' => ['NGN']]))->toThrow(InvalidConfigurationException::class);
})->with([
    'no API key' => [['secret_key' => 'SK', 'contract_code' => 'CC']],
    'no secret key' => [['api_key' => 'MK', 'contract_code' => 'CC']],
    'no contract code' => [['api_key' => 'MK', 'secret_key' => 'SK']],
]);

test('Monnify is logged in to with the API key and secret, and requests then carry the token as JSON', function () {
    $history = [];
    monnifyContractDriver([monnifyLogin(), monnifyInitialized()], $history)->charge(monnifyCharge());

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('MK_TEST:SK_TEST'))
        ->and((string) $history[0]['request']->getUri())->toEndWith('/api/v1/auth/login')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer tok-1')
        ->and($history[1]['request']->getHeaderLine('Content-Type'))->toBe('application/json');
});

test('the token is reused until a minute before it expires, then fetched again', function (?int $expiresIn, int $lifetime) {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $history = [];
    $driver = monnifyContractDriver([monnifyLogin($expiresIn), monnifyInitialized(), monnifyInitialized(), monnifyLogin($expiresIn, 'tok-2'), monnifyInitialized()], $history);

    $driver->charge(monnifyCharge());
    Carbon::setTestNow(now()->addSeconds($lifetime - 1));
    $driver->charge(monnifyCharge());
    Carbon::setTestNow(now()->addSecond());
    $driver->charge(monnifyCharge());

    $logins = array_values(array_filter($history, fn (array $h): bool => str_ends_with((string) $h['request']->getUri(), '/auth/login')));
    expect($logins)->toHaveCount(2)
        ->and(end($history)['request']->getHeaderLine('Authorization'))->toBe('Bearer tok-2');
})->with([
    'as Monnify says' => [120, 60],
    'an hour when it does not say' => [null, 3540],
]);

test('a login Monnify does not confirm, or that brings no token, fails the charge and is logged', function (string $body, string $reason) {
    $logs = captureLogs();

    try {
        monnifyContractDriver([new Response(200, [], $body)])->charge(monnifyCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Monnify authentication failed: '.$reason)
            ->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Monnify authentication failed')['context'])->toBe(['error' => $reason, 'error_class' => ChargeException::class]);
})->with([
    'not successful' => ['{"requestSuccessful":false}', 'Failed to authenticate with Monnify'],
    'no flag' => ['{"responseBody":{"accessToken":"tok"}}', 'Failed to authenticate with Monnify'],
    'empty token' => ['{"requestSuccessful":true,"responseBody":{"accessToken":""}}', 'Monnify reported a successful login but returned no access token'],
]);

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Monnify its full payload', function () {
    $history = [];
    monnifyContractDriver([monnifyLogin(), monnifyInitialized()], $history)->charge(monnifyCharge([
        'description' => 'Order 9', 'customer' => ['name' => 'Ada'], 'metadata' => ['order' => 9], 'channels' => ['card'],
    ]));

    expect(json_decode((string) $history[1]['request']->getBody(), true))->toBe([
        'amount' => 150.5,
        'customerName' => 'Ada',
        'customerEmail' => 'a@b.com',
        'paymentReference' => 'MON_1',
        'paymentDescription' => 'Order 9',
        'currencyCode' => 'NGN',
        'contractCode' => 'CC_1',
        'redirectUrl' => 'https://shop.test/return?reference=MON_1',
        'metadata' => ['order' => 9],
        'paymentMethods' => ['CARD'],
    ])->and((string) $history[1]['request']->getUri())->toEndWith('/api/v1/merchant/transactions/init-transaction');
});

test('a charge without a name or description uses Monnify\'s defaults, and sends no methods it was not given', function () {
    $history = [];
    monnifyContractDriver([monnifyLogin(), monnifyInitialized()], $history)->charge(monnifyCharge());

    $sent = json_decode((string) $history[1]['request']->getBody(), true);
    expect($sent['customerName'])->toBe('Customer')
        ->and($sent['paymentDescription'])->toBe('Payment')
        ->and($sent)->not->toHaveKey('paymentMethods');
});

test('an initialized charge is logged with its reference, and one Monnify refuses carries its message', function () {
    $logs = captureLogs();
    monnifyContractDriver([monnifyLogin(), monnifyInitialized()])->charge(monnifyCharge());

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'MON_1'])
        ->and(fn () => monnifyContractDriver([monnifyLogin(), new Response(200, [], '{"responseMessage":"Invalid contract"}')])->charge(monnifyCharge()))
        ->toThrow(ChargeException::class, 'Invalid contract');
});

test('an unexpected failure inside a charge is logged and wrapped, coded 0, and leaves no key behind', function () {
    $logs = captureLogs();
    $history = [];
    $driver = monnifyContractDriver([monnifyLogin(), fn () => throw new LogicException('handler blew up'), new Response(200, [], '{}')], $history);

    try {
        $driver->charge(monnifyCharge(['idempotencyKey' => 'idem-1']));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Monnify charge failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    try {
        $driver->verify('MON_AFTER');
    } catch (Throwable) {
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up'])
        ->and($history[0]['request']->getHeaderLine('Idempotency-Key'))->toBe('idem-1')
        ->and(end($history)['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a payment is verified by its reference, without a query string a redirect added, and read in full', function () {
    $history = [];
    $driver = monnifyContractDriver([monnifyLogin(), new Response(200, [], (string) json_encode(['requestSuccessful' => true, 'responseBody' => [
        'paymentStatus' => 'PAID', 'amountPaid' => 250, 'currency' => 'NGN', 'paidOn' => '2026-10-01 10:00:00', 'metaData' => ['order' => 1],
        'paymentMethod' => 'CARD', 'customer' => ['email' => 'a@b.com', 'name' => 'Ada'],
    ]]))], $history);

    $result = $driver->verify('MON V?reference=MON V');

    expect((string) $history[1]['request']->getUri())->toEndWith('/api/v2/merchant/transactions/query?paymentReference=MON%20V')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer tok-1')
        ->and($result->reference)->toBe('MON V?reference=MON V')
        ->and($result->status)->toBe('success')
        ->and($result->metadata)->toBe(['order' => 1])
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada']);
});

test('a verified payment carries the reference Monnify reports when it reports one', function () {
    $driver = monnifyContractDriver([monnifyLogin(), new Response(200, [], (string) json_encode(['requestSuccessful' => true, 'responseBody' => [
        'paymentReference' => 'MON_CANONICAL', 'paymentStatus' => 'PAID', 'amountPaid' => 1, 'currencyCode' => 'NGN',
    ]]))]);

    expect($driver->verify('MON_CANONICAL?from=redirect')->reference)->toBe('MON_CANONICAL');
});

test('a verification Monnify does not confirm is refused with its message', function (string $body) {
    expect(fn () => monnifyContractDriver([monnifyLogin(), new Response(200, [], $body)])->verify('MON_X'))
        ->toThrow(VerificationException::class, 'Transaction not found');
})->with([
    'not successful' => ['{"requestSuccessful":false,"responseMessage":"Transaction not found"}'],
    'no flag' => ['{"responseMessage":"Transaction not found"}'],
]);

test('a verification that fails on the way is logged and wrapped, coded 0', function () {
    $logs = captureLogs();

    try {
        monnifyContractDriver([monnifyLogin(), fn () => throw new LogicException('handler blew up')])->verify('MON_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'MON_B', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks and health
// ---------------------------------------------------------------------------

test('the monnify-signature header is read in either case', function (string $header) {
    $body = '{"eventType":"SUCCESSFUL_TRANSACTION"}';

    expect(monnifyContractDriver([])->validateWebhook([$header => [hash_hmac('sha512', $body, 'SK_TEST')]], $body))->toBeTrue();
})->with(['monnify-signature', 'Monnify-Signature']);

test('the health check is up when Monnify answers a login, even with a client error, and logs anything else', function () {
    $logs = captureLogs();
    $clientError = new ClientException('Unauthorized', new Request('POST', '/api/v1/auth/login'), new Response(401));

    expect(monnifyContractDriver([monnifyLogin()])->healthCheck())->toBeTrue()
        ->and(monnifyContractDriver([$clientError])->healthCheck())->toBeTrue()
        ->and(monnifyContractDriver([fn () => throw new LogicException('handler blew up')])->healthCheck())->toBeFalse()
        ->and(loggedEntry($logs, 'Health check failed')['context'])->toBe([
            'error' => 'Monnify authentication failed: handler blew up', 'error_class' => ChargeException::class,
        ]);
});
