<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;

/*
 * What StripeDriver asks of the Stripe SDK, what it makes of the answers,
 * and what it logs. The SDK is replaced by a recorder that answers from
 * scripted responses and keeps every call it was given.
 */

final class StripeRecorder
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $answers method => answer, or a Throwable to throw */
    public function __construct(private array $answers) {}

    public function __call(string $method, array $args): mixed
    {
        $this->calls[] = [$method, $args];
        $answer = $this->answers[$method] ?? null;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return is_callable($answer) ? $answer(...$args) : $answer;
    }
}

/**
 * @param  array<string, array<string, mixed>>  $services  service => [method => answer]
 * @return array{0: StripeDriver, 1: array<string, StripeRecorder>}
 */
function stripeContractDriver(array $services = [], array $config = []): array
{
    $driver = new StripeDriver($config + ['secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_1', 'currencies' => ['USD']]);
    $recorders = [];
    foreach (['sessions', 'paymentIntents', 'balance'] as $name) {
        $recorders[$name] = new StripeRecorder($services[$name] ?? []);
    }

    $driver->setStripeClient((object) [
        'checkout' => (object) ['sessions' => $recorders['sessions']],
        'paymentIntents' => $recorders['paymentIntents'],
        'balance' => $recorders['balance'],
    ]);

    return [$driver, $recorders];
}

function stripeSession(array $values = []): Session
{
    return Session::constructFrom($values + [
        'id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/cs_1', 'client_reference_id' => 'STRIPE_1',
        'payment_status' => 'paid', 'amount_total' => 1050, 'currency' => 'usd', 'created' => 1_759_312_800,
        'metadata' => ['order' => '9'], 'payment_method_types' => ['card', 'link'], 'customer_email' => 'a@b.com',
    ]);
}

function stripeIntent(array $values = []): PaymentIntent
{
    return PaymentIntent::constructFrom($values + [
        'id' => 'pi_1', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'eur', 'created' => 1_759_312_800,
        'metadata' => ['reference' => 'STRIPE_PI'], 'payment_method_types' => ['card'], 'receipt_email' => 'c@d.com',
    ]);
}

function stripeCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 10.5, 'currency' => 'USD', 'email' => 'a@b.com', 'reference' => 'STRIPE_1', 'callbackUrl' => 'https://shop.test/return']);
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge asks Stripe for a checkout session with everything it needs', function () {
    [$driver, $stripe] = stripeContractDriver(['sessions' => ['create' => stripeSession()]]);

    $result = $driver->charge(stripeCharge([
        'description' => 'Order 9', 'channels' => ['bank_transfer'], 'idempotencyKey' => 'idem-1',
        'metadata' => ['note' => 'x', 'count' => 3, 'ratio' => 1.5, 'gift' => true, 'empty' => null, 'cart' => ['a' => 1]],
    ]));

    [$method, [$params, $options]] = $stripe['sessions']->calls[0];
    expect($method)->toBe('create')
        ->and($params)->toBe([
            'payment_method_types' => ['us_bank_account'],
            'line_items' => [[
                'price_data' => ['currency' => 'usd', 'product_data' => ['name' => 'Order 9'], 'unit_amount' => 1050],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => 'https://shop.test/return?status=success&reference=STRIPE_1',
            'cancel_url' => 'https://shop.test/return?status=cancelled&reference=STRIPE_1',
            'client_reference_id' => 'STRIPE_1',
            'customer_email' => 'a@b.com',
            'metadata' => [
                'note' => 'x', 'count' => '3', 'ratio' => '1.5', 'gift' => 'true', 'empty' => '', 'cart' => '{"a":1}', 'reference' => 'STRIPE_1',
            ],
            'payment_intent_data' => ['metadata' => [
                'note' => 'x', 'count' => '3', 'ratio' => '1.5', 'gift' => 'true', 'empty' => '', 'cart' => '{"a":1}', 'reference' => 'STRIPE_1',
            ]],
        ])
        ->and($options)->toBe(['idempotency_key' => 'idem-1'])
        ->and($result->metadata)->toBe(['session_id' => 'cs_1']);
});

