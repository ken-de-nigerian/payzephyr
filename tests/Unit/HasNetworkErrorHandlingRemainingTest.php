<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

test('it handles a generic transfer error that is not a connect/server/request exception', function () {
    // GuzzleHttp\Exception\TransferException is the concrete base class of
    // ConnectException/RequestException/ServerException. Throwing it
    // directly (rather than a subclass) is the only way to reach the final
    // "elseif ($exception instanceof TransferException)" branch in
    // HasNetworkErrorHandling::handleNetworkError(), since every more
    // specific Guzzle exception is caught by an earlier branch first.
    $mock = new MockHandler([
        new TransferException('Generic transfer failure'),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    try {
        $driver->charge($request);
        expect(false)->toBeTrue(); // Should not reach here.
    } catch (ChargeException $e) {
        // getNetworkErrorMessage() also falls through to its generic
        // message for a bare TransferException, since none of the
        // ConnectException/ServerException/RequestException checks match.
        expect($e->getMessage())->toContain('Network error occurred while processing payment');
    }
});
