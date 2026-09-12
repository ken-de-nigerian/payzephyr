<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

beforeEach(function () {
    $this->config = [
        'api_key' => 'pdl_sdbx_apikey_test',
        'webhook_secret' => 'pdl_ntfset_test_secret',
        'base_url' => 'https://sandbox-api.paddle.com',
        'currencies' => ['USD', 'EUR', 'GBP', 'JPY'],
    ];
});

function paddleDriverWith(array $config, array $responses): PaddleDriver
{
    $driver = new PaddleDriver($config);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

    return $driver;
}

test('paddle driver initializes correctly', function () {
    $driver = new PaddleDriver($this->config);

    expect($driver->getName())->toBe('paddle')
        ->and($driver->isCurrencySupported('USD'))->toBeTrue()
        ->and($driver->isCurrencySupported('NGN'))->toBeFalse();
});

test('paddle driver throws exception for missing api key', function () {
    unset($this->config['api_key']);

    new PaddleDriver($this->config);
})->throws(InvalidConfigurationException::class, 'Paddle API key is required');

test('paddle driver charges successfully and returns the hosted checkout url', function () {
    $driver = paddleDriverWith($this->config, [
        new Response(201, [], json_encode([
            'data' => [
                'id' => 'txn_01hv8wptq8987qeep44cyrewp9',
                'status' => 'ready',
                'checkout' => ['url' => 'https://example.com/pay?_ptxn=txn_01hv8wptq8987qeep44cyrewp9'],
            ],
        ])),
    ]);

    $response = $driver->charge(new ChargeRequestDTO(
        amount: 25.50,
        currency: 'USD',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    ));

    expect($response->reference)->toStartWith('PADDLE_')
        ->and($response->authorizationUrl)->toBe('https://example.com/pay?_ptxn=txn_01hv8wptq8987qeep44cyrewp9')
        ->and($response->accessCode)->toBe('txn_01hv8wptq8987qeep44cyrewp9')
        ->and($response->status)->toBe('pending')
        ->and($response->provider)->toBe('paddle')
        ->and($response->metadata['paddle_transaction_id'])->toBe('txn_01hv8wptq8987qeep44cyrewp9');
});

test('paddle driver sends the amount in minor units and carries the reference in custom_data', function () {
    $container = [];
    $history = GuzzleHttp\Middleware::history($container);
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode([
            'data' => ['id' => 'txn_x', 'status' => 'ready', 'checkout' => ['url' => 'https://example.com/pay']],
        ])),
    ]));
    $stack->push($history);

    $driver = new PaddleDriver($this->config);
    $driver->setClient(new Client(['handler' => $stack]));

    $driver->charge(new ChargeRequestDTO(
        amount: 25.50,
        currency: 'USD',
        email: 'test@example.com',
        reference: 'MY_REF_1',
    ));

    $body = json_decode((string) $container[0]['request']->getBody(), true);

    expect($body['items'][0]['price']['unit_price']['amount'])->toBe('2550')
        ->and($body['items'][0]['price']['unit_price']['currency_code'])->toBe('USD')
        ->and($body['custom_data']['reference'])->toBe('MY_REF_1')
        ->and($body['collection_mode'])->toBe('automatic');
});

test('paddle driver does not multiply zero-decimal currencies by 100', function () {
    $container = [];
    $history = GuzzleHttp\Middleware::history($container);
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode([
            'data' => ['id' => 'txn_x', 'status' => 'ready', 'checkout' => ['url' => 'https://example.com/pay']],
        ])),
    ]));
    $stack->push($history);

    $driver = new PaddleDriver($this->config);
    $driver->setClient(new Client(['handler' => $stack]));

    $driver->charge(new ChargeRequestDTO(amount: 1200.0, currency: 'JPY', email: 'test@example.com'));

    $body = json_decode((string) $container[0]['request']->getBody(), true);

    expect($body['items'][0]['price']['unit_price']['amount'])->toBe('1200');
});

test('paddle driver fails the charge when paddle returns no checkout url', function () {
    $driver = paddleDriverWith($this->config, [
        new Response(201, [], json_encode(['data' => ['id' => 'txn_x', 'status' => 'draft']])),
    ]);

    $driver->charge(new ChargeRequestDTO(amount: 10.0, currency: 'USD', email: 'test@example.com'));
})->throws(ChargeException::class, 'No checkout URL returned by Paddle');