test('a charge without a description or channels says "Payment", offers cards, and sends no key it was not given', function () {
    [$driver, $stripe] = stripeContractDriver(['sessions' => ['create' => stripeSession()]]);

    $driver->charge(stripeCharge());

    [, [$params, $options]] = $stripe['sessions']->calls[0];
    expect($params['payment_method_types'])->toBe(['card'])
        ->and($params['line_items'][0]['price_data']['product_data']['name'])->toBe('Payment')
        ->and($options)->toBe([]);
});

test('a charge without a callback URL is refused, saying how to set one', function () {
    [$driver] = stripeContractDriver();

    expect(fn () => $driver->charge(stripeCharge(['callbackUrl' => null])))->toThrow(
        InvalidConfigurationException::class,
        'Stripe requires a callback URL for its redirect flow. Please use ->callback() in your payment chain to set the callback URL.'
    );
});

test('an initialized charge is logged with its references and whether it was idempotent', function (?string $key, bool $idempotent) {
    $logs = captureLogs();
    [$driver] = stripeContractDriver(['sessions' => ['create' => stripeSession()]]);

    $driver->charge(stripeCharge(['idempotencyKey' => $key]));

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'STRIPE_1', 'session_id' => 'cs_1', 'idempotent' => $idempotent]);
})->with([
    'with a key' => ['idem-2', true],
    'without' => [null, false],
]);

