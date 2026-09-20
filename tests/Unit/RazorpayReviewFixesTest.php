<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;

/**
 * The corrections applied to the Razorpay contribution during review.
 *
 * Kept apart from RazorpayDriverTest and RazorpayRefundTest so the
 * contributor's own tests stay theirs and these stay reviewable as a set.
 */
function rzpDriver(array $responses, ?MockHandler &$mock = null): RazorpayDriver
{
    $mock = new MockHandler($responses);
    $driver = new RazorpayDriver([
        'key_id' => 'rzp_test_x', 'key_secret' => 'x',
        'webhook_secret' => 'whsec', 'currencies' => ['INR', 'JPY', 'KWD'],
    ]);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $driver;
}

function rzpJson(array $body): Response
{
    return new Response(200, [], (string) json_encode($body));
}

// ---------------------------------------------------------------------------
// The outbound refund amount
// ---------------------------------------------------------------------------

test('a refund is refused when the payment does not say what currency it is in', function () {
    // The exponent decides the outbound amount, so this is not a labelling
    // problem: refunding JPY 500 against a payment with no currency used to
    // send 50000 minor units - a hundredfold over-refund Razorpay has no
    // reason to reject.
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 500, 'amount_refunded' => 0]),
    ]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'pay_1', amount: 500.0)))
        ->toThrow(RefundException::class, 'currency');
});

test('a zero-decimal refund sends the amount unmultiplied', function () {
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 500, 'amount_refunded' => 0, 'currency' => 'JPY']),
        rzpJson(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 500, 'currency' => 'JPY']),
    ], $mock);

    $refund = $driver->refund(new RefundRequestDTO(transactionReference: 'pay_1', amount: 500.0));

    expect(json_decode((string) $mock->getLastRequest()->getBody(), true)['amount'])->toBe(500)
        ->and($refund->amount)->toBe(500.0);
});

test('a three-decimal refund sends thousandths with a trailing zero', function () {
    // Razorpay requires the last digit to be 0 for these currencies.
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 5000, 'amount_refunded' => 0, 'currency' => 'KWD']),
        rzpJson(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 5000, 'currency' => 'KWD']),
    ], $mock);

    $driver->refund(new RefundRequestDTO(transactionReference: 'pay_1', amount: 5.0));

    expect(json_decode((string) $mock->getLastRequest()->getBody(), true)['amount'])->toBe(5000);
});

// ---------------------------------------------------------------------------
// Refund amounts are never invented
// ---------------------------------------------------------------------------

test('a refund response that omits the amount reports what was actually sent', function () {
    // The amount PayZephyr sent is a defensible inference; zero is not.
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 90000, 'amount_refunded' => 0, 'currency' => 'INR']),
        rzpJson(['id' => 'rfnd_1', 'status' => 'processed', 'currency' => 'INR']),
    ]);

    expect($driver->refund(new RefundRequestDTO(transactionReference: 'pay_1', amount: 250.0))->amount)
        ->toBe(250.0);
});

test('a full refund that is not echoed back reports the unrefunded remainder', function () {
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 90000, 'amount_refunded' => 40000, 'currency' => 'INR']),
        rzpJson(['id' => 'rfnd_1', 'status' => 'processed', 'currency' => 'INR']),
    ]);

    expect($driver->refund(new RefundRequestDTO(transactionReference: 'pay_1'))->amount)->toBe(500.0);
});

test('a fetched refund missing its amount or currency is refused, never reported as zero', function (string $omit) {
    $body = ['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 25000, 'currency' => 'INR', 'payment_id' => 'pay_1'];
    unset($body[$omit]);

    expect(fn () => rzpDriver([rzpJson($body)])->fetchRefund('rfnd_1'))
        ->toThrow(RefundException::class, $omit);
})->with(['amount', 'currency']);

test('a fetched refund with no status is treated as still in flight, not as unknown', function () {
    // 'unknown' maps to no RefundStatus at all, which would drop the refund
    // out of the over-refund guard's accounting entirely.
    $refund = rzpDriver([rzpJson([
        'id' => 'rfnd_1', 'amount' => 25000, 'currency' => 'INR', 'payment_id' => 'pay_1',
    ])])->fetchRefund('rfnd_1');

    expect($refund->status)->toBe('pending');
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

test('an idempotency key Razorpay would not accept is refused, not silently dropped', function () {
    // Sending the refund anyway would leave the caller believing a retry is
    // protected against double-refunding when nothing would deduplicate it.
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 90000, 'amount_refunded' => 0, 'currency' => 'INR']),
    ]);

    expect(fn () => $driver->refund(new RefundRequestDTO(
        transactionReference: 'pay_1', amount: 250.0, idempotencyKey: 'short'
    )))->toThrow(RefundException::class, 'at least 10 characters');
});

