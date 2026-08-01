<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
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

function makeWebhookRequestFor(string $provider, string $body, array $headers = []): WebhookRequest
{
    $base = Request::create("/payments/webhook/$provider", 'POST', [], [], [], [], $body);
    foreach ($headers as $key => $value) {
        $base->headers->set($key, $value);
    }

    $request = new class($base, $body, $provider) extends WebhookRequest
    {
        public function __construct(private readonly Request $base, private readonly string $body, private readonly string $provider)
        {
            parent::__construct(
                $base->query->all(),
                [],
                $base->attributes->all(),
                $base->cookies->all(),
                $base->files->all(),
                $base->server->all(),
                $body
            );
            $this->headers = $base->headers;
        }

        public function getContent(bool $asResource = false): false|string
        {
            return $this->body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? $this->provider : $default;
        }
    };

    return $request;
}

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
