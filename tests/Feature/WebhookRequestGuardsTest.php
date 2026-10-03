<?php

declare(strict_types=1);

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

beforeEach(function () {
    config(['payments.webhook.verify_signature' => true, 'payments.webhook.max_payload_size' => 64]);
    app()->forgetInstance('payments.config');
});

test('the payload is the decoded JSON body, whatever content type it came with', function () {
    // Sent as text/plain, Laravel parses no input from it: only the body has it.
    $request = makeWebhookRequestFor('paystack', '{"event":"charge.success","data":{"reference":"R1"}}', ['Content-Type' => 'text/plain']);

    expect($request->payload())->toBe(['event' => 'charge.success', 'data' => ['reference' => 'R1']]);
});

test('a delivery declaring a length over the limit is refused, even with a valid signature', function () {
    $logs = captureLogs();
    $body = '{"event":"charge.success"}';

    $request = makeWebhookRequestFor('paystack', $body, signedPaystackBody($body) + ['Content-Length' => '65']);

    expect($request->authorize())->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook payload size exceeds limit')['context'])->toBe(['size' => '65', 'max' => 64, 'ip' => '127.0.0.1']);
});

test('a body over the limit is refused, even with a valid signature and no declared length', function () {
    $logs = captureLogs();
    $body = (string) json_encode(['event' => 'charge.success', 'data' => ['reference' => str_repeat('x', 60)]]);

    $request = makeWebhookRequestFor('paystack', $body, signedPaystackBody($body));
    $request->headers->remove('Content-Length');

    expect($request->authorize())->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook payload size exceeds limit')['context'])->toBe(['size' => strlen($body), 'max' => 64, 'ip' => '127.0.0.1']);
});

test('the signature check gets every header that has a value, as a list, and none that do not', function () {
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

test('a provider that cannot be resolved refuses the delivery and says why', function () {
    $logs = captureLogs();

    expect(makeWebhookRequestFor('nosuchprovider', '{}')->authorize())->toBeFalse();

    $entry = loggedEntry($logs, 'Webhook authorization failed for provider [nosuchprovider]');

    expect($entry['level'])->toBe('warning')
        ->and($entry['context']['error'])->toBeString()->not->toBeEmpty()
        ->and($entry['context']['ip'])->toBe('127.0.0.1');
});

test('switching verification off is logged as an error outside local and testing, once an hour', function () {
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

test('switching verification off is not logged in local development', function (string $environment) {
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

test('each field the request validates must have its type', function (string $field, mixed $wrong) {
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

test('the validation rules are all optional', function () {
    expect((new WebhookRequest)->rules())->each->toStartWith('sometimes|');
});
