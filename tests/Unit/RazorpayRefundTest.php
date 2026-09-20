<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use Tests\Helpers\RazorpayDriverTestHelper;

/**
 * @param  array<string, mixed>  $overrides
 */
function razorpayRefundResponse(array $overrides = []): Response
{
    return new Response(200, [], json_encode(array_merge([
        'id' => 'rfnd_Abc123',
        'entity' => 'refund',
        'amount' => 49950,
        'currency' => 'INR',
        'payment_id' => 'pay_Abc123',
        'notes' => ['payzephyr_reference' => 'ORDER_1001'],
        'status' => 'pending',
        'speed_requested' => 'normal',
    ], $overrides)));
}

/**
 * A captured payment, shaped like GET /v1/payments/{id}.
 *
 * @param  array<string, mixed>  $overrides
 */
function razorpayPaymentResponse(array $overrides = []): Response
{
    return new Response(200, [], json_encode(array_merge([
        'id' => 'pay_Abc123',
        'entity' => 'payment',
        'amount' => 49950,
        'amount_refunded' => 0,
        'currency' => 'INR',
        'status' => 'captured',
        'method' => 'upi',
        'refund_status' => null,
    ], $overrides)));
}

test('razorpay refunds the captured payment behind a package reference', function () {
    // Razorpay's list endpoint returns payments: [] even for a paid link, so
    // the match is re-fetched by id before the captured payment is picked.
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['payment_links' => [RazorpayDriverTestHelper::paymentLink(['payments' => []])]])),
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        razorpayRefundResponse(),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(
        transactionReference: 'ORDER_1001',
        idempotencyKey: 'refund-order-1001',
    ));

    $lookup = $history[0]['request'];
    $refund = $history[3]['request'];
    $body = json_decode((string) $refund->getBody(), true);

    expect($lookup->getUri()->getQuery())->toBe('reference_id=ORDER_1001')
        ->and($refund->getMethod())->toBe('POST')
        ->and($refund->getUri()->getPath())->toBe('/v1/payments/pay_Abc123/refund')
        ->and($refund->getHeaderLine('X-Refund-Idempotency'))->toBe('refund-order-1001')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/v1/payments/pay_Abc123')
        ->and($body['amount'])->toBe(49950)
        ->and($body['speed'])->toBe('normal')
        ->and($body['notes'])->toBe(['payzephyr_reference' => 'ORDER_1001'])
        ->and($result->refundReference)->toBe('rfnd_Abc123')
        ->and($result->transactionReference)->toBe('ORDER_1001')
        ->and($result->status)->toBe('pending')
        ->and($result->isPending())->toBeTrue()
        ->and($result->amount)->toBe(499.5)
        ->and($result->currency)->toBe('INR')
        ->and($result->provider)->toBe('razorpay')
        ->and(RefundTransaction::where('refund_reference', 'rfnd_Abc123')->value('transaction_reference'))->toBe('ORDER_1001');
});

test('razorpay partial refund uses the currency razorpay reports, not config', function () {
    $link = RazorpayDriverTestHelper::paymentLink(['currency' => 'KWD', 'amount' => 12340]);

    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode($link)),
        razorpayPaymentResponse(['amount' => 12340, 'currency' => 'KWD']),
        razorpayRefundResponse(['amount' => 5500, 'currency' => 'KWD']),
    ], $history, ['currencies' => ['INR', 'KWD']]);

    $result = $driver->refund(new RefundRequestDTO(
        transactionReference: 'plink_Abc123',
        amount: 5.5,
        reason: 'damaged item',
    ));

    $body = json_decode((string) $history[2]['request']->getBody(), true);

    expect($body['amount'])->toBe(5500)
        ->and($body['notes'])->toBe(['payzephyr_reference' => 'plink_Abc123', 'reason' => 'damaged item'])
        ->and($result->amount)->toBe(5.5)
        ->and($result->currency)->toBe('KWD');
});

test('razorpay refunds a pay_ id directly, reading its currency first', function () {
    $driver = RazorpayDriverTestHelper::driver([
        razorpayPaymentResponse(['id' => 'pay_Jpy123', 'amount' => 1000, 'currency' => 'JPY']),
        razorpayRefundResponse(['amount' => 300, 'currency' => 'JPY', 'payment_id' => 'pay_Jpy123']),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'pay_Jpy123', amount: 300));

    $body = json_decode((string) $history[1]['request']->getBody(), true);

    expect($history[0]['request']->getUri()->getPath())->toBe('/v1/payments/pay_Jpy123')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v1/payments/pay_Jpy123/refund')
        ->and($body['amount'])->toBe(300)
        ->and($result->amount)->toBe(300.0);
});

