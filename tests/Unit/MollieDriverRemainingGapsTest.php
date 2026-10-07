<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

/**
 * Closes the remaining coverage gap in MollieDriver::charge() that is not
 * exercised by MollieDriverCoverageTest, MollieDriverEdgeCasesTest,
 * MollieDriverTest, or MollieIntegrationTest.
 *
 * Note on genuinely dead code found while auditing this file: the
 * `catch (ClientException $e)` block inside
 * MollieDriver::validateWebhookViaAPI() (around lines 357-368) can never
 * execute. It wraps a call to $this->makeRequest(), and
 * AbstractDriver::makeRequest() unconditionally catches every
 * GuzzleException - which ClientException implements - and rethrows it as a
 * ChargeException(message, 0, $originalException) before it can escape to
 * the caller (see AbstractDriver.php lines 147-164). So a raw ClientException
 * is structurally never observable at that catch site; the outer
 * `catch (Throwable $e)` (lines 369-376) is what actually handles it, which
 * is why the existing tests that seem to target this branch
 * ("webhook validation handles 4xx errors gracefully" in
 * MollieDriverCoverageTest, "rejects webhook when payment not found in API"
 * and "webhook validation handles ClientException with response" in
 * MollieDriverTest) still pass - they just take the generic Throwable path
 * instead, with an identical observable result (false). This was left
 * untouched per instructions: src/ is not to be modified for this task.
 */
test('mollie charge names the field when a response carries no status', function (): void {
    // The response has a checkout URL but no status. That used to reach
    // normalizeStatus() as a null and come back as a TypeError wrapped in
    // "Payment initialization failed"; it now says which field is missing.
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_no_status',
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/tr_no_status',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver([
        'api_key' => 'test_mollie_api_key',
        'currencies' => ['EUR'],
    ]);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $driver->charge($request);
})->throws(ChargeException::class, '[mollie] omitted the required field [status] from its charge response');

test('mollie charge wraps a failure that is not an http error in a charge exception', function (): void {
    // makeRequest() converts Guzzle's exceptions. Anything else thrown beneath
    // the call - a handler, a middleware, a stream - is not one of those, and
    // must still reach the caller as the driver's own exception type.
    $driver = new MollieDriver(['api_key' => 'test_mollie_api_key', 'currencies' => ['EUR']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new RuntimeException('stream wrapper failed'),
    ]))]));

    try {
        $driver->charge(new ChargeRequestDTO(
            amount: 10.00,
            currency: 'EUR',
            email: 'test@example.com',
            callbackUrl: 'https://example.com/callback',
        ));
        $this->fail('Expected a ChargeException');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: stream wrapper failed')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});
