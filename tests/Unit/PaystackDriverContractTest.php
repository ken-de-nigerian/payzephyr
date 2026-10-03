<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/*
 * What PaystackDriver sends to Paystack, what it makes of the answers, and
 * what it logs on the way - pinned exactly, because each of these is
 * something a merchant reconciling a payment, or debugging a failed one,
 * relies on.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function paystackContractDriver(array $responses, array &$history = []): PaystackDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function paystackInitialized(): Response
{
    return new Response(200, [], (string) json_encode(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'ac_1']]));
}

function sentJson(array $history, int $index = 0): array
{
    return json_decode((string) $history[$index]['request']->getBody(), true);
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Paystack the amount in kobo, the currency, the reference, the callback and the metadata', function () {
    $history = [];
    $driver = paystackContractDriver([paystackInitialized()], $history);

    $driver->charge(new ChargeRequestDTO(
        amount: 150.5, currency: 'NGN', email: 'a@b.com', reference: 'PZ_CHARGE_1',
        callbackUrl: 'https://shop.test/return', metadata: ['order' => 9], channels: ['card'],
    ));

    expect(sentJson($history))->toBe([
        'email' => 'a@b.com',
        'amount' => 15050,
        'currency' => 'NGN',
        'reference' => 'PZ_CHARGE_1',
        'callback_url' => 'https://shop.test/return',
        'metadata' => ['order' => 9],
        'channels' => ['card'],
    ]);
});

test('a charge leaves out what it was not given', function () {
    $history = [];
    $driver = paystackContractDriver([paystackInitialized()], $history);

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com', reference: 'PZ_CHARGE_2'));

    expect(sentJson($history))->toBe(['email' => 'a@b.com', 'amount' => 1000, 'currency' => 'NGN', 'reference' => 'PZ_CHARGE_2']);
});

test('an initialized charge is logged with its reference', function () {
    $logs = captureLogs();

    paystackContractDriver([paystackInitialized()])->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com', reference: 'PZ_CHARGE_3'));

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'PZ_CHARGE_3']);
});

test('a charge Paystack answers without a status is refused with its message', function () {
    $driver = paystackContractDriver([new Response(200, [], '{"message":"Invalid key"}')]);

    expect(fn () => $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com')))
        ->toThrow(ChargeException::class, 'Invalid key');
});

test('a charge that fails on the way, or comes back incomplete, is raised as a charge failure, coded 0', function () {
    $driver = paystackContractDriver([new ConnectException('Connection refused', new Request('POST', '/transaction/initialize'))]);

    try {
        $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com', idempotencyKey: 'idem-1'));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        // makeRequest() already wraps a network failure; charge() only wraps
        // what is not a ChargeException yet.
        expect($e->getCode())->toBe(0);
    }

    $requireFailure = paystackContractDriver([new Response(200, [], '{"status":true,"data":{}}')]);

    try {
        $requireFailure->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com'));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toStartWith('[paystack] omitted the required field [authorization_url]');
    }
});

test('an unexpected failure inside a charge is logged and wrapped, coded 0', function () {
    $logs = captureLogs();
    $driver = paystackContractDriver([function () {
        throw new LogicException('handler blew up');
    }]);

    try {
        $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com'));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')
            ->and($e->getCode())->toBe(0)
            ->and($e->getPrevious())->toBeInstanceOf(LogicException::class);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class]);
});

test('a charge does not leave its idempotency key on the driver for the next request', function () {
    $history = [];
    $driver = paystackContractDriver([paystackInitialized(), new Response(200, [], '{"status":true,"data":{}}')], $history);

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com', idempotencyKey: 'idem-charge'));

    try {
        $driver->verify('PZ_AFTER');
    } catch (Throwable) {
    }

    expect($history[0]['request']->getHeaderLine('Idempotency-Key'))->toBe('idem-charge')
        ->and($history[1]['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a verified payment is read in full and logged with its status', function () {
    $logs = captureLogs();
    $driver = paystackContractDriver([new Response(200, [], (string) json_encode(['status' => true, 'data' => [
        'reference' => 'PZ_V1', 'status' => 'success', 'amount' => 25000, 'currency' => 'NGN', 'paid_at' => '2026-10-01T10:00:00Z',
        'channel' => 'card', 'metadata' => ['order' => 1],
        'authorization' => ['card_type' => 'visa', 'bank' => 'Test Bank', 'authorization_code' => 'AUTH_1'],
        'customer' => ['email' => 'a@b.com', 'customer_code' => 'CUS_1'],
    ]]))]);

    $result = $driver->verify('PZ_V1');

    expect($result->amount)->toBe(250.0)
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'code' => 'CUS_1'])
        ->and($result->authorizationCode)->toBe('AUTH_1')
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'PZ_V1', 'status' => 'success']);
});

test('a verification Paystack answers without a status is refused with its message', function () {
    $driver = paystackContractDriver([new Response(200, [], '{"message":"Transaction reference not found"}')]);

    expect(fn () => $driver->verify('PZ_MISSING'))->toThrow(VerificationException::class, 'Transaction reference not found');
});

test('a verification that fails on the way is logged and wrapped, coded 0', function () {
    $logs = captureLogs();
    $driver = paystackContractDriver([function () {
        throw new LogicException('handler blew up');
    }]);

    try {
        $driver->verify('PZ_BROKEN');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')
            ->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'PZ_BROKEN', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks and health
// ---------------------------------------------------------------------------

test('each webhook signature outcome is logged', function (array $headers, bool $valid, string $message) {
    $logs = captureLogs();
    $body = '{"event":"charge.success"}';
    $headers = array_map(fn (string $value): array => [$value === 'VALID' ? hash_hmac('sha512', $body, 'sk_test_xxx') : $value], $headers);

    expect(paystackContractDriver([])->validateWebhook($headers, $body))->toBe($valid)
        ->and(loggedEntry($logs, $message)['level'])->toBe($valid ? 'info' : 'warning');
})->with([
    'missing' => [[], false, 'Webhook signature missing'],
    'invalid' => [['x-paystack-signature' => 'forged'], false, 'Webhook signature invalid'],
    'valid' => [['x-paystack-signature' => 'VALID'], true, 'Webhook validated successfully'],
]);

test('the health check counts the 400 and 404 Paystack answers a bogus reference with as healthy, and says so', function (int $status) {
    $logs = captureLogs();
    $request = new Request('GET', '/transaction/verify/invalid_ref_test');
    $driver = paystackContractDriver([new ClientException('Client error', $request, new Response($status))]);

    expect($driver->healthCheck())->toBeTrue()
        ->and(loggedEntry($logs, 'Health check successful')['context'])->toBe(['status_code' => $status]);
})->with([400, 404]);

test('the health check counts any other failure as down, and logs what it was', function () {
    $logs = captureLogs();
    $request = new Request('GET', '/transaction/verify/invalid_ref_test');
    $driver = paystackContractDriver([new ClientException('Unauthorized', $request, new Response(401))]);

    expect($driver->healthCheck())->toBeFalse();

    $context = loggedEntry($logs, 'Health check failed')['context'];
    expect($context['exception_class'])->toBe(ChargeException::class)
        ->and($context['previous_class'])->toBe(ClientException::class)
        ->and($context['error'])->toBeString()->not->toBeEmpty();
});

test('a health check failure with nothing behind it is logged without a previous class', function () {
    $logs = captureLogs();
    // Not a Guzzle exception, so makeRequest() lets it through unwrapped.
    $driver = paystackContractDriver([function () {
        throw new RuntimeException('no cause');
    }]);

    expect($driver->healthCheck())->toBeFalse()
        ->and(loggedEntry($logs, 'Health check failed')['context'])
        ->toBe(['error' => 'no cause', 'exception_class' => RuntimeException::class, 'previous_class' => null]);
});

test('a Paystack driver is refused without a secret key, and built with one', function () {
    expect(fn () => new PaystackDriver(['currencies' => ['NGN']]))
        ->toThrow(KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException::class)
        ->and(new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]))->toBeInstanceOf(PaystackDriver::class);
});

test('every request to Paystack is authenticated with the secret key, as JSON', function () {
    $history = [];
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $client = (new ReflectionClass($driver))->getProperty('client')->getValue($driver);
    $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{"status":true,"data":{}}')]));
    $handler->push(Middleware::history($history));
    // Keep the driver's own default headers, swapping only the transport.
    $driver->setClient(new Client(['handler' => $handler, 'headers' => $client->getConfig('headers')]));

    try {
        $driver->verify('PZ_HEADERS');
    } catch (Throwable) {
    }

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer sk_test_xxx')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json');
});
