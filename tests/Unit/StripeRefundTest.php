<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Stripe\Exception\ApiErrorException;

function stripeRefundObj(array $data): object
{
    return json_decode(json_encode($data), false);
}

function makeStripeRefundDriverWithClient(object $client): StripeDriver
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setStripeClient($client);

    return $driver;
}

test('stripe refund creates a refund against a payment intent', function () {
    $refundsResource = new class
    {
        public function create(array $params, array $options = [])
        {
            return stripeRefundObj([
                'id' => 're_123',
                'payment_intent' => $params['payment_intent'],
                'status' => 'succeeded',
                'amount' => $params['amount'] ?? 5000,
                'currency' => 'usd',
                'metadata' => $params['metadata'] ?? [],
            ]);
        }
    };

    $client = new class($refundsResource)
    {
        public function __construct(public object $refunds) {}
    };

    $driver = makeStripeRefundDriverWithClient($client);
    $request = new RefundRequestDTO(transactionReference: 'pi_123', amount: 50.00, reason: 'requested_by_customer');

    $result = $driver->refund($request);

    expect($result->refundReference)->toBe('re_123')
        ->and($result->transactionReference)->toBe('pi_123')
        ->and($result->status)->toBe('succeeded')
        ->and($result->amount)->toBe(50.0)
        ->and($result->currency)->toBe('USD')
        ->and($result->reason)->toBe('requested_by_customer')
        ->and($result->provider)->toBe('stripe');
});

test('stripe refund omits amount for a full refund', function () {
    $capturedParams = null;
    $refundsResource = new class($capturedParams)
    {
        public function __construct(public mixed &$capturedParams) {}

        public function create(array $params, array $options = [])
        {
            $this->capturedParams = $params;

            return stripeRefundObj([
                'id' => 're_124',
                'payment_intent' => $params['payment_intent'],
                'status' => 'succeeded',
                'amount' => 10000,
                'currency' => 'usd',
                'metadata' => [],
            ]);
        }
    };

    $client = new class($refundsResource)
    {
        public function __construct(public object $refunds) {}
    };

    $driver = makeStripeRefundDriverWithClient($client);
    $driver->refund(new RefundRequestDTO(transactionReference: 'pi_124'));

    expect($refundsResource->capturedParams)->not->toHaveKey('amount');
});

test('stripe refund throws RefundException on api error', function () {
    $apiError = Mockery::mock(ApiErrorException::class);
    $apiError->shouldReceive('getMessage')->andReturn('No such payment_intent');

    $refundsResource = new class($apiError)
    {
        public function __construct(private object $apiError) {}

        public function create(array $params, array $options = [])
        {
            throw $this->apiError;
        }
    };

    $client = new class($refundsResource)
    {
        public function __construct(public object $refunds) {}
    };

    $driver = makeStripeRefundDriverWithClient($client);
    $driver->refund(new RefundRequestDTO(transactionReference: 'pi_invalid'));
})->throws(RefundException::class);

test('stripe fetchRefund retrieves and maps a refund', function () {
    $refundsResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeRefundObj([
                'id' => $id,
                'payment_intent' => 'pi_999',
                'status' => 'pending',
                'amount' => 2500,
                'currency' => 'usd',
                'metadata' => [],
            ]);
        }
    };

    $client = new class($refundsResource)
    {
        public function __construct(public object $refunds) {}
    };

    $driver = makeStripeRefundDriverWithClient($client);
    $result = $driver->fetchRefund('re_999');

    expect($result->refundReference)->toBe('re_999')
        ->and($result->transactionReference)->toBe('pi_999')
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(25.0);
});

/**
 * A Stripe refund whose response cannot be read must surface as a
 * RefundException.
 *
 * The response is mapped after refunds->create() has succeeded, and the
 * mapping can throw: requireAmountValue() raises ChargeException on a missing
 * amount. refund() used to catch only ApiErrorException, so that escaped as a
 * ChargeException. A caller catching RefundException would miss it and could
 * retry - and without an idempotency key that retry is a second refund.
 */
function stripeRefundClientReturning(array $refund): object
{
    $refunds = new class($refund)
    {
        public function __construct(private array $refund) {}

        public function create(array $params, array $options = []): object
        {
            return stripeRefundObj($this->refund);
        }

        public function retrieve(string $id): object
        {
            return stripeRefundObj($this->refund);
        }
    };

    return new class($refunds)
    {
        public function __construct(public object $refunds) {}
    };
}

test('a stripe refund with no amount surfaces as a RefundException, not a ChargeException', function () {
    $driver = makeStripeRefundDriverWithClient(stripeRefundClientReturning([
        'id' => 're_noamount',
        'payment_intent' => 'pi_123',
        'status' => 'succeeded',
        'currency' => 'usd',
    ]));

    $thrown = null;

    try {
        $driver->refund(new RefundRequestDTO(transactionReference: 'pi_123', amount: 50.00));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RefundException::class)
        ->and($thrown->getMessage())->toContain('may already have been created')
        ->and($thrown->getPrevious())->toBeInstanceOf(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class);
});

test('a stripe refund with no status surfaces as a RefundException, not a TypeError', function () {
    $driver = makeStripeRefundDriverWithClient(stripeRefundClientReturning([
        'id' => 're_nostatus',
        'payment_intent' => 'pi_123',
        'amount' => 5000,
        'currency' => 'usd',
    ]));

    expect(fn () => $driver->refund(new RefundRequestDTO(transactionReference: 'pi_123', amount: 50.00)))
        ->toThrow(RefundException::class);
});

test('fetching a stripe refund with no amount surfaces as a RefundException', function () {
    $driver = makeStripeRefundDriverWithClient(stripeRefundClientReturning([
        'id' => 're_noamount',
        'payment_intent' => 'pi_123',
        'status' => 'succeeded',
        'currency' => 'usd',
    ]));

    expect(fn () => $driver->fetchRefund('re_noamount'))
        ->toThrow(RefundException::class, 'Failed to read refund from Stripe');
});
