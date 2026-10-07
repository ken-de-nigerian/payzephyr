<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/*
 * What RazorpayDriver sends to Razorpay, what it makes of the answers, and
 * what it logs - payment links, their notes and customers, lookups by
 * reference, and Razorpay's own error descriptions.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function razorpayContractDriver(array $responses = [], array &$history = [], array $config = []): RazorpayDriver
{
    $driver = new RazorpayDriver($config + ['key_id' => 'rzp_test_1', 'key_secret' => 'rzp_secret', 'webhook_secret' => 'rzp_whsec', 'currencies' => ['INR']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function razorpayLink(array $data = []): Response
{
    return new Response(200, [], (string) json_encode($data + ['id' => 'plink_1', 'short_url' => 'https://rzp.io/i/x', 'status' => 'created']));
}

function razorpayCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 10.5, 'currency' => 'inr', 'email' => 'a@b.com', 'reference' => 'RZP_1', 'callbackUrl' => 'https://shop.test/return']);
}

function razorpaySent(array $history, int $i = 0): array
{
    return json_decode((string) $history[$i]['request']->getBody(), true);
}

function razorpayRequestError(string $body, ?Throwable $previous = null): RequestException
{
    return new RequestException('Bad Request', new Request('POST', '/v1/payment_links'), new Response(400, [], $body), $previous);
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Razorpay a full payment link, authenticated, as JSON', function (): void {
    $history = [];
    $result = razorpayContractDriver([razorpayLink(['status' => 'issued'])], $history)->charge(razorpayCharge([
        'description' => 'Order 9', 'customer' => ['name' => 'Ada', 'phone' => '+919000000000'], 'metadata' => ['order' => 9], 'channels' => ['card', 'upi'],
    ]));

    $request = $history[0]['request'];
    expect(razorpaySent($history))->toBe([
        'amount' => 1050,
        'currency' => 'INR',
        'reference_id' => 'RZP_1',
        'description' => 'Order 9',
        'customer' => ['email' => 'a@b.com', 'name' => 'Ada', 'contact' => '+919000000000'],
        'notify' => ['sms' => false, 'email' => false],
        'notes' => ['payzephyr_reference' => 'RZP_1', 'order' => '9'],
        'callback_url' => 'https://shop.test/return?reference=RZP_1',
        'callback_method' => 'get',
        'options' => ['checkout' => ['method' => ['card' => true, 'netbanking' => false, 'upi' => true, 'wallet' => false]]],
    ])
        ->and((string) $request->getUri())->toEndWith('/v1/payment_links')
        ->and($request->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('rzp_test_1:rzp_secret'))
        ->and($result->status)->toBe('issued')
        ->and($result->metadata)->toBe(['order' => 9, 'razorpay_payment_link_id' => 'plink_1']);
});

test('a charge without a description, customer details, callback or methods sends only what it has', function (): void {
    $history = [];
    $result = razorpayContractDriver([razorpayLink(['status' => null])], $history)->charge(razorpayCharge(['callbackUrl' => null]));

    $sent = razorpaySent($history);
    expect($sent['description'])->toBe('Payment')
        ->and($sent['customer'])->toBe(['email' => 'a@b.com'])
        ->and($sent)->not->toHaveKeys(['callback_url', 'callback_method', 'options'])
        // No status reads as a new link, which is pending.
        ->and($result->status)->toBe('pending');
});

test('a customer\'s contact is taken from phone or contact, and numbers are sent as text', function (array $customer, array $expected): void {
    $history = [];
    razorpayContractDriver([razorpayLink()], $history)->charge(razorpayCharge(['customer' => $customer]));

    expect(razorpaySent($history)['customer'])->toBe(['email' => 'a@b.com'] + $expected);
})->with([
    'contact' => [['contact' => '+919111111111'], ['contact' => '+919111111111']],
    'numbers' => [['name' => 42, 'phone' => 9000000000], ['name' => '42', 'contact' => '9000000000']],
    'not scalar' => [['name' => ['x'], 'phone' => ['y']], []],
]);

test('notes carry the reference first, then scalar metadata as text, at most fifteen and 256 characters each', function (): void {
    $history = [];
    $metadata = ['nested' => ['x'], 'payzephyr_reference' => 'spoofed', 'flag' => true, 'long' => str_repeat('a', 300)];
    for ($i = 1; $i <= 20; $i++) {
        $metadata["k$i"] = $i;
    }

    razorpayContractDriver([razorpayLink()], $history)->charge(razorpayCharge(['metadata' => $metadata]));

    $notes = razorpaySent($history)['notes'];
    expect($notes)->toHaveCount(15)
        ->and(array_slice($notes, 0, 4, true))->toBe(['payzephyr_reference' => 'RZP_1', 'flag' => '1', 'long' => str_repeat('a', 256), 'k1' => '1'])
        ->and(array_key_last($notes))->toBe('k12');
});

test('an amount is sent in the currency\'s minor unit, rounded', function (float $amount, string $currency, int $minor): void {
    $history = [];
    razorpayContractDriver([razorpayLink()], $history)->charge(razorpayCharge(['amount' => $amount, 'currency' => $currency]));

    expect(razorpaySent($history)['amount'])->toBe($minor);
})->with([
    'rounds up' => [10.006, 'INR', 1001],
    'rounds down' => [10.004, 'INR', 1000],
    'zero-decimal up' => [1000.6, 'JPY', 1001],
    'zero-decimal down' => [1000.4, 'JPY', 1000],
    'thousandths, to the hundredth' => [10.006, 'KWD', 10010],
]);

test('a reference longer than Razorpay takes is refused before anything is sent', function (): void {
    $history = [];
    $reference = str_repeat('R', 41);

    expect(fn (): ChargeResponseDTO => razorpayContractDriver([], $history)->charge(razorpayCharge(['reference' => $reference])))
        ->toThrow(ChargeException::class, "Razorpay references are limited to 40 characters; [$reference] is 41.")
        ->and($history)->toBe([]);
});

test('an initialized charge is logged with both references, and traced under the charge\'s', function (): void {
    config(['payments.features.trace' => true, 'payments.trace.async' => false]);
    app()->forgetInstance('payments.config');
    $logs = captureLogs();

    razorpayContractDriver([razorpayLink()])->charge(razorpayCharge());

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'RZP_1', 'razorpay_payment_link_id' => 'plink_1'])
        ->and(PaymentTraceEvent::where('reference', 'RZP_1')->where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->exists())->toBeTrue();
});

test('a charge Razorpay refuses carries Razorpay\'s own description, coded 0', function (): void {
    try {
        razorpayContractDriver([razorpayRequestError('{"error":{"description":"The amount must be atleast INR 1.00"}}')])->charge(razorpayCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toEndWith(' Razorpay: The amount must be atleast INR 1.00')
            ->and($e->getMessage())->not->toStartWith(' Razorpay:')
            ->and($e->getCode())->toBe(0);
    }
});

test('Razorpay\'s description is added only when it gave one, from the first answer in the chain', function (string $body, ?string $innerBody): void {
    $inner = $innerBody === null ? null : razorpayRequestError($innerBody);

    try {
        razorpayContractDriver([razorpayRequestError($body, $inner)])->charge(razorpayCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->not->toContain('Razorpay:');
    }
})->with([
    'empty description' => ['{"error":{"description":""}}', null],
    'error not an object' => ['{"error":"bad"}', null],
    'only a deeper answer has one' => ['{"error":{}}', '{"error":{"description":"deeper"}}'],
]);

test('an unexpected failure inside a charge is logged and wrapped, coded 0, and leaves no request behind', function (): void {
    $logs = captureLogs();
    $driver = razorpayContractDriver([fn () => throw new LogicException('handler blew up')]);

    try {
        $driver->charge(razorpayCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class])
        ->and((new ReflectionClass($driver))->getProperty('currentRequest')->getValue($driver))->toBeNull();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

function razorpayFullLink(array $data = []): Response
{
    return new Response(200, [], (string) json_encode($data + [
        'id' => 'plink_V', 'reference_id' => 'RZP_V', 'status' => 'paid', 'amount' => 1050, 'currency' => 'inr',
        'notes' => ['order' => '1'], 'customer' => ['email' => 'a@b.com', 'name' => 'Ada', 'contact' => '+91900'],
        'payments' => ['junk', ['status' => 'failed', 'method' => 'card'], ['status' => 'captured', 'method' => 'upi', 'created_at' => 1_759_312_800]],
    ]));
}

test('a link is verified by its id and read in full, its payment the settled one', function (): void {
    $logs = captureLogs();
    $history = [];
    $result = razorpayContractDriver([razorpayFullLink()], $history)->verify('plink_V');

    expect((string) $history[0]['request']->getUri())->toEndWith('/v1/payment_links/plink_V')
        ->and($history[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($history[0]['request']->getHeaderLine('Accept'))->toBe('application/json')
        ->and($result->reference)->toBe('RZP_V')
        ->and($result->status)->toBe('success')
        ->and($result->currency)->toBe('INR')
        ->and($result->amount)->toBe(10.5)
        ->and($result->channel)->toBe('upi')
        ->and($result->paidAt)->toBe(date('c', 1_759_312_800))
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada', 'contact' => '+91900'])
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'plink_V', 'status' => 'paid']);
});

test('a refunded payment counts as the settled one', function (): void {
    $result = razorpayContractDriver([razorpayFullLink(['payments' => [['status' => 'refunded', 'method' => 'netbanking']]])])->verify('plink_V');

    expect($result->channel)->toBe('netbanking');
});

test('a link with no reference or status of its own is read by the one asked for, as unknown', function (): void {
    $result = razorpayContractDriver([razorpayFullLink(['reference_id' => null, 'status' => null])])->verify('plink_V');

    expect($result->reference)->toBe('plink_V')
        ->and($result->status)->toBe('unknown');
});

test('a link is found by its reference, then fetched by its id', function (): void {
    $history = [];
    $result = razorpayContractDriver([new Response(200, [], '{"payment_links":[{"id":"plink_V"}]}'), razorpayFullLink()], $history)->verify('RZP_V');

    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query)->toBe(['reference_id' => 'RZP_V'])
        ->and((string) $history[1]['request']->getUri())->toEndWith('/v1/payment_links/plink_V')
        ->and($result->reference)->toBe('RZP_V');
});

test('a reference that finds no link, several, or one without an id is refused, saying which', function (string $body, string $message): void {
    expect(fn (): VerificationResponseDTO => razorpayContractDriver([new Response(200, [], $body)])->verify('RZP_X'))->toThrow(VerificationException::class, $message);
})->with([
    'none' => ['{"payment_links":[]}', "Expected exactly one Razorpay payment link for reference [RZP_X], found 0. Razorpay's reference_id lookup can trail link creation by a few seconds; the plink_ id is always current."],
    'several' => ['{"payment_links":[{"id":"plink_1"},{"id":"plink_2"}]}', 'Expected exactly one Razorpay payment link for reference [RZP_X], found 2'],
    'not a link' => ['{"payment_links":["plink_1"]}', 'Expected exactly one Razorpay payment link for reference [RZP_X], found 1'],
    'no id' => ['{"payment_links":[{"status":"paid"}]}', 'Razorpay returned a payment link without an id for reference [RZP_X]'],
    'not a plink id' => ['{"payment_links":[{"id":"inv_1"}]}', 'Razorpay returned a payment link without an id for reference [RZP_X]'],
]);

test('several links found is not described as a delay', function (): void {
    try {
        razorpayContractDriver([new Response(200, [], '{"payment_links":[{"id":"plink_1"},{"id":"plink_2"}]}')])->verify('RZP_X');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Expected exactly one Razorpay payment link for reference [RZP_X], found 2');
    }
});

test('a verification that fails on the way is logged and wrapped with Razorpay\'s description, coded 0', function (): void {
    $logs = captureLogs();

    try {
        razorpayContractDriver([razorpayRequestError('{"error":{"description":"Link not found"}}')])->verify('plink_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toStartWith('Payment verification failed: ')
            ->and($e->getMessage())->toEndWith(' Razorpay: Link not found')
            ->and($e->getCode())->toBe(0);
    }

    $context = loggedEntry($logs, 'Verification failed')['context'];
    expect($context['reference'])->toBe('plink_B')
        ->and($context['error_class'])->toBe(ChargeException::class)
        ->and($context['error'])->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------

test('the signature header is read in either case', function (string $header): void {
    $body = '{"event":"payment_link.paid"}';

    expect(razorpayContractDriver()->validateWebhook([$header => [hash_hmac('sha256', $body, 'rzp_whsec')]], $body))->toBeTrue();
})->with(['x-razorpay-signature', 'X-Razorpay-Signature']);

test('each webhook outcome is logged, and each refusal is final', function (): void {
    $logs = captureLogs();
    $driver = razorpayContractDriver();
    $sign = fn (string $body): array => ['x-razorpay-signature' => [hash_hmac('sha256', $body, 'rzp_whsec')]];

    expect(razorpayContractDriver(config: ['webhook_secret' => null])->validateWebhook(['x-razorpay-signature' => ['x']], '{}'))->toBeFalse()
        ->and($driver->validateWebhook([], '{}'))->toBeFalse()
        ->and($driver->validateWebhook(['x-razorpay-signature' => ['forged']], '{}'))->toBeFalse()
        ->and($driver->validateWebhook($sign('"text"'), '"text"'))->toBeFalse()
        ->and($driver->validateWebhook($sign('{}'), '{}'))->toBeTrue();

    $razorpay = array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[razorpay]')));
    expect($razorpay)->toBe([
        '[razorpay] Webhook rejected: no webhook secret configured',
        '[razorpay] Webhook signature missing',
        '[razorpay] Webhook signature invalid',
        '[razorpay] Webhook body is not a JSON object',
        '[razorpay] Webhook validated successfully',
    ])
        ->and(loggedEntry($logs, 'no webhook secret configured')['context']['hint'])->toContain('RAZORPAY_WEBHOOK_SECRET')
        ->and(loggedEntry($logs, 'Webhook signature invalid')['context']['hint'])->toContain('not the API key secret');
});

test('only a payment link event names a payment link\'s reference and status', function (): void {
    $driver = razorpayContractDriver();
    $entity = ['payload' => ['payment_link' => ['entity' => ['reference_id' => 'RZP_W', 'status' => 'paid']]]];

    expect($driver->extractWebhookReference(['event' => 'payment_link.paid'] + $entity))->toBe('RZP_W')
        ->and($driver->extractWebhookStatus(['event' => 'payment_link.paid'] + $entity))->toBe('paid')
        ->and($driver->extractWebhookReference(['event' => 'refund.processed'] + $entity))->toBeNull()
        ->and($driver->extractWebhookStatus(['event' => 'refund.processed'] + $entity))->toBe('unknown')
        ->and($driver->extractWebhookReference($entity))->toBeNull();
});

test('an event id leaves out the parts a delivery does not have', function (): void {
    expect(razorpayContractDriver()->extractWebhookEventId(['event' => '', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_1']], 'payment' => ['entity' => ['id' => 'pay_1']]]]))
        ->toBe('plink_1:pay_1');
});

// ---------------------------------------------------------------------------
// Health
// ---------------------------------------------------------------------------

test('the health check lists one link, counts Razorpay\'s 400 and 404 as healthy, and anything else as down', function (): void {
    $logs = captureLogs();
    $history = [];
    $request = new Request('GET', '/v1/payment_links');

    razorpayContractDriver([new Response(200, [], '{}')], $history)->healthCheck();
    parse_str($history[0]['request']->getUri()->getQuery(), $query);

    expect($query)->toBe(['count' => '1'])
        ->and(razorpayContractDriver([new ClientException('Not Found', $request, new Response(404))])->healthCheck())->toBeTrue()
        ->and(loggedEntry($logs, 'Health check successful')['level'])->toBe('info')
        ->and(razorpayContractDriver([fn () => throw new RuntimeException('not ours', 0, new ClientException('x', $request, new Response(400)))])->healthCheck())->toBeFalse()
        ->and(loggedEntry($logs, 'Health check failed')['context'])->toBe(['error' => 'not ours']);
});

test('a note keeps a numeric metadata key as it was given', function (): void {
    $history = [];
    razorpayContractDriver([razorpayLink()], $history)->charge(razorpayCharge(['metadata' => [7 => 'seven']]));

    expect(razorpaySent($history)['notes'])->toBe(['payzephyr_reference' => 'RZP_1', '7' => 'seven']);
});
