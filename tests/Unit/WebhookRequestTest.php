<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);
});

test('webhook request validates payload structure', function () {
    $request = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ]);

    $body = json_encode($request->all());
    $formRequest = new class($request, $body) extends WebhookRequest
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
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function rules(): array
        {
            return parent::rules();
        }
    };

    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $formRequest->rules());

    expect($validator->passes())->toBeTrue();
});

test('webhook request authorizes valid signature', function () {
    // Real Paystack payloads carry the timestamp under data.paid_at - see ADR-0001.
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123', 'paid_at' => now()->toIso8601String()],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');

    $request = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', $payload);
    $request->headers->set('x-paystack-signature', $signature);
    $request->headers->set('Content-Type', 'application/json');

    $request = new class($request, $body) extends WebhookRequest
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
            $this->headers = $request->headers;
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

    expect($request->authorize())->toBeTrue();
});

test('webhook request rejects invalid signature', function () {
    $payload = ['event' => 'charge.success'];

    $baseRequest = \Illuminate\Http\Request::create('/payments/webhook/paystack', 'POST', $payload);
    $baseRequest->headers->set('x-paystack-signature', 'invalid_signature');

    $body = json_encode(['event' => 'charge.success']);
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
            $this->headers = $request->headers;
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

test('webhook request bypasses signature when verification disabled', function () {
    config(['payments.webhook.verify_signature' => false]);

    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', []);
    $body = json_encode([]);
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
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'paystack' : $default;
        }
    };

    expect($request->authorize())->toBeTrue();
});

test('webhook request validation rules accept optional fields', function () {
    $payload = [
        'event' => 'charge.success',
        'eventType' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
        'reference' => 'ref_123',
        'status' => 'success',
    ];
    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', $payload);
    $body = json_encode($payload);

    $formRequest = new class($baseRequest, $body) extends WebhookRequest
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
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function rules(): array
        {
            return parent::rules();
        }
    };
    $validator = \Illuminate\Support\Facades\Validator::make($baseRequest->all(), $formRequest->rules());

    expect($validator->passes())->toBeTrue();
});

test('webhook request handles missing provider gracefully', function () {
    $baseRequest = Request::create('/payments/webhook/invalid', 'POST', []);
    $body = json_encode([]);
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
            $this->headers = $request->headers;
            $this->body = $body;
        }

        public function route($param = null, $default = null)
        {
            return $param === 'provider' ? 'invalid' : $default;
        }
    };

    expect($request->authorize())->toBeFalse();
});
