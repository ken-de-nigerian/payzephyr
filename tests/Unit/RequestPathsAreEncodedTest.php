<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;

/**
 * An identifier is data, and never part of a URL's structure.
 *
 * Applications routinely hand `verify()` whatever arrived in the callback
 * query string, so these values are attacker-influenced. Interpolated straight
 * into a path, a reference of `../methods` or `tr_1/refunds` redirects the call
 * to a different endpoint on the merchant's own account, and one containing `&`
 * appends a parameter to a query string.
 *
 * Only Paddle and Razorpay - the two most recently contributed drivers - were
 * encoding. These tests cover the rest, and assert on the URL that actually
 * left the client rather than on the call succeeding.
 */
function capturingDriver(AbstractDriver $driver, array &$sent, int $responses = 3): AbstractDriver
{
    $queue = array_fill(0, $responses, new Response(200, [], (string) json_encode([
        'status' => true, 'data' => ['id' => 'x', 'status' => 'paid', 'amount' => ['value' => '1.00', 'currency' => 'EUR']],
    ])));

    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(function (callable $handler) use (&$sent) {
        return function ($request, $options) use ($handler, &$sent) {
            $sent[] = (string) $request->getUri();

            return $handler($request, $options);
        };
    });

    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

test('a traversal attempt in a reference is encoded, not treated as path structure', function (
    AbstractDriver $driver,
    string $expectedFragment,
) {
    $sent = [];
    capturingDriver($driver, $sent);

    try {
        $driver->verify('../../v2/methods');
    } catch (Throwable) {
        // The response shape will not match; the URL is what is under test.
    }

    expect($sent)->not->toBeEmpty();

    $url = $sent[0];

    expect($url)->toContain($expectedFragment)
        // The traversal is inert: encoded, so the provider reads it as one
        // opaque identifier rather than as directories to climb.
        ->and($url)->toContain('..%2F..%2Fv2%2Fmethods')
        ->and($url)->not->toContain('../');
})->with([
    'paystack' => [
        fn () => new PaystackDriver(['secret_key' => 'sk_test_x', 'base_url' => 'https://api.paystack.co', 'currencies' => ['NGN']]),
        '/transaction/verify/',
    ],
    'mollie' => [
        fn () => new MollieDriver(['api_key' => 'test_x', 'base_url' => 'https://api.mollie.com', 'currencies' => ['EUR']]),
        '/v2/payments/',
    ],
    'square' => [
        fn () => new SquareDriver(['access_token' => 'tok', 'location_id' => 'L1', 'base_url' => 'https://connect.squareup.com', 'currencies' => ['USD']]),
        '/v2/online-checkout/payment-links/',
    ],
]);

test('a reference cannot smuggle an extra query parameter into a lookup', function () {
    // Monnify passes the reference as a query value. It strips anything after
    // a `?`, which never stopped `&` - so `X&limit=100` used to arrive as a
    // second parameter rather than as part of the reference.
    $sent = [];

    $driver = new MonnifyDriver([
        'api_key' => 'MK_TEST_x', 'secret_key' => 'SK_TEST_x', 'contract_code' => 'C1',
        'base_url' => 'https://sandbox.monnify.com', 'currencies' => ['NGN'],
    ]);

    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 't', 'expiresIn' => 3600],
        ])),
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'transactionReference' => 'ref_1', 'paymentStatus' => 'PAID',
                'amountPaid' => 1000, 'currencyCode' => 'NGN',
            ],
        ])),
    ]));
    $stack->push(function (callable $handler) use (&$sent) {
        return function ($request, $options) use ($handler, &$sent) {
            $sent[] = (string) $request->getUri();

            return $handler($request, $options);
        };
    });
    $driver->setClient(new Client(['handler' => $stack]));

    try {
        $driver->verify('ref_1&limit=100');
    } catch (Throwable) {
    }

    $lookup = end($sent);

    expect($lookup)->toContain('paymentReference=ref_1%26limit%3D100')
        ->and($lookup)->not->toContain('&limit=100');
});

test('an ordinary reference is not mangled on the way out', function () {
    // The guard must not break the common case, and must not double-encode a
    // reference that is already URL-safe.
    $sent = [];
    $driver = capturingDriver(
        new PaystackDriver(['secret_key' => 'sk_test_x', 'base_url' => 'https://api.paystack.co', 'currencies' => ['NGN']]),
        $sent,
    );

    try {
        $driver->verify('PZ_1758000000_ab12cd');
    } catch (Throwable) {
    }

    expect($sent[0])->toEndWith('/transaction/verify/PZ_1758000000_ab12cd')
        ->and($sent[0])->not->toContain('%');
});

test('every interpolated request path in every driver is encoded', function () {
    // The sweep covered 45 sites across 16 files. This is what stops the
    // forty-sixth from arriving unencoded: a raw "..." URL containing a
    // variable is a build failure, whichever driver adds it.
    $offenders = [];

    foreach (['src/Drivers', 'src/Traits'] as $directory) {
        foreach (glob(dirname(__DIR__, 2).'/'.$directory.'/*.php') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (! str_contains($line, 'makeRequest(')) {
                    continue;
                }

                // A double-quoted URL argument containing a variable is an
                // interpolated path; the encoded form uses concatenation.
                if (preg_match('/makeRequest\(\s*\'[A-Z]+\'\s*,\s*"[^"]*\$/', $line)) {
                    $offenders[] = basename($file).':'.($number + 1).' -> '.trim($line);
                }
            }
        }
    }

    expect($offenders)->toBe([], "Interpolate identifiers with rawurlencode() instead:\n  ".implode("\n  ", $offenders));
});