test('a charge Stripe refuses, or that fails otherwise, is logged and wrapped, coded 0', function (Throwable $failure, array $context) {
    $logs = captureLogs();
    [$driver] = stripeContractDriver(['sessions' => ['create' => $failure]]);

    try {
        $driver->charge(stripeCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Stripe charge failed: '.$failure->getMessage())
            ->and($e->getCode())->toBe(0)
            ->and($e->getPrevious())->toBe($failure);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe($context);
})->with([
    'refused by Stripe' => [InvalidRequestException::factory('No such price'), ['error' => 'No such price']],
    'anything else' => [new LogicException('sdk blew up'), ['error' => 'sdk blew up', 'error_class' => LogicException::class]],
]);

test('a session without a URL is refused', function () {
    [$driver] = stripeContractDriver(['sessions' => ['create' => stripeSession(['url' => null])]]);

    expect(fn () => $driver->charge(stripeCharge()))
        ->toThrow(ChargeException::class, 'Stripe created checkout session [cs_1] without a URL to redirect the customer to');
});

test('a Stripe driver builds no HTTP client: it talks to Stripe through the SDK alone', function () {
    $driver = new StripeDriver(['secret_key' => 'sk_test_1', 'currencies' => ['USD']]);

    expect((new ReflectionClass($driver))->getProperty('client')->isInitialized($driver))->toBeFalse()
        ->and((new ReflectionClass($driver))->getMethod('getDefaultHeaders')->invoke($driver))->toBe([]);
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a checkout session id is retrieved with its payment intent, and read in full', function () {
    [$driver, $stripe] = stripeContractDriver(['sessions' => ['retrieve' => stripeSession()]]);

    $result = $driver->verify('cs_1');

    expect($stripe['sessions']->calls[0])->toBe(['retrieve', ['cs_1', ['expand' => ['payment_intent']]]])
        ->and($result->reference)->toBe('STRIPE_1')
        ->and($result->status)->toBe('success')
        ->and($result->amount)->toBe(10.5)
        ->and($result->currency)->toBe('USD')
        ->and($result->paidAt)->toBe(date('Y-m-d H:i:s', 1_759_312_800))
        ->and($result->metadata)->toBe(['order' => '9'])
        ->and($result->channel)->toBe('card,link')
        ->and($result->customer)->toBe(['email' => 'a@b.com']);
});

test('a session reads its status, amount and details whatever Stripe left out', function () {
    [$driver] = stripeContractDriver(['sessions' => ['retrieve' => Session::constructFrom([
        'id' => 'cs_2', 'payment_status' => 'unpaid', 'currency' => 'usd', 'created' => 1, 'payment_intent' => ['amount' => 700],
    ])]]);

    $result = $driver->verify('cs_2');

    expect($result->reference)->toBe('cs_2')
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(7.0)
        ->and($result->paidAt)->toBeNull()
        ->and($result->metadata)->toBe([])
        ->and($result->channel)->toBe('')
        ->and($result->customer)->toBe(['email' => null]);
});

test('a session neither paid nor unpaid has failed, and one without a currency cannot be verified', function () {
    [$driver] = stripeContractDriver(['sessions' => ['retrieve' => stripeSession(['payment_status' => 'no_payment_required'])]]);
    [$noCurrency] = stripeContractDriver(['sessions' => ['retrieve' => stripeSession(['currency' => null])]]);

    expect($driver->verify('cs_1')->status)->toBe('failed')
        ->and(fn () => $noCurrency->verify('cs_1'))->toThrow(VerificationException::class, 'omitted the required field [currency]');
});

test('a payment intent id is retrieved directly, and read in full', function () {
    [$driver, $stripe] = stripeContractDriver(['paymentIntents' => ['retrieve' => stripeIntent()]]);

    $result = $driver->verify('pi_1');

    expect($stripe['paymentIntents']->calls[0])->toBe(['retrieve', ['pi_1']])
        ->and($result->reference)->toBe('STRIPE_PI')
        ->and($result->status)->toBe('success')
        ->and($result->amount)->toBe(20.0)
        ->and($result->currency)->toBe('EUR')
        ->and($result->paidAt)->toBe(date('Y-m-d H:i:s', 1_759_312_800))
        ->and($result->metadata)->toBe(['reference' => 'STRIPE_PI'])
        ->and($result->channel)->toBe('card')
        ->and($result->customer)->toBe(['email' => 'c@d.com']);
});

test('a payment intent without a reference of its own is read by its id', function () {
    [$driver] = stripeContractDriver(['paymentIntents' => ['retrieve' => stripeIntent(['metadata' => [], 'status' => 'processing'])]]);

    $result = $driver->verify('pi_1');

    expect($result->reference)->toBe('pi_1')
        ->and($result->status)->toBe('pending')
        ->and($result->paidAt)->toBeNull();
});

/**
 * A page of checkout sessions as Stripe lists them.
 *
 * @param  list<Session>  $sessions
 */
function stripeSessionPage(array $sessions, bool $hasMore = false): object
{
    return (object) ['data' => $sessions, 'has_more' => $hasMore];
}

/** A search that finds nothing, as Stripe answers it. */
function stripeNoIntents(): array
{
    return ['search' => (object) ['data' => []]];
}

test('a reference is searched for in payment intent metadata first, quotes escaped, and no session is read when it is found', function () {
    [$driver, $stripe] = stripeContractDriver([
        'paymentIntents' => ['search' => (object) ['data' => [stripeIntent(['id' => 'pi_match', 'metadata' => ['reference' => "O'Brien\\1"]]), stripeIntent(['id' => 'pi_second'])]]],
    ]);

    $result = $driver->verify("O'Brien\\1");

    expect($result->reference)->toBe("O'Brien\\1")
        ->and($stripe['paymentIntents']->calls)->toBe([['search', [[
            'query' => "metadata['reference']:'O\\'Brien\\\\1'",
            'limit' => 1,
        ]]]])
        ->and($stripe['sessions']->calls)->toBe([]);
});

test('a reference the search does not find is looked for among recent sessions a page of 100 at a time, then fetched with its payment intent', function () {
    [$driver, $stripe] = stripeContractDriver([
        'paymentIntents' => stripeNoIntents(),
        'sessions' => [
            'all' => fn (array $params) => isset($params['starting_after'])
                ? stripeSessionPage([stripeSession(['id' => 'cs_match']), stripeSession(['id' => 'cs_older'])])
                : stripeSessionPage([Session::constructFrom(['id' => 'cs_unnamed']), stripeSession(['id' => 'cs_other', 'client_reference_id' => 'other'])], true),
            'retrieve' => fn (string $id) => stripeSession(['id' => $id]),
        ],
    ]);

    $driver->verify('STRIPE_1');

    expect($stripe['sessions']->calls)->toBe([
        ['all', [['limit' => 100]]],
        ['all', [['limit' => 100, 'starting_after' => 'cs_other']]],
        ['retrieve', ['cs_match', ['expand' => ['payment_intent']]]],
    ]);
});

test('the sessions read stop at verify_search_pages pages, ten unless set, and never fewer than one', function (array $config, int $pages) {
    [$driver, $stripe] = stripeContractDriver([
        'paymentIntents' => stripeNoIntents(),
        'sessions' => ['all' => stripeSessionPage([stripeSession(['id' => 'cs_other', 'client_reference_id' => 'other'])], true)],
    ], $config);

    expect(fn () => $driver->verify('STRIPE_GONE'))->toThrow(VerificationException::class, "among the $pages most recent")
        ->and($stripe['sessions']->calls)->toHaveCount($pages);
})->with([
    'unset' => [[], 10],
    'two' => [['verify_search_pages' => 2], 2],
    'zero' => [['verify_search_pages' => 0], 1],
]);

test('an empty page ends the sessions read even if Stripe says there are more', function () {
    [$driver, $stripe] = stripeContractDriver([
        'paymentIntents' => stripeNoIntents(),
        'sessions' => ['all' => stripeSessionPage([], true)],
    ]);

    expect(fn () => $driver->verify('STRIPE_GONE'))->toThrow(VerificationException::class, 'among the 0 most recent')
        ->and($stripe['sessions']->calls)->toBe([['all', [['limit' => 100]]]]);
});

test('where Stripe refuses the search, the sessions are read alone, and the refusal is logged', function () {
    $logs = captureLogs();
    [$driver, $stripe] = stripeContractDriver([
        'paymentIntents' => ['search' => InvalidRequestException::factory('Search is not available in your region')],
        'sessions' => [
            'all' => stripeSessionPage([stripeSession(['id' => 'cs_match'])]),
            'retrieve' => fn (string $id) => stripeSession(['id' => $id]),
        ],
    ]);

    expect($driver->verify('STRIPE_1')->reference)->toBe('STRIPE_1')
        ->and($stripe['sessions']->calls[1])->toBe(['retrieve', ['cs_match', ['expand' => ['payment_intent']]]])
        ->and(loggedEntry($logs, 'Stripe payment intent search unavailable; reading checkout sessions only'))->toMatchArray([
            'level' => 'warning',
            'context' => ['reference' => 'STRIPE_1', 'error' => 'Search is not available in your region'],
        ]);
});

test('a reference nothing names is not found, saying what was tried, and a lookup that fails is wrapped, coded 0', function (array $search, string $tried) {
    [$driver] = stripeContractDriver([
        'paymentIntents' => $search,
        'sessions' => ['all' => fn (array $params) => isset($params['starting_after'])
            ? stripeSessionPage([stripeSession(['id' => 'cs_3', 'client_reference_id' => 'c'])])
            : stripeSessionPage([stripeSession(['id' => 'cs_1', 'client_reference_id' => 'a']), stripeSession(['id' => 'cs_2', 'client_reference_id' => 'b'])], true)],
    ]);
    [$failing] = stripeContractDriver(['paymentIntents' => ['retrieve' => ApiConnectionException::factory('Stripe is down')]]);

    expect(fn () => $driver->verify('STRIPE_GONE'))->toThrow(
        VerificationException::class,
        "Payment not found for reference [STRIPE_GONE] by searching payment intents$tried or among the 3 most recent Stripe checkout sessions. ".
        "Stripe's search can trail a new payment by a minute; verify with the cs_ or pi_ id to read it directly."
    );

    try {
        $failing->verify('pi_1');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Stripe verification failed: Stripe is down')->and($e->getCode())->toBe(0);
    }
})->with([
    'searched' => [['search' => (object) ['data' => []]], ''],
    'search refused' => [['search' => InvalidRequestException::factory('No search here')], ' (unavailable: No search here)'],
]);

// ---------------------------------------------------------------------------
// Webhooks, health, extraction
// ---------------------------------------------------------------------------

function stripeSignatureHeader(string $body, string $secret = 'whsec_1'): string
{
    $ts = time();

    return "t=$ts,v1=".hash_hmac('sha256', "$ts.$body", $secret);
}

test('the signature header is read in either case', function (string $header) {
    [$driver] = stripeContractDriver();
    $body = '{"id":"evt_1","object":"event","type":"checkout.session.completed"}';

    expect($driver->validateWebhook([$header => [stripeSignatureHeader($body)]], $body))->toBeTrue();
})->with(['stripe-signature', 'Stripe-Signature']);

test('each webhook outcome is logged with what an operator needs, and each refusal is final', function () {
    $logs = captureLogs();
    [$driver] = stripeContractDriver();
    [$noSecret] = stripeContractDriver(config: ['webhook_secret' => null]);
    $body = '{"id":"evt_1","object":"event"}';

    expect($driver->validateWebhook([], $body))->toBeFalse()
        ->and($noSecret->validateWebhook(['stripe-signature' => [stripeSignatureHeader($body)]], $body))->toBeFalse()
        ->and($driver->validateWebhook(['stripe-signature' => [stripeSignatureHeader($body, 'whsec_other')]], $body))->toBeFalse()
        ->and($driver->validateWebhook(['stripe-signature' => [stripeSignatureHeader('not json')]], 'not json'))->toBeFalse()
        ->and($driver->validateWebhook(['stripe-signature' => [stripeSignatureHeader($body)]], $body))->toBeTrue();

    $stripe = array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[stripe]')));
    $failures = array_values(array_filter($logs->getArrayCopy(), fn (array $r): bool => $r['message'] === '[stripe] Webhook validation failed'));

    expect($stripe)->toBe([
        '[stripe] Webhook signature missing',
        '[stripe] Webhook secret not configured',
        '[stripe] Webhook validation failed',
        '[stripe] Webhook validation failed',
        '[stripe] Webhook validated successfully',
    ])
        ->and(loggedEntry($logs, 'Webhook secret not configured')['context']['hint'])->toContain('STRIPE_WEBHOOK_SECRET')
        ->and(array_keys($failures[0]['context']))->toBe(['error', 'hint'])
        ->and($failures[0]['context']['hint'])->toContain('whsec_')
        ->and(array_keys($failures[1]['context']))->toBe(['error', 'exception_type'])
        ->and($failures[1]['context']['exception_type'])->toBe(Stripe\Exception\UnexpectedValueException::class);
});

test('the health check is up when Stripe answers, even to refuse the key, and down on a server error or no answer', function (mixed $answer, bool $healthy) {
    $logs = captureLogs();
    [$driver] = stripeContractDriver(['balance' => ['retrieve' => $answer]]);

    expect($driver->healthCheck())->toBe($healthy);

    if ($answer instanceof Throwable && ! $answer instanceof AuthenticationException) {
        expect(loggedEntry($logs, 'Health check failed')['context'])->toBe(['error' => $answer->getMessage()]);
    }
})->with([
    'answered' => [(object) [], true],
    'key refused' => [AuthenticationException::factory('Invalid API Key', 401), true],
    'bad request' => [InvalidRequestException::factory('Bad', 400), true],
    'server error' => [InvalidRequestException::factory('Down', 500), false],
    'no answer' => [ApiConnectionException::factory('Unreachable'), false],
]);

test('a webhook\'s reference, status and channel are read from its object', function () {
    [$driver] = stripeContractDriver();

    expect($driver->extractWebhookReference(['data' => ['object' => ['metadata' => ['reference' => 'R1'], 'client_reference_id' => 'R2']]]))->toBe('R1')
        ->and($driver->extractWebhookReference(['data' => ['object' => ['client_reference_id' => 'R2']]]))->toBe('R2')
        ->and($driver->extractWebhookReference(['data' => ['object' => []]]))->toBeNull()
        ->and($driver->extractWebhookStatus(['type' => 'checkout.session.completed', 'data' => ['object' => ['status' => 'complete']]]))->toBe('complete')
        ->and($driver->extractWebhookStatus(['type' => 'checkout.session.completed', 'data' => ['object' => []]]))->toBe('checkout.session.completed')
        ->and($driver->extractWebhookStatus([]))->toBe('unknown')
        ->and($driver->extractWebhookChannel(['data' => ['object' => ['payment_method' => 'pm_1']]]))->toBe('pm_1')
        ->and($driver->extractWebhookChannel(['data' => ['object' => []]]))->toBeNull();
});
