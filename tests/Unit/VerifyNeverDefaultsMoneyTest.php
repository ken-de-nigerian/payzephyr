<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/**
 * No driver may report a verified payment whose amount or currency the
 * provider never sent.
 *
 * The require* helpers are unit-tested on their own elsewhere. That was not
 * enough: Flutterwave's and Mollie's verify() kept reading the amount with a
 * bare `(float) $result['amount']` long after the helpers existed, because no
 * test asked every driver the same question. This one does. On an install that
 * does not promote PHP warnings to exceptions, that cast turns a missing amount
 * into 0.0 - a *verified* payment worth nothing, reported as fact.
 *
 * Each case pairs a control - the complete response verifies - with the same
 * response minus one field, so a failure can only mean the guard fired on that
 * field, never that the fixture was malformed to begin with.
 */
function driverOverMockedTransport(AbstractDriver $driver, array $responses): AbstractDriver
{
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

    return $driver;
}

/**
 * @return array{0: AbstractDriver, 1: string}
 */
function verifyScenario(string $provider, ?string $omit = null): array
{
    $json = fn (array $body): Response => new Response(200, [], (string) json_encode($body));

    [$driver, $preamble, $body, $reference] = match ($provider) {
        'paystack' => [
            new PaystackDriver(['secret_key' => 'sk_test_x', 'base_url' => 'https://api.paystack.co', 'currencies' => ['NGN']]),
            [],
            ['status' => true, 'data' => [
                'reference' => 'ref_1', 'status' => 'success', 'amount' => 1000000, 'currency' => 'NGN',
            ]],
            'ref_1',
        ],
        'flutterwave' => [
            new FlutterwaveDriver(['secret_key' => 'FLWSECK_TEST-x', 'base_url' => 'https://api.flutterwave.com/v3', 'currencies' => ['NGN']]),
            [],
            ['status' => 'success', 'data' => [
                'tx_ref' => 'ref_1', 'status' => 'successful', 'amount' => 15000, 'currency' => 'NGN',
            ]],
            'ref_1',
        ],
        'monnify' => [
            new MonnifyDriver(['api_key' => 'MK_TEST_x', 'secret_key' => 'SK_TEST_x', 'contract_code' => 'C1', 'base_url' => 'https://sandbox.monnify.com', 'currencies' => ['NGN']]),
            [$json(['requestSuccessful' => true, 'responseBody' => ['accessToken' => 't', 'expiresIn' => 3600]])],
            ['requestSuccessful' => true, 'responseBody' => [
                'transactionReference' => 'ref_1', 'paymentStatus' => 'PAID', 'amountPaid' => 20000, 'currencyCode' => 'NGN',
            ]],
            'ref_1',
        ],
        'opay' => [
            new OPayDriver(['merchant_id' => 'M1', 'public_key' => 'PK', 'secret_key' => 'SK', 'base_url' => 'https://liveapi.opaycheckout.com', 'currencies' => ['NGN']]),
            [],
            ['code' => '00000', 'message' => 'Success', 'data' => [
                'reference' => 'ref_1', 'status' => 'SUCCESS', 'amount' => ['total' => 20000, 'currency' => 'NGN'],
            ]],
            'ref_1',
        ],
        'paypal' => [
            new PayPalDriver(['client_id' => 'C', 'client_secret' => 'S', 'mode' => 'sandbox', 'currencies' => ['USD']]),
            [$json(['access_token' => 't', 'expires_in' => 32400])],
            ['id' => 'ORDER_1', 'status' => 'COMPLETED', 'purchase_units' => [[
                'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
                'payments' => ['captures' => [['id' => 'CAP_1', 'status' => 'COMPLETED']]],
            ]]],
            'ref_1',
        ],
        'mollie' => [
            new MollieDriver(['api_key' => 'test_x', 'currencies' => ['EUR']]),
            [],
            ['id' => 'tr_1', 'status' => 'paid', 'amount' => ['value' => '10.00', 'currency' => 'EUR']],
            'tr_1',
        ],
        'razorpay' => [
            new RazorpayDriver(['key_id' => 'rzp_test_x', 'key_secret' => 'x', 'currencies' => ['INR']]),
            [],
            [
                'id' => 'plink_1', 'status' => 'paid', 'reference_id' => 'PZ_1_aa',
                'amount' => 50000, 'currency' => 'INR', 'payments' => [],
            ],
            'plink_1',
        ],
        'paddle' => [
            new PaddleDriver(['api_key' => 'pdl_sdbx_x', 'currencies' => ['USD']]),
            [],
            ['data' => [
                'id' => 'txn_1', 'status' => 'completed', 'currency_code' => 'USD',
                'details' => ['totals' => ['grand_total' => '1000']],
                'custom_data' => ['reference' => 'PZ_1_aa'],
            ]],
            'txn_1',
        ],
    };

    if ($omit !== null) {
        Arr::forget($body, $omit);
    }

    return [driverOverMockedTransport($driver, [...$preamble, $json($body)]), $reference];
}

dataset('drivers with an amount and currency to lose', [
    'paystack amount' => ['paystack', 'data.amount', 'amount'],
    'paystack currency' => ['paystack', 'data.currency', 'currency'],
    'flutterwave amount' => ['flutterwave', 'data.amount', 'amount'],
    'flutterwave currency' => ['flutterwave', 'data.currency', 'currency'],
    'monnify amount' => ['monnify', 'responseBody.amountPaid', 'amountPaid'],
    'monnify currency' => ['monnify', 'responseBody.currencyCode', 'currency'],
    'opay amount' => ['opay', 'data.amount.total', 'total'],
    'opay currency' => ['opay', 'data.amount.currency', 'currency'],
    'paypal amount' => ['paypal', 'purchase_units.0.amount.value', 'value'],
    'paypal currency' => ['paypal', 'purchase_units.0.amount.currency_code', 'currency_code'],
    'mollie amount' => ['mollie', 'amount.value', 'value'],
    'mollie currency' => ['mollie', 'amount.currency', 'currency'],
    'razorpay amount' => ['razorpay', 'amount', 'amount'],
    'razorpay currency' => ['razorpay', 'currency', 'currency'],
    'paddle amount' => ['paddle', 'data.details.totals.grand_total', 'grand_total'],
    'paddle currency' => ['paddle', 'data.currency_code', 'currency_code'],
    'paddle totals block' => ['paddle', 'data.details.totals', 'totals'],
]);

test('the complete response verifies, so the fixture is sound', function (string $provider) {
    [$driver, $reference] = verifyScenario($provider);

    $result = $driver->verify($reference);

    expect($result->amount)->toBeGreaterThan(0.0)
        ->and($result->currency)->not->toBe('');
})->with(['paystack', 'flutterwave', 'monnify', 'opay', 'paypal', 'mollie', 'paddle', 'razorpay']);

test('a verify response missing a money field is refused, never reported', function (string $provider, string $omit, string $field) {
    [$driver, $reference] = verifyScenario($provider, $omit);

    $reported = null;
    $thrown = null;

    try {
        $reported = $driver->verify($reference);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The failure mode being guarded against is not an exception - it is a
    // result. So first: nothing was reported.
    expect($reported)->toBeNull()
        ->and($thrown)->toBeInstanceOf(VerificationException::class)
        ->and($thrown->getMessage())->toContain("omitted the required field [$field]");
})->with('drivers with an amount and currency to lose');
