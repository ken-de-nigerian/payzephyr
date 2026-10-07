<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

/*
 * What every driver inherits from AbstractDriver: how a request to the
 * provider is sent and recorded on the timeline, how a response that lacks
 * what PayZephyr needs is reported, and the small services drivers lean on.
 * PaystackDriver stands in for "a driver"; nothing here is Paystack's own.
 */

beforeEach(function (): void {
    config(['payments.features.trace' => true, 'payments.trace.async' => false, 'payments.trace.record_http_bodies' => true]);
    app()->forgetInstance('payments.config');
});

/**
 * @param  list<mixed>  $responses  Responses, exceptions, or callables for the mock handler
 * @param  array<int, array<string, mixed>>  $history
 */
function driverWith(array $responses, array $config = [], array &$history = []): PaystackDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $driver = new PaystackDriver($config + ['secret_key' => 'sk_test_xxx', 'base_url' => 'https://api.paystack.co/', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => $stack, 'base_uri' => array_key_exists('base_url', $config) ? (string) $config['base_url'] : 'https://api.paystack.co/']));
    $driver->setTraceContext('PZ_DRIVER');

    return $driver;
}

function callDriver(object $driver, string $method, mixed ...$args): mixed
{
    return (new ReflectionClass($driver))->getMethod($method)->invoke($driver, ...$args);
}

function driverTrace(TraceEvent $event): PaymentTraceEvent
{
    return PaymentTraceEvent::where('reference', 'PZ_DRIVER')->where('event', $event->value)->sole();
}

// ---------------------------------------------------------------------------
// Sending a request
// ---------------------------------------------------------------------------

test('a request carries the idempotency key unless the caller set the header itself', function (array $headers, string $expected): void {
    $history = [];
    $driver = driverWith([new Response(200, [], '{}')], history: $history);
    (new ReflectionClass($driver))->getProperty('currentRequest')->setValue($driver, new ChargeRequestDTO(
        amount: 10, currency: 'NGN', email: 'a@b.com', reference: 'PZ_DRIVER', idempotencyKey: 'idem-from-request',
    ));

    callDriver($driver, 'makeRequest', 'POST', '/transaction/initialize', ['headers' => $headers]);

    expect($history[0]['request']->getHeaderLine('Idempotency-Key'))->toBe($expected);
})->with([
    'not set' => [['X-Other' => '1'], 'idem-from-request'],
    'set by the caller' => [['Idempotency-Key' => 'idem-from-caller'], 'idem-from-caller'],
]);

test('a request and its response are recorded with their bodies, address and timing', function (): void {
    Carbon::setTestNow('2026-10-01 12:00:00.000');
    $driver = driverWith([function (): Response {
        Carbon::setTestNow(now()->addMilliseconds(250));

        return new Response(201, [], '{"status":true,"data":{"id":7}}');
    }]);

    $response = callDriver($driver, 'makeRequest', 'POST', 'transaction/initialize', ['form_params' => ['amount' => 500]]);

    $sent = driverTrace(TraceEvent::PROVIDER_REQUEST_SENT);
    $received = driverTrace(TraceEvent::PROVIDER_RESPONSE_RECEIVED);

    expect($sent->payload)->toEqual(['amount' => 500])
        ->and($sent->http_url)->toBe('https://api.paystack.co/transaction/initialize')
        ->and($received->payload)->toEqual(['status' => true, 'data' => ['id' => 7]])
        ->and($received->http_status_code)->toBe(201)
        ->and($received->response_time_ms)->toBe(250)
        // Peeking at the body for the timeline leaves it for the caller.
        ->and($response->getBody()->tell())->toBe(0);

    Carbon::setTestNow();
});

test('the recorded address is the base URL joined to the path, or the path when it is already absolute', function (?string $base, string $uri, string $expected): void {
    $driver = driverWith([new Response(200, [], '{}')], ['base_url' => $base]);

    callDriver($driver, 'makeRequest', 'GET', $uri);

    expect(driverTrace(TraceEvent::PROVIDER_REQUEST_SENT)->http_url)->toBe($expected);
})->with([
    'trailing and leading slashes' => ['https://api.example.test/v1/', '/charges', 'https://api.example.test/v1/charges'],
    'neither slash' => ['https://api.example.test/v1', 'charges', 'https://api.example.test/v1/charges'],
    'absolute https' => ['https://api.example.test/', 'https://other.example.test/x', 'https://other.example.test/x'],
    'absolute http' => ['https://api.example.test/', 'http://other.example.test/x', 'http://other.example.test/x'],
    'no base URL' => [null, 'charges', 'charges'],
]);

test('a failed request is recorded and logged by what failed, and raised with where it was going', function (Throwable $failure, TraceEvent $event, string $errorType): void {
    Carbon::setTestNow('2026-10-01 12:00:00.000');
    $logs = captureLogs();
    $driver = driverWith([function () use ($failure): void {
        Carbon::setTestNow(now()->addMilliseconds(40));

        throw $failure;
    }]);

    try {
        callDriver($driver, 'makeRequest', 'GET', '/bank');
        $thrown = null;
    } catch (ChargeException $e) {
        $thrown = $e;
    }

    $trace = driverTrace($event);

    expect($thrown?->getContext())->toBe(['method' => 'GET', 'uri' => '/bank', 'provider' => 'paystack'])
        ->and($trace->payload)->toEqual(['error' => $failure->getMessage(), 'error_class' => $failure::class])
        ->and($trace->response_time_ms)->toBe(40)
        ->and(loggedEntry($logs, 'Network error during GET request to /bank')['context']['error_type'])->toBe($errorType);

    Carbon::setTestNow();
})->with([
    'timed out, in any case' => [new ConnectException('Connection Timed Out after 30 seconds', new Request('GET', '/bank')), TraceEvent::PROVIDER_TIMEOUT, 'connection_error'],
    'refused' => [new ConnectException('Connection refused', new Request('GET', '/bank')), TraceEvent::PROVIDER_EXCEPTION, 'connection_error'],
    'answered with an error' => [new RequestException('Server error', new Request('GET', '/bank'), new Response(503)), TraceEvent::PROVIDER_ERROR, 'request_error'],
    'no answer' => [new RequestException('Stream closed', new Request('GET', '/bank')), TraceEvent::PROVIDER_EXCEPTION, 'request_error'],
    'transfer failure' => [new TransferException('Too many redirects'), TraceEvent::PROVIDER_EXCEPTION, 'transfer_error'],
]);