test('paddle driver verifies a transaction successfully', function () {
    $driver = paddleDriverWith($this->config, [
        new Response(200, [], json_encode([
            'data' => [
                'id' => 'txn_01hv8wptq8987qeep44cyrewp9',
                'status' => 'completed',
                'currency_code' => 'USD',
                'billed_at' => '2024-04-12T10:18:48.294633Z',
                'custom_data' => ['reference' => 'PADDLE_123', 'email' => 'buyer@example.com'],
                'details' => ['totals' => ['grand_total' => '65215']],
                'payments' => [[
                    'status' => 'captured',
                    'method_details' => [
                        'type' => 'card',
                        'card' => ['type' => 'visa', 'cardholder_name' => 'Michael McGovern'],
                    ],
                ]],
            ],
        ])),
    ]);

    $verification = $driver->verify('txn_01hv8wptq8987qeep44cyrewp9');

    expect($verification->reference)->toBe('PADDLE_123')
        ->and($verification->status)->toBe('success')
        ->and($verification->amount)->toBe(652.15)
        ->and($verification->currency)->toBe('USD')
        ->and($verification->channel)->toBe('card')
        ->and($verification->cardType)->toBe('visa')
        ->and($verification->customer['email'])->toBe('buyer@example.com')
        ->and($verification->isSuccessful())->toBeTrue();
});

test('paddle driver wraps verification failures', function () {
    $driver = paddleDriverWith($this->config, [new Response(500, [], 'boom')]);

    $driver->verify('txn_x');
})->throws(VerificationException::class);

test('paddle driver validates a correctly signed webhook', function () {
    $driver = new PaddleDriver($this->config);

    $body = json_encode(['event_id' => 'evt_1', 'event_type' => 'transaction.completed', 'data' => ['id' => 'txn_1']]);
    $ts = (string) time();
    $headers = [
        'paddle-signature' => ['ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, $this->config['webhook_secret'])],
    ];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('paddle driver rejects a webhook signed with the wrong secret', function () {
    $driver = new PaddleDriver($this->config);

    $body = json_encode(['event_id' => 'evt_1']);
    $ts = (string) time();
    $headers = ['paddle-signature' => ['ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, 'wrong_secret')]];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('paddle driver rejects a webhook whose signature covers a different timestamp', function () {
    // The HMAC is over "<ts>:<body>", so replaying an old-but-validly-signed
    // event with a fresh ts must fail both checks, not just the clock one.
    $driver = new PaddleDriver($this->config);

    $body = json_encode(['event_id' => 'evt_1']);
    $signedTs = (string) (time() - 10_000);
    $signature = hash_hmac('sha256', $signedTs.':'.$body, $this->config['webhook_secret']);

    expect($driver->validateWebhook(['paddle-signature' => ['ts='.$signedTs.';h1='.$signature]], $body))->toBeFalse()
        ->and($driver->validateWebhook(['paddle-signature' => ['ts='.time().';h1='.$signature]], $body))->toBeFalse();
});

test('paddle driver rejects webhooks with a missing or malformed signature header', function () {
    $driver = new PaddleDriver($this->config);

    expect($driver->validateWebhook([], '{}'))->toBeFalse()
        ->and($driver->validateWebhook(['paddle-signature' => ['garbage']], '{}'))->toBeFalse()
        ->and($driver->validateWebhook(['paddle-signature' => ['ts=notanumber;h1=abc']], '{}'))->toBeFalse();
});

test('paddle driver rejects webhooks when no webhook secret is configured', function () {
    unset($this->config['webhook_secret']);
    $driver = new PaddleDriver($this->config);

    $body = '{}';
    $ts = (string) time();

    expect($driver->validateWebhook(['paddle-signature' => ['ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, 'anything')]], $body))->toBeFalse();
});

test('paddle driver extracts webhook fields from the event envelope', function () {
    $driver = new PaddleDriver($this->config);

    $payload = [
        'event_id' => 'evt_01h',
        'notification_id' => 'ntf_01h',
        'event_type' => 'transaction.completed',
        'occurred_at' => date('c'),
        'data' => [
            'id' => 'txn_01h',
            'status' => 'completed',
            'custom_data' => ['reference' => 'PADDLE_999'],
            'payments' => [['method_details' => ['type' => 'paypal']]],
        ],
    ];

    expect($driver->extractWebhookReference($payload))->toBe('PADDLE_999')
        ->and($driver->extractWebhookStatus($payload))->toBe('completed')
        ->and($driver->extractWebhookChannel($payload))->toBe('paypal')
        ->and($driver->extractWebhookEventId($payload))->toBe('evt_01h');
});

test('paddle driver falls back to the transaction id when no reference was stored', function () {
    $driver = new PaddleDriver($this->config);

    expect($driver->extractWebhookReference(['data' => ['id' => 'txn_01h']]))->toBe('txn_01h');
});

test('paddle driver health check succeeds against event types', function () {
    $driver = paddleDriverWith($this->config, [new Response(200, [], json_encode(['data' => []]))]);

    expect($driver->healthCheck())->toBeTrue();
});

test('paddle driver health check fails on server errors', function () {
    $driver = paddleDriverWith($this->config, [new Response(500, [], 'boom')]);

    expect($driver->healthCheck())->toBeFalse();
});

test('paddle driver creates a full refund as a full adjustment', function () {
    $container = [];
    $history = GuzzleHttp\Middleware::history($container);
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode([
            'data' => [
                'id' => 'adj_01hvgf2s84dr6reszzg29zbvcm',
                'action' => 'refund',
                'type' => 'full',
                'transaction_id' => 'txn_01hvcc93znj3mpqt1tenkjb04y',
                'status' => 'pending_approval',
                'currency_code' => 'USD',
                'reason' => 'customer changed their mind',
                'totals' => ['total' => '10000'],
            ],
        ])),
    ]));
    $stack->push($history);

    $driver = new PaddleDriver($this->config);
    $driver->setClient(new Client(['handler' => $stack]));

    $response = $driver->refund(new RefundRequestDTO(
        transactionReference: 'txn_01hvcc93znj3mpqt1tenkjb04y',
        reason: 'customer changed their mind',
    ));

    $body = json_decode((string) $container[0]['request']->getBody(), true);

    expect($body)->toMatchArray([
        'action' => 'refund',
        'type' => 'full',
        'transaction_id' => 'txn_01hvcc93znj3mpqt1tenkjb04y',
    ])
        ->and($body)->not->toHaveKey('items')
        ->and($response->refundReference)->toBe('adj_01hvgf2s84dr6reszzg29zbvcm')
        ->and($response->amount)->toBe(100.0)
        ->and($response->currency)->toBe('USD')
        ->and($response->status)->toBe('pending')
        ->and($response->isPending())->toBeTrue();
});

test('paddle driver creates a partial refund against the resolved line item', function () {
    $container = [];
    $history = GuzzleHttp\Middleware::history($container);
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'data' => ['details' => ['line_items' => [['id' => 'txnitm_01hvcc94b7qgz60qmrqmbm19zw']]]],
        ])),
        new Response(201, [], json_encode([
            'data' => [
                'id' => 'adj_2',
                'transaction_id' => 'txn_1',
                'status' => 'approved',
                'currency_code' => 'USD',
                'totals' => ['total' => '2500'],
            ],
        ])),
    ]));
    $stack->push($history);

    $driver = new PaddleDriver($this->config);
    $driver->setClient(new Client(['handler' => $stack]));

    $response = $driver->refund(new RefundRequestDTO(
        transactionReference: 'txn_1',
        amount: 25.00,
        currency: 'USD',
    ));

    $body = json_decode((string) $container[1]['request']->getBody(), true);

    expect($body['type'])->toBe('partial')
        ->and($body['items'][0])->toMatchArray([
            'item_id' => 'txnitm_01hvcc94b7qgz60qmrqmbm19zw',
            'type' => 'partial',
            'amount' => '2500',
        ])
        ->and($response->status)->toBe('completed')
        ->and($response->isCompleted())->toBeTrue()
        ->and($response->amount)->toBe(25.0);
});

