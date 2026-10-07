<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\PaymentManager;

/*
 * WebhookRequest is the only thing between the public webhook route and the
 * job: it caps the size of a delivery, checks its signature, and warns when
 * checking is switched off. Each guard is pinned here by what it does to a
 * delivery, and by what it logs - the log is how an operator finds out why a
 * provider's webhooks are being refused.
 */

function signedPaystackBody(string $body): array
{
    return ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_xxx')];
}

/**
 * Make $driver the one the request resolves for "fake".
 */
function useWebhookDriver(DriverInterface $driver): void
{
    $manager = app(PaymentManager::class);
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setValue($manager, ['fake' => $driver]);
}

beforeEach(function (): void {
    config(['payments.webhook.verify_signature' => true, 'payments.webhook.max_payload_size' => 64]);
    app()->forgetInstance('payments.config');
});

test('the payload is the decoded JSON body, whatever content type it came with', function (): void {
    // Sent as text/plain, Laravel parses no input from it: only the body has it.
    $request = makeWebhookRequestFor('paystack', '{"event":"charge.success","data":{"reference":"R1"}}', ['Content-Type' => 'text/plain']);

    expect($request->payload())->toBe(['event' => 'charge.success', 'data' => ['reference' => 'R1']]);
});

test('a delivery declaring a length over the limit is refused, even with a valid signature', function (): void {
    $logs = captureLogs();
    $body = '{"event":"charge.success"}';

    $request = makeWebhookRequestFor('paystack', $body, signedPaystackBody($body) + ['Content-Length' => '65']);

    expect($request->authorize())->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook payload size exceeds limit')['context'])->toBe(['size' => '65', 'max' => 64, 'ip' => '127.0.0.1']);
});

test('a body over the limit is refused, even with a valid signature and no declared length', function (): void {
    $logs = captureLogs();
    $body = (string) json_encode(['event' => 'charge.success', 'data' => ['reference' => str_repeat('x', 60)]]);

    $request = makeWebhookRequestFor('paystack', $body, signedPaystackBody($body));
    $request->headers->remove('Content-Length');

    expect($request->authorize())->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook payload size exceeds limit')['context'])->toBe(['size' => strlen($body), 'max' => 64, 'ip' => '127.0.0.1']);
});

test('the signature check gets every header that has a value, as a list, and none that do not', function (): void {
    $received = null;
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturnUsing(function (array $headers) use (&$received): bool {
        $received = $headers;

        return true;
    });
    useWebhookDriver($driver);

    $request = makeWebhookRequestFor('fake', '{}');
    $request->headers->set('x-signature', ['first', null, 'second']);
    $request->headers->set('x-empty', null);

    expect($request->authorize())->toBeTrue()
        ->and($received['x-signature'])->toBe(['first', 'second'])
        ->and($received['x-empty'])->toBe([]);
});

test('a provider that cannot be resolved refuses the delivery and says why', function (): void {
    $logs = captureLogs();

    expect(makeWebhookRequestFor('nosuchprovider', '{}')->authorize())->toBeFalse();

    $entry = loggedEntry($logs, 'Webhook authorization failed for provider [nosuchprovider]');

    expect($entry['level'])->toBe('warning')
        ->and($entry['context']['error'])->toBeString()->not->toBeEmpty()
        ->and($entry['context']['ip'])->toBe('127.0.0.1');
});

test('switching verification off is logged as an error outside local and testing, once an hour', function (): void {
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');
    app()->detectEnvironment(fn (): string => 'production');
    $logs = captureLogs();

    Cache::shouldReceive('add')->once()->with('payzephyr:webhook:unverified_warning', true, 3600)->andReturnTrue();

    $authorized = makeWebhookRequestFor('paystack', '{}')->authorize();
    // Back to testing before teardown, whose migration rollback asks for
    // confirmation in production.
    app()->detectEnvironment(fn (): string => 'testing');

    expect($authorized)->toBeTrue();

    $entry = loggedEntry($logs, 'Webhook signature verification is DISABLED');

    expect($entry['level'])->toBe('error')
        ->and($entry['context']['hint'])->toContain('PAYMENTS_WEBHOOK_VERIFY_SIGNATURE')
        ->and($entry['context']['ip'])->toBe('127.0.0.1');
});

test('switching verification off is not logged in local development', function (string $environment): void {
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');
    app()->detectEnvironment(fn (): string => $environment);
    $logs = captureLogs();

    Cache::shouldReceive('add')->never();

    $authorized = makeWebhookRequestFor('paystack', '{}')->authorize();
    app()->detectEnvironment(fn (): string => 'testing');

    expect($authorized)->toBeTrue()
        ->and($logs->getArrayCopy())->toBe([]);
})->with(['local', 'testing']);

test('each field the request validates must have its type', function (string $field, mixed $wrong): void {
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');

    $this->postJson('/payments/webhook/paystack', [$field => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    ['event', ['not', 'a', 'string']],
    ['eventType', ['x']],
    ['event_type', ['x']],
    ['data', 'not-an-array'],
    ['reference', ['x']],
    ['status', ['x']],
    ['paymentStatus', ['x']],
    ['payment_status', ['x']],
]);

test('the validation rules are all optional', function (): void {
    expect((new WebhookRequest)->rules())->each->toStartWith('sometimes|');
});

test('a delivery exactly at the size limit is accepted, by its declared length and by its body', function (): void {
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');

    $declared = makeWebhookRequestFor('paystack', '{}', ['Content-Length' => '64']);
    $body = makeWebhookRequestFor('paystack', str_pad('{}', 64));
    $body->headers->remove('Content-Length');

    expect($declared->authorize())->toBeTrue()
        ->and($body->authorize())->toBeTrue();
});

test('a declared length is read as a number, so trailing junk does not hide its size', function (): void {
    // "100x" compared as a string sorts below "64"; as a number it is 100.
    // Verification is off, so only the size check can refuse it.
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');

    expect(makeWebhookRequestFor('paystack', '{}', ['Content-Length' => '100x'])->authorize())->toBeFalse();
});

test('the size limit is a megabyte when not configured', function (string $length, bool $accepted): void {
    config(['payments.webhook' => ['verify_signature' => false]]);
    app()->forgetInstance('payments.config');

    expect(makeWebhookRequestFor('paystack', '{}', ['Content-Length' => $length])->authorize())->toBe($accepted);
})->with([
    'a megabyte' => ['1048576', true],
    'a byte more' => ['1048577', false],
]);

test('signatures are checked when verification is not configured either way', function (): void {
    config(['payments.webhook' => ['max_payload_size' => 1048576]]);
    app()->forgetInstance('payments.config');

    expect(makeWebhookRequestFor('paystack', '{}', ['x-paystack-signature' => 'forged'])->authorize())->toBeFalse();
});

test('a request with no provider in its route names none when it is refused', function (): void {
    $logs = captureLogs();
    $request = WebhookRequest::createFrom(Request::create('/payments/webhook', 'POST', [], [], [], [], '{}'));
    $request->setRouteResolver(fn () => (new Route('POST', 'payments/webhook', []))->bind($request));

    expect($request->authorize())->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook authorization failed')['message'])->toContain('for provider []');
});