// ---------------------------------------------------------------------------
// Reading a response
// ---------------------------------------------------------------------------

test('a response missing a required field says which, and that the request may have gone through', function (): void {
    $driver = driverWith([]);

    expect(fn (): mixed => callDriver($driver, 'requireField', [], 'reference', 'verify'))->toThrow(
        ChargeException::class,
        '[paystack] omitted the required field [reference] from its verify response. The request may still have been accepted by the provider - verify before retrying.'
    );
});

test('a response missing an amount says it will not report zero', function (): void {
    $driver = driverWith([]);

    try {
        callDriver($driver, 'requireAmountValue', null, 'amount', 'verify');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('[paystack] omitted the amount [amount] from its verify response. Reporting this as a zero-value payment would be worse than failing.')
            ->and($e->getContext())->toBe(['provider' => 'paystack', 'field' => 'amount', 'operation' => 'verify']);

        return;
    }

    test()->fail('Expected a ChargeException.');
});

test('a response field of the wrong type is reported with the field and operation', function (string $method, array $data, string $message): void {
    $driver = driverWith([]);

    try {
        callDriver($driver, $method, $data, 'field', 'charge');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe($message)
            ->and($e->getContext())->toBe(['provider' => 'paystack', 'field' => 'field', 'operation' => 'charge']);

        return;
    }

    test()->fail('Expected a ChargeException.');
})->with([
    'string' => ['requireString', ['field' => ['x']], '[paystack] returned a non-string value for [field] in its charge response.'],
    'amount' => ['requireAmount', ['field' => 'ten'], '[paystack] returned a non-numeric amount for [field] in its charge response.'],
    'array' => ['requireArray', ['field' => 'x'], '[paystack] returned a non-array value for [field] in its charge response.'],
]);

test('a response body is decoded to the array it holds, and anything else to an empty one', function (string $body, array $expected): void {
    expect(callDriver(driverWith([]), 'parseResponse', new Response(200, [], $body)))->toBe($expected);
})->with([
    'object' => ['{"status":true,"data":{"id":1}}', ['status' => true, 'data' => ['id' => 1]]],
    'list' => ['[1,2]', [1, 2]],
    'bare string' => ['"ok"', []],
    'html' => ['<html>bad gateway</html>', []],
]);

// ---------------------------------------------------------------------------
// Small services
// ---------------------------------------------------------------------------

test('only the configured currencies that are strings are supported, as a list', function (): void {
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN', 5, null, 'USD']]);

    expect($driver->getSupportedCurrencies())->toBe(['NGN', 'USD']);
});

test('a generated reference is the prefix, the time and sixteen random hex digits', function (): void {
    Carbon::setTestNow('2026-10-01 12:00:00');

    expect(callDriver(driverWith([]), 'generateReference', 'PZ'))->toMatch('/^PZ_\d{10}_[0-9a-f]{16}$/');

    Carbon::setTestNow();
});

test('the health check is cached per provider, for as long as configured', function (?int $ttl, int $expectedTtl, bool $healthy): void {
    config(['payments.health_check.cache_ttl' => $ttl]);
    app()->forgetInstance('payments.config');

    Cache::shouldReceive('remember')->once()
        ->with('payments.health.paystack', $expectedTtl, Mockery::type(Closure::class))
        ->andReturn($healthy);

    expect(driverWith([])->getCachedHealthCheck())->toBe($healthy);
})->with([
    'configured, healthy' => [42, 42, true],
    'configured, down' => [42, 42, false],
    'default' => [null, PaymentConstants::HEALTH_CHECK_CACHE_TTL_SECONDS, true],
]);

test('a status normalizer or channel mapper set on the driver is the one it uses', function (): void {
    $driver = driverWith([]);
    $normalizer = (new StatusNormalizer)->registerProviderMappings('paystack', ['from-custom-normalizer' => ['PAID']]);
    $mapper = new ChannelMapper;

    $driver->setStatusNormalizer($normalizer)->setChannelMapper($mapper);

    expect(callDriver($driver, 'normalizeStatus', 'PAID'))->toBe('from-custom-normalizer')
        ->and(callDriver($driver, 'getStatusNormalizer'))->toBe($normalizer)
        ->and(callDriver($driver, 'getChannelMapper'))->toBe($mapper);
});

test('channels are not mapped for a provider that does not take them', function (): void {
    $driver = driverWith([]);
    (new ReflectionClass($driver))->getProperty('name')->setValue($driver, 'acme');

    expect(callDriver($driver, 'mapChannels', new ChargeRequestDTO(amount: 10, currency: 'NGN', email: 'a@b.com', channels: ['card'])))->toBeNull();
});

test('a required credential that is missing fails rather than reading as an empty string', function (): void {
    $driver = driverWith([]);
    (new ReflectionClass($driver))->getProperty('config')->setValue($driver, ['secret_key' => '']);

    expect(fn (): mixed => callDriver($driver, 'requiredCredential', 'secret_key'))
        ->toThrow(InvalidConfigurationException::class, '[paystack] secret_key is not configured.');
});
