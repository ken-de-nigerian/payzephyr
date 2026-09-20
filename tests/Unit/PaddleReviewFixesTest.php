<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

/**
 * The corrections applied to the Paddle contribution during review.
 *
 * Grouped here rather than folded into PaddleDriverTest so the contributor's
 * own tests stay theirs and these stay reviewable as a set.
 */
function paddleFixDriver(array $responses, array $config = []): PaddleDriver
{
    $driver = new PaddleDriver(array_merge([
        'api_key' => 'pdl_sdbx_x',
        'webhook_secret' => 'pdl_ntfset_secret',
        'currencies' => ['USD', 'JPY'],
    ], $config));

    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

    return $driver;
}

function paddleJson(array $body, int $status = 200): Response
{
    return new Response($status, [], (string) json_encode($body));
}

/** A transport failure that was transmitted but never answered. */
function paddleLostResponse(): RequestException
{
    return new RequestException('read timed out', new Request('POST', 'https://sandbox-api.paddle.com/adjustments'));
}

// ---------------------------------------------------------------------------
// Refund amounts and currencies are never invented
// ---------------------------------------------------------------------------

test('a refund whose adjustment omits its currency is refused, not read as USD', function () {
    // `?? 'USD'` was not cosmetic: fromMinorUnits() uses the currency to decide
    // whether to divide by 100, so a JPY refund read as USD is reported at a
    // hundredth of its value - and that figure is what gets persisted.
    $driver = paddleFixDriver([paddleJson(['data' => [
        'id' => 'adj_1', 'transaction_id' => 'txn_1', 'action' => 'refund',
        'status' => 'approved', 'totals' => ['total' => '2500'],
    ]])]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'currency_code');
});

test('a refund whose adjustment omits its total is refused, not reported as zero', function () {
    $driver = paddleFixDriver([paddleJson(['data' => [
        'id' => 'adj_1', 'transaction_id' => 'txn_1', 'action' => 'refund',
        'status' => 'approved', 'currency_code' => 'USD',
    ]])]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'totals');
});

test('a refund with no id of its own is refused rather than stored referenceless', function () {
    // An empty refund_reference persists, and fetchRefund('') would later turn
    // into a list query rather than a lookup.
    $driver = paddleFixDriver([paddleJson(['data' => [
        'transaction_id' => 'txn_1', 'action' => 'refund', 'status' => 'approved',
        'currency_code' => 'USD', 'totals' => ['total' => '2500'],
    ]])]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'id');
});

test('a complete adjustment still maps cleanly', function () {
    $driver = paddleFixDriver([paddleJson(['data' => [
        'id' => 'adj_1', 'transaction_id' => 'txn_1', 'action' => 'refund',
        'status' => 'approved', 'currency_code' => 'USD', 'totals' => ['total' => '2500'],
    ]])]);

    $refund = $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1'));

    expect($refund->refundReference)->toBe('adj_1')
        ->and($refund->transactionReference)->toBe('txn_1')
        ->and($refund->amount)->toBe(25.0)
        ->and($refund->currency)->toBe('USD')
        ->and($refund->status)->toBe('completed');
});

// ---------------------------------------------------------------------------
// Reconciliation when the refund's outcome is unknown
// ---------------------------------------------------------------------------

test('a refund whose response was lost reports what Paddle actually holds', function () {
    // Paddle accepts no idempotency key, and its own guidance is to list the
    // entity before retrying. PayZephyr cannot dedupe automatically - an
    // adjustment carries no custom_data, so a retry and a deliberate second
    // refund of the same amount are indistinguishable - so it gathers the
    // facts and puts them in the exception for a human to act on.
    $driver = paddleFixDriver([
        paddleLostResponse(),
        paddleJson(['data' => [[
            'id' => 'adj_existing', 'action' => 'refund', 'status' => 'pending_approval',
            'currency_code' => 'USD', 'totals' => ['total' => '2500'],
        ]]]),
    ]);

    $thrown = null;

    try {
        $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RefundException::class)
        ->and($thrown->getMessage())->toContain('may already have been created')
        ->and($thrown->getMessage())->toContain('adj_existing')
        ->and($thrown->getMessage())->toContain('pending_approval')
        ->and($thrown->getMessage())->toContain('will not retry');
});

test('a lost response with no adjustment on record says so plainly', function () {
    $driver = paddleFixDriver([paddleLostResponse(), paddleJson(['data' => []])]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'no refund adjustments');
});

test('non-refund adjustments are not counted as refunds', function () {
    $driver = paddleFixDriver([
        paddleLostResponse(),
        paddleJson(['data' => [['id' => 'adj_credit', 'action' => 'credit', 'status' => 'approved']]]),
    ]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'no refund adjustments');
});

