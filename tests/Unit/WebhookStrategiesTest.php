<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.logging.enabled' => false,
        'payments.webhook.verify_signature' => false,

        'payments.providers.monnify' => [
            'driver' => 'monnify',
            'api_key' => 'test_key',
            'secret_key' => 'test_secret',
            'contract_code' => '123',
            'enabled' => true,
        ],
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'test_secret',
            'enabled' => true,
        ],
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_id',
            'client_secret' => 'test_secret',
            'enabled' => true,
        ],
    ]);
});

test('webhook controller routes monnify requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);

    $payload = ['event' => 'charge.success', 'transactionReference' => 'ref_monnify'];
    $baseRequest = Request::create('/payments/webhook/monnify', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers->replace($request->headers->all());
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'monnify' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'monnify');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});

test('webhook controller routes stripe requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);

    $payload = [
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => ['id' => 'pi_123'],
        ],
    ];
    $baseRequest = Request::create('/payments/webhook/stripe', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers->replace($request->headers->all());
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'stripe' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'stripe');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});

test('webhook controller routes paypal requests correctly', function () {
    $manager = app(PaymentManager::class);
    $controller = app(WebhookController::class);

    $payload = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'pay_123']];
    $baseRequest = Request::create('/payments/webhook/paypal', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends WebhookRequest
    {
        private string $body;

        public function __construct($request, string $body)
        {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $body
            );
            $this->headers->replace($request->headers->all());
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paypal' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paypal');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});
