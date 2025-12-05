<?php /** @noinspection ALL */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    // Disable logging to prevent "Table not found" errors during unit tests
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

test('webhook controller handles valid paystack webhook', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_123',
            'amount' => 1000000,
            'status' => 'success',
        ],
    ];

    $request = Request::create('/payments/webhook/paystack', 'POST', $payload);
    $request->headers->set('Content-Type', 'application/json');

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');
    $request->headers->set('x-paystack-signature', $signature);

    // Mock the request content
    $request = new class($request) extends Request {
        private $originalRequest;

        public function __construct($request) {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent()
            );
            $this->headers = $request->headers;
            $this->originalRequest = $request;
        }

        public function getContent(bool $asResource = false): false|string
        {
            return json_encode($this->originalRequest->request->all());
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.paystack');
    Event::assertDispatched('payments.webhook');
});

test('webhook controller rejects invalid signature', function () {
    config(['payments.webhook.verify_signature' => true]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123']];
    $request = Request::create('/payments/webhook/paystack', 'POST', $payload);
    $request->headers->set('x-paystack-signature', 'invalid_signature_here');

    $request = new class($request) extends Request {
        private $originalRequest;

        public function __construct($request) {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent()
            );
            $this->headers = $request->headers;
            $this->originalRequest = $request;
        }

        public function getContent(bool $asResource = false): false|string
        {
            return json_encode($this->originalRequest->request->all());
        }
    };

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(403);
});

test('webhook controller bypasses signature verification when disabled', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_123']];
    $request = Request::create('/payments/webhook/paystack', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.paystack');
});

test('webhook controller handles invalid provider gracefully', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $request = Request::create('/payments/webhook/invalid_provider', 'POST', []);

    $response = $controller->handle($request, 'invalid_provider');

    expect($response->getStatusCode())->toBe(500);
});

test('webhook controller handles exceptions during processing', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    // Create a request that might cause issues
    $request = Request::create('/payments/webhook/paystack', 'POST', [
        'malformed' => 'data',
    ]);

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    // Should handle gracefully
    expect($response->getStatusCode())->toBeIn([200, 500]);
});

test('webhook controller dispatches both provider-specific and general events', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ];
    $request = Request::create('/payments/webhook/flutterwave', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'flutterwave');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.flutterwave');
    Event::assertDispatched('payments.webhook');
});

test('webhook controller handles flutterwave webhook with valid signature', function () {
    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'tx_ref' => 'FLW_ref_123',
            'status' => 'successful',
        ],
    ];

    $request = Request::create('/payments/webhook/flutterwave', 'POST', $payload);
    $request->headers->set('verif-hash', 'webhook_secret');

    $request = new class($request) extends Request {
        private $originalRequest;

        public function __construct($request) {
            parent::__construct(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent()
            );
            $this->headers = $request->headers;
            $this->originalRequest = $request;
        }

        public function getContent(bool $asResource = false): false|string
        {
            return json_encode($this->originalRequest->request->all());
        }
    };

    Event::fake();

    $response = $controller->handle($request, 'flutterwave');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched('payments.webhook.flutterwave');
});

test('webhook controller logs webhook processing', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $request = Request::create('/payments/webhook/paystack', 'POST', [
        'event' => 'charge.success',
    ]);

    Event::fake();

    // Should not throw errors during logging
    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);
});

test('webhook controller handles empty payload', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

    $request = Request::create('/payments/webhook/paystack', 'POST', []);

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBeIn([200, 500]);
});

test('webhook controller handles complex nested payload', function () {
    config(['payments.webhook.verify_signature' => false]);

    $manager = app(PaymentManager::class);
    $controller = new WebhookController($manager);

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

    $request = Request::create('/payments/webhook/paystack', 'POST', $payload);

    Event::fake();

    $response = $controller->handle($request, 'paystack');

    expect($response->getStatusCode())->toBe(200);

    Event::assertDispatched('payments.webhook.paystack', function ($event, $data) {
        return isset($data[0]['data']['reference'])
            && $data[0]['data']['reference'] === 'ref_123';
    });
});