test('a reconciliation lookup that itself fails does not replace the original error', function () {
    // This runs while an error is already being reported. It must not throw a
    // different one on the way out.
    $driver = paddleFixDriver([paddleLostResponse(), paddleLostResponse()]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1')))
        ->toThrow(RefundException::class, 'could not list the existing adjustments');
});

test('a refund that definitively failed is not dressed up as ambiguous', function () {
    // A connection that was never established means nothing reached Paddle.
    $driver = paddleFixDriver([
        new ConnectException('Connection refused', new Request('POST', 'https://sandbox-api.paddle.com/adjustments')),
    ]);

    $thrown = null;

    try {
        $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RefundException::class)
        ->and($thrown->getMessage())->toContain('Failed to create refund')
        ->and($thrown->getMessage())->not->toContain('may already have been created');
});

// ---------------------------------------------------------------------------
// Paths the contribution left uncovered
// ---------------------------------------------------------------------------

test('a charge that never reached Paddle is reported as a clean connection failure', function () {
    // A refused connection is unambiguous - nothing was created - so this must
    // stay a plain connection error the fallback chain is free to retry, not
    // an ambiguous outcome that strands the payment.
    $driver = paddleFixDriver([
        new ConnectException('Connection refused', new Request('POST', 'https://sandbox-api.paddle.com/transactions')),
    ]);

    $thrown = null;

    try {
        $driver->charge(new ChargeRequestDTO(10.0, 'USD', 'a@b.test', null, 'https://example.com/cb'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ChargeException::class)
        ->and($thrown->getMessage())->toContain('Unable to connect to payment provider')
        ->and($thrown->isAmbiguousProviderOutcome())->toBeFalse();
});

test('a charge whose response Paddle never returned is surfaced as ambiguous', function () {
    // The opposite case: the request was transmitted, so a transaction may
    // exist. This one must NOT be retried blindly.
    $driver = paddleFixDriver([
        new RequestException('read timed out', new Request('POST', 'https://sandbox-api.paddle.com/transactions')),
    ]);

    $thrown = null;

    try {
        $driver->charge(new ChargeRequestDTO(10.0, 'USD', 'a@b.test', null, 'https://example.com/cb'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ChargeException::class)
        ->and($thrown->isAmbiguousProviderOutcome())->toBeTrue();
});

test('a fetched refund that fails in transport is wrapped as a refund failure', function () {
    $driver = paddleFixDriver([
        new ConnectException('Connection refused', new Request('GET', 'https://sandbox-api.paddle.com/adjustments')),
    ]);

    expect(fn () => $driver->fetchRefund('adj_1'))->toThrow(RefundException::class, 'Failed to fetch refund');
});

test('a partial refund whose transaction cannot be looked up explains why', function () {
    $driver = paddleFixDriver([
        new ConnectException('Connection refused', new Request('GET', 'https://sandbox-api.paddle.com/transactions/txn_1')),
    ]);

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 5.0)))
        ->toThrow(RefundException::class, 'failed to look up its line items');
});

test('the health check treats a 400 or 404 as reachable', function (int $status) {
    $driver = paddleFixDriver([paddleJson(['error' => ['code' => 'not_found']], $status)]);

    expect($driver->healthCheck())->toBeTrue();
})->with([400, 404]);

test('the health check reads the event envelope timestamp Paddle actually sends', function () {
    // extractWebhookTimestamp feeds the shared replay window, and had no test
    // of its own despite being security-relevant.
    $driver = paddleFixDriver([]);
    $method = (new ReflectionClass($driver))->getMethod('extractWebhookTimestamp');

    expect($method->invoke($driver, ['occurred_at' => '2026-01-01T12:00:00Z']))
        ->toBe(strtotime('2026-01-01T12:00:00Z'))
        ->and($method->invoke($driver, ['occurred_at' => 'not a date']))->toBeNull()
        ->and($method->invoke($driver, []))->toBeNull();
});

test('every Paddle adjustment status maps to a refund status PayZephyr knows', function (string $paddle, string $expected) {
    $driver = paddleFixDriver([paddleJson(['data' => [
        'id' => 'adj_1', 'transaction_id' => 'txn_1', 'action' => 'refund',
        'status' => $paddle, 'currency_code' => 'USD', 'totals' => ['total' => '100'],
    ]])]);

    expect($driver->refund(new RefundRequestDTO(transactionReference: 'txn_1'))->status)->toBe($expected);
})->with([
    ['pending_approval', 'pending'],
    ['approved', 'completed'],
    ['rejected', 'failed'],
    ['reversed', 'cancelled'],
]);

// ---------------------------------------------------------------------------
// Zero-decimal currencies
// ---------------------------------------------------------------------------

test('a zero-decimal currency is neither multiplied nor divided', function () {
    $driver = paddleFixDriver([paddleJson(['data' => [
        'id' => 'txn_1', 'status' => 'completed', 'currency_code' => 'JPY',
        'details' => ['totals' => ['grand_total' => '5000']],
    ]])]);

    expect($driver->verify('txn_1')->amount)->toBe(5000.0);
});

test('the zero-decimal list matches the one PayPal already uses', function () {
    // A merchant adding KRW or ISK to their Paddle currencies would otherwise
    // have amounts sent a hundred times too large.
    $paddle = (new ReflectionClass(PaddleDriver::class))->getConstant('ZERO_DECIMAL_CURRENCIES');

    expect($paddle)->toContain('KRW')
        ->and($paddle)->toContain('ISK')
        ->and($paddle)->toContain('XOF')
        ->and($paddle)->toContain('JPY');
});

test('an unexpected failure during a charge is still reported as a charge failure', function () {
    // The generic catch in charge(): anything that is not already a
    // ChargeException and not a recognised network fault - a serialization
    // error, a contract violation in a DTO - must still leave through
    // ChargeException rather than escaping as whatever it happened to be, or
    // the fallback chain sees a type it does not handle.
    $driver = paddleFixDriver([new RuntimeException('something unexpected')]);

    $thrown = null;

    try {
        $driver->charge(new ChargeRequestDTO(10.0, 'USD', 'a@b.test', null, 'https://example.com/cb'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ChargeException::class)
        ->and($thrown->getMessage())->toContain('Payment initialization failed')
        ->and($thrown->getPrevious())->toBeInstanceOf(RuntimeException::class);
});
