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
test('mollie driver charge wraps unexpected non-charge errors in a charge exception', function () {
    // The response has a checkout URL (so the explicit "No checkout URL"
    // ChargeException is not triggered) but is missing 'status'.
    // normalizeStatus() has a `string $status` parameter and this file
    // declares strict_types=1, so passing the resulting null triggers a
    // TypeError - a Throwable that isn't already a ChargeException - which
    // must be caught by the generic catch (Throwable $e) block in charge()
    // rather than the earlier catch (ChargeException $e) rethrow.
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
})->throws(ChargeException::class, 'Payment initialization failed');
