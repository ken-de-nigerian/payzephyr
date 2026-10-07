<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/*
 * What OPayDriver sends to OPay, what it makes of the answers, and what it
 * logs - including the signed status request verify() makes.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function opayContractDriver(array $responses, array &$history = [], array $config = []): OPayDriver
{
    $driver = new OPayDriver($config + ['merchant_id' => 'M_1', 'public_key' => 'OPAYPUB_1', 'secret_key' => 'OPAYPRV_1', 'currencies' => ['NGN']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function opayCashier(array $data = ['cashierUrl' => 'https://cashier.opaycheckout.com/x', 'orderNo' => 'ORD_1']): Response
{
    return new Response(200, [], (string) json_encode(['code' => '00000', 'data' => $data]));
}

function opayCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 150.5, 'currency' => 'NGN', 'email' => 'a@b.com', 'reference' => 'OPAY_1', 'callbackUrl' => 'https://shop.test/return']);
}

function opayStatus(array $data): Response
{
    return new Response(200, [], (string) json_encode(['code' => '00000', 'data' => $data + ['amount' => ['total' => 25000, 'currency' => 'NGN']]]));
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends OPay its full payload with the merchant\'s headers', function (): void {
    $history = [];
    opayContractDriver([opayCashier()], $history)->charge(opayCharge([
        'metadata' => ['name' => 'Ada', 'description' => 'Order 9'], 'channels' => ['card'], 'idempotencyKey' => 'idem-1',
    ]));

    $request = $history[0]['request'];
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'country' => 'NG',
        'reference' => 'OPAY_1',
        'amount' => ['total' => '15050', 'currency' => 'NGN'],
        'callbackUrl' => 'https://shop.test/return?reference=OPAY_1',
        'returnUrl' => 'https://shop.test/return?reference=OPAY_1',
        'cancelUrl' => 'https://shop.test/return?reference=OPAY_1',
        'displayName' => 'Ada',
        'userInfo' => ['userEmail' => 'a@b.com', 'userName' => 'Ada'],
        'product' => ['name' => 'Product', 'description' => 'Order 9'],
        'metadata' => ['name' => 'Ada', 'description' => 'Order 9', 'reference' => 'OPAY_1'],
        'payMethod' => ['CARD'],
    ])
        ->and((string) $request->getUri())->toEndWith('/api/v1/international/cashier/create')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer OPAYPUB_1')
        ->and($request->getHeaderLine('MerchantId'))->toBe('M_1')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-1');
});

test('a charge without a name, description or callback falls back, and leaves the callbacks out', function (): void {
    $history = [];
    opayContractDriver([opayCashier()], $history)->charge(opayCharge(['callbackUrl' => null]));

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['displayName'])->toBe('a@b.com')
        ->and($sent['userInfo']['userName'])->toBe('a@b.com')
        ->and($sent['product']['description'])->toBe('Payment for OPAY_1')
        ->and($sent)->not->toHaveKeys(['callbackUrl', 'returnUrl', 'cancelUrl', 'payMethod']);
});

test('a charge without a reference gets one of its own', function (): void {
    $history = [];
    $result = opayContractDriver([opayCashier()], $history)->charge(opayCharge(['reference' => null]));

    expect($result->reference)->toMatch('/^OPAY_\d+_[0-9a-f]{16}$/')
        ->and(json_decode((string) $history[0]['request']->getBody(), true)['reference'])->toBe($result->reference);
});

test('the checkout link and order are read from whichever field OPay used', function (array $data, string $url, string $order): void {
    $result = opayContractDriver([opayCashier($data)])->charge(opayCharge());

    expect($result->authorizationUrl)->toBe($url)
        ->and($result->accessCode)->toBe($order);
})->with([
    'cashierUrl, orderNo' => [['cashierUrl' => 'https://c/1', 'orderNo' => 'O1'], 'https://c/1', 'O1'],
    'paymentUrl, orderNumber' => [['paymentUrl' => 'https://c/2', 'orderNumber' => 'O2'], 'https://c/2', 'O2'],
    'checkoutUrl, no order' => [['checkoutUrl' => 'https://c/3'], 'https://c/3', 'OPAY_1'],
]);

test('an initialized charge is logged with its reference', function (): void {
    $logs = captureLogs();
    opayContractDriver([opayCashier()])->charge(opayCharge());

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'OPAY_1']);
});

test('a charge OPay does not accept is refused with whichever message it gave', function (string $body, string $message): void {
    expect(fn (): ChargeResponseDTO => opayContractDriver([new Response(200, [], $body)])->charge(opayCharge()))->toThrow(ChargeException::class, $message);
})->with([
    'message' => ['{"code":"02000","message":"Invalid merchant"}', 'Invalid merchant'],
    'msg' => ['{"code":"02000","msg":"Invalid amount"}', 'Invalid amount'],
    'no code' => ['{"message":"No code"}', 'No code'],
    'neither' => ['{"code":"02000"}', 'Failed to initialize OPay payment'],
]);

test('an unexpected failure inside a charge is logged and wrapped, coded 0, and leaves no key behind', function (): void {
    $logs = captureLogs();
    $history = [];
    $driver = opayContractDriver([fn () => throw new LogicException('handler blew up'), new Response(200, [], '{}')], $history);

    try {
        $driver->charge(opayCharge(['idempotencyKey' => 'idem-2']));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    try {
        $driver->verify('OPAY_AFTER');
    } catch (Throwable) {
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class])
        ->and($history[0]['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a status request is signed with the secret key and sent for the reference', function (): void {
    $history = [];
    opayContractDriver([opayStatus(['status' => 'SUCCESS'])], $history)->verify('OPAY V/1');

    $request = $history[0]['request'];
    $body = (string) $request->getBody();

    expect(json_decode($body, true))->toBe(['country' => 'NG', 'reference' => 'OPAY V/1'])
        ->and((string) $request->getUri())->toEndWith('/api/v1/international/cashier/status')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer '.hash_hmac('sha512', '{"country":"NG","reference":"OPAY V/1"}', 'OPAYPRV_1'))
        ->and($request->getHeaderLine('MerchantId'))->toBe('M_1');
});

test('a status request without a secret key is refused before it is sent', function (): void {
    $history = [];

    expect(fn (): VerificationResponseDTO => opayContractDriver([], $history, ['secret_key' => ''])->verify('OPAY_X'))
        ->toThrow(VerificationException::class, 'OPay secret key (private key) is required for status API authentication')
        ->and($history)->toBe([]);
});

test('a verified payment is read from whichever field OPay used, and logged', function (): void {
    $logs = captureLogs();
    $result = opayContractDriver([opayStatus([
        'orderStatus' => 'success', 'orderNo' => 'ORD_V', 'createTime' => 1_759_312_800, 'metadata' => ['order' => 1],
        'instrumentType' => 'BankCard', 'email' => 'a@b.com', 'name' => 'Ada',
    ])])->verify('OPAY_V');

    expect($result->reference)->toBe('ORD_V')
        ->and($result->status)->toBe('success')
        ->and($result->amount)->toBe(250.0)
        ->and($result->paidAt)->toBe(date('Y-m-d H:i:s', 1_759_312_800))
        ->and($result->metadata)->toBe(['order' => 1])
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada'])
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'OPAY_V', 'status' => 'success']);
});

test('a verified payment prefers OPay\'s own names when it gives them', function (): void {
    $result = opayContractDriver([opayStatus([
        'status' => 'PENDING', 'reference' => 'REF_V', 'orderNo' => 'ORD_V', 'customerEmail' => 'c@d.com', 'customerName' => 'Bo', 'email' => 'x', 'name' => 'y',
    ])])->verify('OPAY_V');

    expect($result->reference)->toBe('REF_V')
        ->and($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull()
        ->and($result->customer)->toBe(['email' => 'c@d.com', 'name' => 'Bo']);
});

test('a payment OPay reports no status for, or no reference, is read as unknown and by the one asked for', function (): void {
    expect(opayContractDriver([opayStatus([])])->verify('OPAY_ASKED'))
        ->reference->toBe('OPAY_ASKED')
        ->status->toBe('unknown');
});

test('a verification OPay does not accept is refused with whichever message it gave', function (string $body, string $message): void {
    expect(fn (): VerificationResponseDTO => opayContractDriver([new Response(200, [], $body)])->verify('OPAY_X'))->toThrow(VerificationException::class, $message);
})->with([
    'message' => ['{"code":"02001","message":"Order not found"}', 'Order not found'],
    'msg' => ['{"code":"02001","msg":"Bad signature"}', 'Bad signature'],
    'no code' => ['{"message":"No code"}', 'No code'],
]);

test('a verification that fails on the way is logged and wrapped, coded 0', function (): void {
    $logs = captureLogs();

    try {
        opayContractDriver([fn () => throw new LogicException('handler blew up')])->verify('OPAY_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'OPAY_B', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks and health
// ---------------------------------------------------------------------------

test('the signature is read from every header OPay has used', function (string $header): void {
    $body = '{"payload":{"reference":"OPAY_1"}}';

    expect(opayContractDriver([])->validateWebhook([$header => [hash_hmac('sha256', $body, 'OPAYPRV_1')]], $body))->toBeTrue();
})->with(['x-opay-signature', 'X-OPay-Signature', 'signature', 'Signature']);

test('each webhook outcome is logged, and each refusal is final', function (): void {
    $logs = captureLogs();
    $driver = opayContractDriver([]);
    $body = '{"payload":{"reference":"OPAY_1"}}';

    expect($driver->validateWebhook([], $body))->toBeFalse()
        ->and($driver->validateWebhook(['signature' => ['forged']], $body))->toBeFalse()
        ->and(opayContractDriver([], config: ['secret_key' => ''])->validateWebhook(['signature' => ['x']], $body))->toBeFalse()
        ->and($driver->validateWebhook(['signature' => [hash_hmac('sha256', $body, 'OPAYPRV_1')]], $body))->toBeTrue();

    $opay = array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[opay]')));
    expect($opay)->toBe([
        '[opay] Webhook signature missing',
        '[opay] Webhook validation failed',
        '[opay] OPay secret key not configured for webhook validation',
        '[opay] Webhook validated successfully',
    ])->and(loggedEntry($logs, 'Webhook validation failed')['context'])->toBe(['valid' => false]);
});

test('the health check counts OPay\'s 400 and 404 as healthy, and logs anything else', function (int $status, bool $healthy): void {
    $logs = captureLogs();
    $driver = opayContractDriver([new ClientException('Client error', new Request('POST', '/x'), new Response($status))]);

    expect($driver->healthCheck())->toBe($healthy)
        ->and(loggedEntry($logs, $healthy ? 'Health check successful' : 'Health check failed')['level'])->toBe($healthy ? 'info' : 'error');

    if (! $healthy) {
        expect(loggedEntry($logs, 'Health check failed')['context']['error'])->toBeString()->not->toBeEmpty();
    }
})->with([
    'bad request' => [400, true],
    'not found' => [404, true],
    'unauthorized' => [401, false],
]);

test('a request without a JSON body still says it is JSON', function (): void {
    $history = [];
    opayContractDriver([new Response(200, [], '{}')], $history)->healthCheck();

    expect($history[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json');
});
