<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);
    app()->forgetInstance('payments.config');
    Event::fake();
});

test('paypal webhook request authorizes without a valid signature - verification is deferred (ADR-0007)', function () {
    $body = json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

    // Deliberately no paypal-transmission-* headers at all - a synchronous
    // check would reject this immediately. authorize() must still return
    // true, because PayPalDriver defers to ProcessWebhook.
    $request = makeWebhookRequestFor('paypal', $body);

    expect($request->authorize())->toBeTrue();
});

test('other providers still verify synchronously and are unaffected by the paypal deferral', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret',
            'enabled' => true,
        ],
    ]);
    app()->forgetInstance('payments.config');

    $body = json_encode(['event' => 'charge.success']);
    $request = makeWebhookRequestFor('paystack', $body, ['x-paystack-signature' => 'invalid']);

    expect($request->authorize())->toBeFalse();
});

test('ProcessWebhook discards a paypal delivery that fails deferred verification', function () {
    $job = new ProcessWebhook('paypal', ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], [
        // Missing all paypal-transmission-* headers - validateWebhook() will
        // reject before ever attempting the PayPal API call.
    ]);

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('ProcessWebhook processes a paypal delivery that passes deferred verification', function () {
    $manager = app(PaymentManager::class);

    $mockDriver = Mockery::mock(DriverInterface::class, RequiresAsyncWebhookVerification::class);
    $mockDriver->shouldReceive('requiresAsyncVerification')->andReturn(true);
    $mockDriver->shouldReceive('validateWebhook')->once()->andReturn(true);
    $mockDriver->shouldReceive('extractWebhookEventId')->andReturn('WH-1');
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);

    $managerReflection = new ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $job = new ProcessWebhook('paypal', ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], [
        'paypal-transmission-id' => ['t1'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});

/**
 * A real PayPalDriver, resolved through the manager the job will use, whose
 * HTTP calls are answered from $queue in order (token first, then verify).
 *
 * @param  array<int, mixed>  $queue
 */
function paypalDriverInManagerAnswering(array $queue): \KenDeNigerian\PayZephyr\Drivers\PayPalDriver
{
    app()->forgetInstance(PaymentManager::class);
    $driver = app(PaymentManager::class)->driver('paypal');
    $driver->setClient(new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(
        new \GuzzleHttp\Handler\MockHandler($queue)
    )]));

    return $driver;
}

function paypalDeliveryHeaders(): array
{
    return [
        'paypal-transmission-id' => ['t1'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['sig'],
    ];
}

test('ProcessWebhook retries, rather than discards, a paypal delivery PayPal could not be asked about', function () {
    // PayPal has already been told 202 and will not resend. If an outage at
    // PayPal's verification endpoint were read as a forged signature, this
    // genuine delivery would be gone for good.
    paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', '/v1/notifications/verify-webhook-signature')),
    ]);

    $payload = ['id' => 'WH-RETRY', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => now()->toIso8601String()];
    $job = new ProcessWebhook('paypal', $payload, paypalDeliveryHeaders());

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\WebhookException::class);

    Event::assertNotDispatched(WebhookReceived::class);

    // Verification precedes the idempotency claim, so a failed attempt leaves
    // nothing that would make the retry look like a duplicate.
    expect(\KenDeNigerian\PayZephyr\Models\WebhookEvent::query()->count())->toBe(0);

    // The retry, with PayPal back, processes the delivery.
    paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"verification_status":"SUCCESS"}'),
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});

test('ProcessWebhook still discards a paypal delivery PayPal reports as forged', function () {
    paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"verification_status":"FAILURE"}'),
    ]);

    $payload = ['id' => 'WH-FORGED', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => now()->toIso8601String()];
    $job = new ProcessWebhook('paypal', $payload, paypalDeliveryHeaders());

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('ProcessWebhook records when the delivery was received', function () {
    $before = time();
    $job = new ProcessWebhook('paypal', ['id' => 'WH-1']);

    expect($job->receivedAt)->toBeGreaterThanOrEqual($before)
        ->and($job->receivedAt)->toBeLessThanOrEqual(time());

    // It travels with the job through the queue.
    $restored = unserialize(serialize($job));
    expect($restored->receivedAt)->toBe($job->receivedAt);
});

test('ProcessWebhook measures the paypal replay window from receipt, not from when a worker ran it', function () {
    // Received ten minutes ago and only now picked up - a backed-up queue.
    // Measured from now, create_time is outside a five-minute window and the
    // delivery would be thrown away.
    config(['payments.webhook.events.replay_window' => 300]);
    app()->forgetInstance('payments.config');
    $driver = paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"verification_status":"SUCCESS"}'),
    ]);

    $receivedAt = time() - 600;
    $payload = ['id' => 'WH-LATE', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => date('c', $receivedAt - 5)];
    $job = new ProcessWebhook('paypal', $payload, paypalDeliveryHeaders());
    $job->receivedAt = $receivedAt;

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);

    // The driver outlives the job in a worker; the next delivery must be
    // measured from its own receipt, not inherit this one's.
    $reflection = new ReflectionProperty(\KenDeNigerian\PayZephyr\Drivers\AbstractDriver::class, 'webhookReceivedAt');
    expect($reflection->getValue($driver))->toBeNull();
});

test('ProcessWebhook clears the receipt time even when verification throws', function () {
    $driver = paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', '/v1/notifications/verify-webhook-signature')),
    ]);

    $job = new ProcessWebhook('paypal', ['id' => 'WH-X', 'create_time' => now()->toIso8601String()], paypalDeliveryHeaders());

    try {
        app()->call([$job, 'handle']);
    } catch (\KenDeNigerian\PayZephyr\Exceptions\WebhookException) {
    }

    $reflection = new ReflectionProperty(\KenDeNigerian\PayZephyr\Drivers\AbstractDriver::class, 'webhookReceivedAt');
    expect($reflection->getValue($driver))->toBeNull();
});

test('ProcessWebhook falls back to now for a job queued before receipt times were recorded', function () {
    // A job serialized by an older version has no receivedAt. It must still
    // be verifiable - measured from now, exactly as before.
    paypalDriverInManagerAnswering([
        new \GuzzleHttp\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"verification_status":"SUCCESS"}'),
    ]);

    $job = new ProcessWebhook('paypal', ['id' => 'WH-OLD', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => now()->toIso8601String()], paypalDeliveryHeaders());
    $job->receivedAt = null;

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});
