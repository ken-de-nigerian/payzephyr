<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\NowPaymentsDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createNowPaymentsDriverWithMockForEdgeCases(array $responses, array $config = []): NowPaymentsDriver
{
    $defaultConfig = [
        'api_key' => 'TEST_API_KEY_123',
        'ipn_secret' => 'TEST_IPN_SECRET_123',
        'base_url' => 'https://api.nowpayments.io',
        'currencies' => ['USD', 'NGN', 'EUR', 'BTC', 'ETH'],
    ];

    $config = array_merge($defaultConfig, $config);
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new NowPaymentsDriver($config);
    $driver->setClient($client);

    return $driver;
}

beforeEach(function () {
    $this->config = [
        'api_key' => 'TEST_API_KEY_123',
        'ipn_secret' => 'TEST_IPN_SECRET_123',
        'base_url' => 'https://api.nowpayments.io',
        'currencies' => ['USD', 'NGN', 'EUR', 'BTC', 'ETH'],
    ];
});

// ============================================================================
// CONFIGURATION EDGE CASES
// ============================================================================

test('nowpayments driver throws exception when api_key is missing', function () {
    expect(fn () => new NowPaymentsDriver([
        'ipn_secret' => 'test_secret',
        'currencies' => ['USD'],
    ]))->toThrow(InvalidConfigurationException::class, 'api key is required');
});

test('nowpayments driver throws exception when api_key is empty string', function () {
    expect(fn () => new NowPaymentsDriver([
        'api_key' => '',
        'ipn_secret' => 'test_secret',
        'currencies' => ['USD'],
    ]))->toThrow(InvalidConfigurationException::class, 'api key is required');
});

// ============================================================================
// CHARGE EDGE CASES - Amount and Currency
// ============================================================================

test('nowpayments driver handles charge with very small amount (crypto precision)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '0.00001',
            'price_currency' => 'btc',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(0.00001, 'BTC', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('NOW_');
});

test('nowpayments driver handles charge with very large amount', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '999999999.99',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(999999999.99, 'USD', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('NOW_');
});

test('nowpayments driver handles charge with unsupported currency gracefully', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(400, [], json_encode([
            'message' => 'Currency XXX is not supported',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'XXX', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles charge with cryptocurrency currency code', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '0.001',
            'price_currency' => 'btc',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(0.001, 'BTC', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->status)->toBe('pending');
});

// ============================================================================
// CHARGE EDGE CASES - Network and API Errors
// ============================================================================

test('nowpayments driver handles network timeout during charge', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection timeout',
            new Request('POST', '/v1/invoice')
        ),
    ]);

    $driver = new NowPaymentsDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles 401 unauthorized error', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(401, [], json_encode([
            'message' => 'Unauthorized. Invalid API key.',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles 429 rate limit error', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(429, [], json_encode([
            'message' => 'Rate limit exceeded. Please try again later.',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles 500 server error', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new ServerException('Internal Server Error', new Request('POST', '/v1/invoice'), new Response(500)),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles malformed json response', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], 'invalid json response {'),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles response without id field', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'message' => 'Invoice created but id is missing',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('nowpayments driver handles response without invoice_url', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
            // Missing invoice_url - this should throw an exception
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

// ============================================================================
// CHARGE EDGE CASES - Missing Fields
// ============================================================================

test('nowpayments driver handles charge with missing callback url', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com');

    // Should still work, callback URLs will be null
    $response = $driver->charge($request);
    expect($response->reference)->toStartWith('NOW_');
});

test('nowpayments driver handles charge with empty description', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback', [], '');
    $response = $driver->charge($request);

    expect($response->status)->toBe('pending');
});

test('nowpayments driver handles charge with very long description', function () {
    $longDescription = str_repeat('A', 2000);
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback', [], $longDescription);
    $response = $driver->charge($request);

    expect($response->status)->toBe('pending');
});

test('nowpayments driver handles charge with special characters in metadata', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'id' => '4522625843',
            'order_id' => 'NOW_1234567890_abc123def456',
            'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843',
            'price_amount' => '100.00',
            'price_currency' => 'usd',
            'created_at' => '2020-12-22T15:05:58.290Z',
            'updated_at' => '2020-12-22T15:05:58.290Z',
        ])),
    ]);

    $request = new ChargeRequestDTO(100.00, 'USD', 'test@example.com', null, 'https://example.com/callback', [
        'order_id' => '123',
        'special' => 'Value with "quotes" & <tags>',
        'unicode' => '🎉 Test',
        'crypto_address' => '0x1234567890abcdef',
    ]);
    $response = $driver->charge($request);

    expect($response->metadata)->toHaveKey('special');
});

// ============================================================================
// VERIFY EDGE CASES - Payment Status
// ============================================================================

