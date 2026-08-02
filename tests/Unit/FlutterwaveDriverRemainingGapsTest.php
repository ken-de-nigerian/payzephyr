<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function flutterwaveGapsDriverWithMock(array $responses, array $configOverrides = []): FlutterwaveDriver
{
    $config = array_merge([
        'secret_key' => 'FLWSECK_TEST-xxx',
        'base_url' => 'https://api.flutterwave.com/v3',
        'currencies' => ['NGN', 'USD', 'GHS', 'KES', 'UGX', 'ZAR'],
        'callback_url' => 'https://example.com/webhook',
    ], $configOverrides);

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new FlutterwaveDriver($config);
    $driver->setClient($client);

    return $driver;
}

// Covers the "$data['status'] !== 'success'" branch inside charge() itself
// (lines ~105-108), reached when Flutterwave replies with HTTP 200 but an
// error status in the JSON body. The existing "charge throws exception on
// error" test uses an HTTP 400 (which Guzzle turns into a ClientException
// caught by the generic Throwable handler) and an invalid DTO currency that
// never even reaches charge(), so this specific branch was never exercised.
test('flutterwave charge throws ChargeException when body status is not success despite 200 response', function () {
    $driver = flutterwaveGapsDriverWithMock([
        new Response(200, [], json_encode([
            'status' => 'error',
            'message' => 'Insufficient funds',
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))
        ->toThrow(ChargeException::class, 'Insufficient funds');
});

// Covers the generic catch (Throwable $e) branch of charge() (lines ~128-133),
// which only triggers for exceptions that are neither ChargeException nor
// GuzzleException (those are already wrapped/rethrown by makeRequest()).
test('flutterwave charge wraps unexpected non-guzzle exceptions from the http client', function () {
    $config = [
        'secret_key' => 'FLWSECK_TEST-xxx',
        'currencies' => ['NGN'],
        'callback_url' => 'https://example.com/webhook',
    ];

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new RuntimeException('Unexpected serialization failure'));

    $driver = new FlutterwaveDriver($config);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))
        ->toThrow(ChargeException::class, 'Payment initialization failed: Unexpected serialization failure');
});

// Covers the "$data['status'] !== 'success'" branch inside verify() itself
// (lines ~156-159) plus the immediate `catch (VerificationException $e) {
// throw $e; }` rethrow (line ~186). The existing "verify handles not found"
// test uses an HTTP 404, which Guzzle turns into a ClientException that gets
// wrapped by makeRequest() into a ChargeException and then re-wrapped by the
// generic Throwable catch in verify() - a different code path entirely.
test('flutterwave verify throws VerificationException when body status is not success despite 200 response', function () {
    $driver = flutterwaveGapsDriverWithMock([
        new Response(200, [], json_encode([
            'status' => 'error',
            'message' => 'Transaction could not be verified',
        ])),
    ]);

    expect(fn () => $driver->verify('fw_gap_ref'))
        ->toThrow(VerificationException::class, 'Transaction could not be verified');
});

// Covers the "missing verif-hash header" branch of validateWebhook() (lines ~211-218).
test('flutterwave driver rejects webhook when verif-hash header is missing', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'test_secret',
        'webhook_secret' => 'webhook_secret',
    ]);

    $result = $driver->validateWebhook([], json_encode(['event' => 'charge.completed']));

    expect($result)->toBeFalse();
});

// Covers the "webhook secret hash not configured" branch of validateWebhook()
// (lines ~222-228). Note that secret_key is mandatory (validateConfig()
// rejects an empty one), so the only way to make `$secretHash` empty is for
// webhook_secret to be explicitly set to an empty string - the `??` operator
// only falls through on null, so '' short-circuits before the secret_key
// fallback is ever consulted.
test('flutterwave driver rejects webhook when configured secret hash is empty', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'test_secret',
        'webhook_secret' => '',
    ]);

    $headers = ['verif-hash' => ['anything']];
    $body = json_encode(['event' => 'charge.completed']);

    $result = $driver->validateWebhook($headers, $body);

    expect($result)->toBeFalse();
});
