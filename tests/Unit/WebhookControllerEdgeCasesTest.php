<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

test('webhook controller handles paypal status from event_type', function (): void {
    $job = new ProcessWebhook('paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookStatus')->andReturn('PAYMENT.CAPTURE.COMPLETED');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setValue($manager, ['paypal' => $mockDriver]);

    $statusNormalizer = app(StatusNormalizerInterface::class);

    $reflection = new \ReflectionClass($job);
    $method = $reflection->getMethod('determineStatus');
    $status = $method->invoke($job, $manager, $statusNormalizer);

    expect($status)->toBe('success');
});

test('webhook controller handles webhook update with channel', function (): void {
    Event::fake();

    $transaction = PaymentTransaction::create([
        'reference' => 'test_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'test_ref',
            'status' => 'success',
            'channel' => 'card',
        ],
    ]);

    $request->headers->set('x-paystack-signature', 'valid');

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
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

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(202);

    $transaction->refresh();
    expect($transaction->channel)->toBe('card');
});

test('webhook controller handles database error in updateTransactionFromWebhook', function (): void {
    Event::fake();

    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'nonexistent_ref',
            'status' => 'success',
        ],
    ]);

    $request->headers->set('x-paystack-signature', 'valid');

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
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

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(202);
});

test('webhook controller handles successful status with paid_at', function (): void {
    Event::fake();

    $transaction = PaymentTransaction::create([
        'reference' => 'test_ref',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'data' => [
            'reference' => 'test_ref',
            'status' => 'success',
        ],
    ]);

    $request->headers->set('x-paystack-signature', 'valid');

    $body = json_encode($request->all());
    $webhookRequest = new class($request, $body) extends WebhookRequest
    {
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

    $controller = app(WebhookController::class);
    $response = $controller->handle($webhookRequest, 'paystack');

    expect($response->getStatusCode())->toBe(202);

    $transaction->refresh();
    expect($transaction->paid_at)->not->toBeNull();
});