test('a valid idempotency key is sent as the header Razorpay documents', function () {
    $driver = rzpDriver([
        rzpJson(['id' => 'pay_1', 'status' => 'captured', 'amount' => 90000, 'amount_refunded' => 0, 'currency' => 'INR']),
        rzpJson(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 25000, 'currency' => 'INR']),
    ], $mock);

    $driver->refund(new RefundRequestDTO(
        transactionReference: 'pay_1', amount: 250.0, idempotencyKey: 'retry_key_abc123'
    ));

    expect($mock->getLastRequest()->getHeaderLine('X-Refund-Idempotency'))->toBe('retry_key_abc123');
});

// ---------------------------------------------------------------------------
// The shared refund-webhook path
// ---------------------------------------------------------------------------

test('a Razorpay refund webhook resolves its reference from the notes', function () {
    Event::fake();

    app()->call([new ProcessWebhook('razorpay', [
        'event' => 'refund.processed',
        'payload' => ['refund' => ['entity' => [
            'id' => 'rfnd_1', 'status' => 'processed', 'payment_id' => 'pay_1',
            'notes' => ['payzephyr_reference' => 'PZ_123'],
        ]]],
    ]), 'handle']);

    Event::assertDispatched(RefundCompleted::class, fn ($e) => $e->transactionReference === 'PZ_123');
});

test('a Square refund webhook is left exactly as it was', function () {
    // payment_id is deliberately not a fallback in the shared chain: Square's
    // refund webhooks carry one, and reading it would silently change the
    // reference those events have always dispatched from '' to a Square id.
    Event::fake();

    app()->call([new ProcessWebhook('square', [
        'event_type' => 'refund.updated',
        'data' => ['object' => [
            'id' => 'sq_refund_1', 'status' => 'COMPLETED', 'payment_id' => 'sq_payment_999',
        ]],
    ]), 'handle']);

    Event::assertDispatched(RefundCompleted::class, fn ($e) => $e->transactionReference === '');
});

// ---------------------------------------------------------------------------
// Failure paths the contribution left uncovered
// ---------------------------------------------------------------------------

test('an unexpected failure during a charge is still reported as a charge failure', function () {
    // Anything that is not already a ChargeException and not a recognised
    // network fault must still leave through ChargeException, or the fallback
    // chain sees a type it does not handle.
    $driver = rzpDriver([new RuntimeException('something unexpected')]);

    $thrown = null;

    try {
        $driver->charge(new ChargeRequestDTO(10.0, 'INR', 'a@b.test', null, 'https://example.com/cb'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ChargeException::class)
        ->and($thrown->getMessage())->toContain('Payment initialization failed')
        ->and($thrown->getPrevious())->toBeInstanceOf(RuntimeException::class);
});

test('a payment link fetched by id that comes back without one is refused', function () {
    expect(fn () => rzpDriver([rzpJson(['status' => 'paid', 'amount' => 100, 'currency' => 'INR'])])->verify('plink_missing'))
        ->toThrow(VerificationException::class, 'not found');
});

test('a refund fetched by an id Razorpay does not know is refused', function () {
    expect(fn () => rzpDriver([rzpJson(['status' => 'processed'])])->fetchRefund('rfnd_missing'))
        ->toThrow(RefundException::class, 'not found');
});

test('a refund fetch that fails in transport is wrapped as a refund failure', function () {
    $driver = rzpDriver([
        new ConnectException('Connection refused', new Request('GET', 'https://api.razorpay.com/v1/refunds/rfnd_1')),
    ]);

    expect(fn () => $driver->fetchRefund('rfnd_1'))->toThrow(RefundException::class, 'Failed to fetch refund');
});
