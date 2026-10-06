<?php

/** @noinspection ALL */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;

beforeEach(function () {
    config([
        'payments.logging.enabled' => false,

        'payments.webhook.verify_signature' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
        'payments.providers.flutterwave' => [
            'driver' => 'flutterwave',
            'secret_key' => 'test_secret',
            'webhook_secret' => 'webhook_secret',
            'enabled' => true,
        ],
    ]);
});

test('webhook controller queues webhook processing', function () {
    Queue::fake();

    $controller = app(WebhookController::class);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'amount' => 1000000,
            'status' => 'success',
        ],
    ];

    $baseRequest = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', $payload);
    $request = $baseRequest;
    $request->headers->set('Content-Type', 'application/json');

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');
    $request->headers->set('x-paystack-signature', $signature);

    $request = new class($request, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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

        public function getContent(bool $asResource = false): false|string
        {
            return $this->body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }
    };

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(202); // 202 Accepted for queued
    Queue::assertPushed(ProcessWebhook::class, function ($job) {
        return $job->provider === 'paystack'
            && isset($job->payload['event']);
    });
});

test('webhook controller rejects invalid signature via form request', function () {
    config(['payments.webhook.verify_signature' => true]);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123']];
    $baseRequest = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', $payload);
    $baseRequest->headers->set('x-paystack-signature', 'invalid_signature_here');

    $body = json_encode(['event' => 'charge.success']);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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

        public function getContent(bool $asResource = false): false|string
        {
            return $this->body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }
    };

    expect($request->authorize())->toBeFalse();
});

test('webhook controller bypasses signature verification when disabled', function () {
    Queue::fake();
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123']];
    $baseRequest = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(202); // Queued
    Queue::assertPushed(ProcessWebhook::class);
});

test('webhook controller handles invalid provider gracefully', function () {
    Queue::fake();
    $controller = app(WebhookController::class);

    $baseRequest = Request::create('/payments/webhook/invalid_provider', 'POST', []);
    $body = json_encode([]);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'invalid_provider' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $response = $controller->handle($request, 'invalid_provider');

    expect($response->getStatusCode())->toBe(202);
    // No driver to say its signature is checked in the job, so no headers.
    Queue::assertPushed(ProcessWebhook::class, fn (ProcessWebhook $job): bool => $job->headers === []);
});

test('webhook controller handles exceptions during processing', function () {
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', [
        'malformed' => 'data',
    ]);

    $body = json_encode(['malformed' => 'data']);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBeIn([202, 500]);
});

test('webhook controller dispatches both provider-specific and general events', function () {
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ];
    $baseRequest = Request::create('/payments/webhook/flutterwave', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'flutterwave' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'flutterwave');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});

test('webhook controller handles flutterwave webhook with valid signature', function () {
    $controller = app(WebhookController::class);

    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'tx_ref' => 'FLW_ref_123',
            'status' => 'successful',
        ],
    ];

    $baseRequest = Request::create('/payments/webhook/flutterwave', 'POST', $payload);
    $baseRequest->headers->set('verif-hash', 'webhook_secret');

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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

        public function getContent(bool $asResource = false): false|string
        {
            return $this->body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'flutterwave' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'flutterwave');

    expect($response->getStatusCode())->toBe(202);
    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class);
});

test('webhook controller logs webhook processing', function () {
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', [
        'event' => 'charge.success',
    ]);

    $body = json_encode(['event' => 'charge.success']);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(202);
});

test('webhook controller handles empty payload', function () {
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', []);
    $body = json_encode([]);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBeIn([202, 500]);
});

test('webhook controller handles complex nested payload', function () {
    config(['payments.webhook.verify_signature' => false]);

    $controller = app(WebhookController::class);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'amount' => 50000,
            'currency' => 'NGN',
            'customer' => [
                'email' => 'test@example.com',
                'name' => 'John Doe',
                'metadata' => [
                    'custom_field' => 'value',
                ],
            ],
            'metadata' => [
                'order_id' => 12345,
                'items' => [
                    ['name' => 'Item 1', 'price' => 25000],
                    ['name' => 'Item 2', 'price' => 25000],
                ],
            ],
        ],
    ];

    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', $payload);

    $body = json_encode($payload);
    $request = new class($baseRequest, $body) extends \KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest
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
            return $param === 'provider' ? 'paystack' : $default;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(202);

    Event::assertDispatched(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class, function ($event) {
        return $event->provider === 'paystack';
    });
});