test('paddle driver refuses a partial refund on a multi-item transaction', function () {
    $driver = paddleDriverWith($this->config, [
        new Response(200, [], json_encode([
            'data' => ['details' => ['line_items' => [['id' => 'txnitm_1'], ['id' => 'txnitm_2']]]],
        ])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 5.0, currency: 'USD'));
})->throws(RefundException::class, 'expected exactly one line item');

test('paddle driver fetches a refund by filtering the adjustments list', function () {
    $driver = paddleDriverWith($this->config, [
        new Response(200, [], json_encode([
            'data' => [[
                'id' => 'adj_1',
                'transaction_id' => 'txn_1',
                'status' => 'rejected',
                'currency_code' => 'EUR',
                'reason' => 'duplicate',
                'totals' => ['total' => '1500'],
            ]],
        ])),
    ]);

    $response = $driver->fetchRefund('adj_1');

    expect($response->refundReference)->toBe('adj_1')
        ->and($response->status)->toBe('failed')
        ->and($response->isFailed())->toBeTrue()
        ->and($response->amount)->toBe(15.0)
        ->and($response->currency)->toBe('EUR')
        ->and($response->reason)->toBe('duplicate');
});

test('paddle driver reports a missing adjustment as a refund failure', function () {
    $driver = paddleDriverWith($this->config, [new Response(200, [], json_encode(['data' => []]))]);

    $driver->fetchRefund('adj_missing');
})->throws(RefundException::class, 'not found');