test('razorpay refund skips an idempotency key razorpay would reject', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        razorpayRefundResponse(),
    ], $history);

    $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123', idempotencyKey: 'short'));

    expect($history[2]['request']->hasHeader('X-Refund-Idempotency'))->toBeFalse();
});

test('razorpay refund uses the configured refund speed', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        razorpayRefundResponse(),
    ], $history, ['refund_speed' => 'optimum']);

    $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123'));

    expect(json_decode((string) $history[2]['request']->getBody(), true)['speed'])->toBe('optimum');
});

test('razorpay refund refuses a link with no captured payment', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink(['status' => 'created', 'payments' => []]))),
    ], $history);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123')))
        ->toThrow(RefundException::class, 'expected exactly one captured Razorpay payment, found 0');

    expect($history)->toHaveCount(1);
});

test('a razorpay lookup that times out before refunding is not an ambiguous refund', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new RequestException('Operation timed out', new Request('GET', '/v1/payment_links')),
    ]);

    $exception = null;
    try {
        $driver->refund(new RefundRequestDTO(transactionReference: 'ORDER_1001'));
    } catch (RefundException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(RefundException::class)
        ->and($exception->getMessage())->toContain('before refunding')
        ->and($exception->isAmbiguousProviderOutcome())->toBeFalse();
});

test('a razorpay refund request that loses its response is ambiguous', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        new RequestException('Operation timed out', new Request('POST', '/v1/payments/pay_Abc123/refund')),
    ]);

    $exception = null;
    try {
        $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123'));
    } catch (RefundException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(RefundException::class)
        ->and($exception->isAmbiguousProviderOutcome())->toBeTrue();
});

test('razorpay refund throws when razorpay rejects it', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        new Response(400, [], json_encode(['error' => [
            'code' => 'BAD_REQUEST_ERROR',
            'description' => 'The refund amount provided is greater than amount captured',
        ]])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123', amount: 1000));
})->throws(RefundException::class, 'Razorpay: The refund amount provided is greater than amount captured');

test('razorpay refund throws when the response has no refund id', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(),
        new Response(200, [], json_encode(['status' => 'pending'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123'));
})->throws(RefundException::class, 'Razorpay did not return a refund id');

test('a razorpay full refund after a partial one sends only the unrefunded remainder', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(['amount_refunded' => 20000, 'refund_status' => 'partial']),
        razorpayRefundResponse(['amount' => 29950]),
    ], $history);

    $result = $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123'));

    expect(json_decode((string) $history[2]['request']->getBody(), true)['amount'])->toBe(29950)
        ->and($result->amount)->toBe(299.5);
});

test('razorpay refund refuses a fully refunded payment without calling the refund endpoint', function () {
    // Observed in test mode: after a full refund the link still lists its
    // payment as "captured"; only the payment entity itself says "refunded".
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
        razorpayPaymentResponse(['status' => 'refunded', 'amount_refunded' => 49950]),
    ], $history);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'plink_Abc123')))
        ->toThrow(RefundException::class, 'it is refunded with 0 of 49950 minor units left to refund');

    expect($history)->toHaveCount(2);
});

test('razorpay fetchRefund keeps the package reference on the logged refund', function () {
    RefundTransaction::create([
        'refund_reference' => 'rfnd_Abc123',
        'transaction_reference' => 'ORDER_1001',
        'provider' => 'razorpay',
        'status' => 'pending',
        'amount' => 499.50,
        'currency' => 'INR',
    ]);

    $driver = RazorpayDriverTestHelper::driver([
        razorpayRefundResponse([
            'status' => 'processed',
            'notes' => ['payzephyr_reference' => 'ORDER_1001', 'reason' => 'damaged item'],
        ]),
    ], $history);

    $result = $driver->fetchRefund('rfnd_Abc123');
    $row = RefundTransaction::where('refund_reference', 'rfnd_Abc123')->first();

    expect($history[0]['request']->getUri()->getPath())->toBe('/v1/refunds/rfnd_Abc123')
        ->and($result->transactionReference)->toBe('ORDER_1001')
        ->and($result->isCompleted())->toBeTrue()
        ->and($result->reason)->toBe('damaged item')
        ->and($row->transaction_reference)->toBe('ORDER_1001')
        ->and($row->status)->toBe('completed');
});

test('razorpay fetchRefund falls back to the payment id for refunds made outside PayZephyr', function () {
    $driver = RazorpayDriverTestHelper::driver([
        razorpayRefundResponse(['notes' => []]),
    ]);

    expect($driver->fetchRefund('rfnd_Abc123')->transactionReference)->toBe('pay_Abc123');
});

test('razorpay fetchRefund throws when the refund does not exist', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(400, [], json_encode(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']])),
    ]);

    $driver->fetchRefund('rfnd_Missing');
})->throws(RefundException::class, 'Razorpay: The id provided does not exist');