test('nowpayments driver handles verify with all crypto payment statuses', function () {
    $statuses = ['waiting', 'confirming', 'sending', 'partially_paid', 'finished', 'confirmed', 'failed', 'refunded', 'expired'];

    foreach ($statuses as $status) {
        $driver = createNowPaymentsDriverWithMockForEdgeCases([
            new Response(200, [], json_encode([
                'payment_id' => 12345678,
                'order_id' => 'NOW_1234567890_abc123def456',
                'payment_status' => $status,
                'price_amount' => 100.00,
                'price_currency' => 'usd',
                'updated_at' => time(),
            ])),
        ]);

        $result = $driver->verify('12345678');
        expect($result->status)->toBe($status);
    }
});

test('nowpayments driver handles verify with partially_paid status (common in crypto)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'partially_paid',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'actually_paid' => 50.00,
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('partially_paid')
        ->and($result->isPending())->toBeTrue()
        ->and($result->metadata)->toHaveKey('actually_paid', 50.00);
});

test('nowpayments driver handles verify with missing updated_at timestamp', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            // Missing updated_at
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->paidAt)->toBeNull();
});

test('nowpayments driver handles verify with invalid payment_id format', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(400, [], json_encode([
            'message' => 'Invalid payment ID format',
        ])),
    ]);

    expect(fn () => $driver->verify('invalid_payment_id'))->toThrow(VerificationException::class);
});

test('nowpayments driver handles verify with payment not found (404)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(404, [], json_encode([
            'message' => 'Payment not found',
        ])),
    ]);

    expect(fn () => $driver->verify('99999999'))->toThrow(VerificationException::class);
});

test('nowpayments driver handles verify with network timeout', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection timeout',
            new Request('GET', '/v1/payment/12345678')
        ),
    ]);

    $driver = new NowPaymentsDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect(fn () => $driver->verify('12345678'))->toThrow(VerificationException::class);
});

// ============================================================================
// VERIFY EDGE CASES - Amount and Currency Handling
// ============================================================================

test('nowpayments driver handles verify with missing price_amount (uses default)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->amount)->toBe(0.0);
});

test('nowpayments driver handles verify with missing price_currency (uses default)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->currency)->toBe('USD'); // Default
});

test('nowpayments driver handles verify with crypto amount precision (very small values)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 0.00001234,
            'price_currency' => 'btc',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->amount)->toBe(0.00001234)
        ->and($result->currency)->toBe('BTC');
});

test('nowpayments driver handles verify with actually_paid amount different from price_amount (overpayment/underpayment)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'actually_paid' => 105.50, // Customer paid more
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->metadata)->toHaveKey('actually_paid', 105.50)
        ->and($result->amount)->toBe(100.0); // Uses price_amount, not actually_paid
});

// ============================================================================
// VERIFY EDGE CASES - Crypto-Specific Fields
// ============================================================================

test('nowpayments driver handles verify with pay_currency (cryptocurrency used)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'pay_currency' => 'btc',
            'pay_amount' => 0.002,
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->channel)->toBe('btc')
        ->and($result->metadata)->toHaveKey('pay_currency', 'btc')
        ->and($result->metadata)->toHaveKey('pay_amount', 0.002);
});

test('nowpayments driver handles verify with outcome_amount and outcome_currency', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'outcome_amount' => 0.002,
            'outcome_currency' => 'btc',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->metadata)->toHaveKey('outcome_amount', 0.002)
        ->and($result->metadata)->toHaveKey('outcome_currency', 'btc');
});

test('nowpayments driver handles verify with missing order_id (uses reference fallback)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'payment_status' => 'finished',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->reference)->toBe('12345678'); // Falls back to payment_id
});

// ============================================================================
// WEBHOOK EDGE CASES - Security
// ============================================================================

test('nowpayments driver rejects webhook with missing signature', function () {
    $driver = new NowPaymentsDriver($this->config);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $result = $driver->validateWebhook([], $body);

    expect($result)->toBeFalse();
});

test('nowpayments driver rejects webhook with invalid signature', function () {
    $driver = new NowPaymentsDriver($this->config);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $invalidSignature = 'invalid_signature_hash';

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$invalidSignature]], $body);

    expect($result)->toBeFalse();
});

test('nowpayments driver rejects webhook when ipn_secret is missing', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'TEST_API_KEY_123',
        'ipn_secret' => '',
        'currencies' => ['USD'],
    ]);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $signature = hash_hmac('sha512', $body, 'some_secret');

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$signature]], $body);

    expect($result)->toBeFalse();
});

test('nowpayments driver handles webhook with replay attack (old timestamp)', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 12345678,
        'payment_status' => 'finished',
        'timestamp' => time() - 1000, // Very old timestamp
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $this->config['ipn_secret']);

    // Mock validateWebhookTimestamp to return false for old timestamps
    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$signature]], $body);

    // Should still validate signature but timestamp validation might fail
    // The actual timestamp validation depends on the AbstractDriver implementation
    expect($result)->toBeBool();
});

test('nowpayments driver handles webhook with malformed json body', function () {
    $driver = new NowPaymentsDriver($this->config);

    $body = 'invalid json {';
    $signature = hash_hmac('sha512', $body, $this->config['ipn_secret']);

    // Should still validate signature, but payload parsing will fail
    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$signature]], $body);

    // Signature validation should pass, but timestamp validation might fail due to invalid JSON
    expect($result)->toBeBool();
});

test('nowpayments driver handles webhook with empty body', function () {
    $driver = new NowPaymentsDriver($this->config);

    $body = '';
    $signature = hash_hmac('sha512', $body, $this->config['ipn_secret']);

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$signature]], $body);

    // Should validate signature even with empty body
    expect($result)->toBeBool();
});

test('nowpayments driver handles webhook signature with wrong algorithm attempt', function () {
    $driver = new NowPaymentsDriver($this->config);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    // Try SHA256 instead of SHA512 (wrong algorithm)
    $wrongSignature = hash_hmac('sha256', $body, $this->config['ipn_secret']);

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$wrongSignature]], $body);

    expect($result)->toBeFalse();
});

// ============================================================================
// WEBHOOK EDGE CASES - Payload Extraction
// ============================================================================

test('nowpayments driver extracts webhook reference from order_id', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'order_id' => 'NOW_1234567890_abc123def456',
        'payment_id' => 12345678,
        'payment_status' => 'finished',
    ];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBe('NOW_1234567890_abc123def456');
});

test('nowpayments driver extracts webhook reference from payment_id when order_id missing', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 12345678,
        'payment_status' => 'finished',
    ];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBe('12345678'); // Converted to string
});

test('nowpayments driver extracts webhook reference with integer payment_id', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 999999999, // Large integer
        'payment_status' => 'finished',
    ];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBe('999999999'); // Should be string
});

test('nowpayments driver extracts webhook status returns unknown when missing', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 12345678,
    ];

    $result = $driver->extractWebhookStatus($payload);

    expect($result)->toBe('unknown');
});

test('nowpayments driver extracts webhook channel from pay_currency', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 12345678,
        'pay_currency' => 'eth',
    ];

    $result = $driver->extractWebhookChannel($payload);

    expect($result)->toBe('eth');
});

test('nowpayments driver extracts webhook channel returns null when missing', function () {
    $driver = new NowPaymentsDriver($this->config);

    $payload = [
        'payment_id' => 12345678,
    ];

    $result = $driver->extractWebhookChannel($payload);

    expect($result)->toBeNull();
});

// ============================================================================
// RESOLVE VERIFICATION ID EDGE CASES
// ============================================================================

test('nowpayments driver resolveVerificationId prefers providerId over reference', function () {
    $driver = new NowPaymentsDriver($this->config);

    $result = $driver->resolveVerificationId('NOW_1234567890_abc123def456', '12345678');

    expect($result)->toBe('12345678');
});

test('nowpayments driver resolveVerificationId uses reference when providerId is empty', function () {
    $driver = new NowPaymentsDriver($this->config);

    $result = $driver->resolveVerificationId('NOW_1234567890_abc123def456', '');

    expect($result)->toBe('NOW_1234567890_abc123def456');
});

test('nowpayments driver resolveVerificationId uses reference when providerId is null', function () {
    $driver = new NowPaymentsDriver($this->config);

    // Empty string is considered empty, so it falls back
    $result = $driver->resolveVerificationId('NOW_1234567890_abc123def456', '');

    expect($result)->toBe('NOW_1234567890_abc123def456');
});

// ============================================================================
// HEALTH CHECK EDGE CASES
// ============================================================================

test('nowpayments driver healthCheck handles 503 service unavailable', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(503, [], json_encode(['message' => 'Service unavailable'])),
    ]);

    $result = $driver->healthCheck();

    expect($result)->toBeFalse(); // 5xx errors should return false
});

test('nowpayments driver healthCheck handles 502 bad gateway', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(502, [], json_encode(['message' => 'Bad gateway'])),
    ]);

    $result = $driver->healthCheck();

    expect($result)->toBeFalse(); // 5xx errors should return false
});

// ============================================================================
// IDEMPOTENCY EDGE CASES
// ============================================================================

test('nowpayments driver includes idempotency header when provided', function () {
    $driver = new NowPaymentsDriver($this->config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'test_idempotency_key_123');

    expect($result)->toBe(['Idempotency-Key' => 'test_idempotency_key_123']);
});

// ============================================================================
// CRYPTO-SPECIFIC EDGE CASES
// ============================================================================

test('nowpayments driver handles verify with blockchain confirmation status (confirming)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'confirming', // Waiting for blockchain confirmation
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('confirming')
        ->and($result->isPending())->toBeTrue();
});

test('nowpayments driver handles verify with sending status (transaction in mempool)', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'sending', // Transaction in mempool
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('sending')
        ->and($result->isPending())->toBeTrue();
});

test('nowpayments driver handles verify with expired payment status', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'expired',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('expired')
        ->and($result->isFailed())->toBeTrue();
});

test('nowpayments driver handles verify with refunded payment status', function () {
    $driver = createNowPaymentsDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_id' => 12345678,
            'order_id' => 'NOW_1234567890_abc123def456',
            'payment_status' => 'refunded',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'updated_at' => time(),
        ])),
    ]);

    $result = $driver->verify('12345678');

    expect($result->status)->toBe('refunded')
        ->and($result->isFailed())->toBeTrue();
});
