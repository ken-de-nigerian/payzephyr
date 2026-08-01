<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;

/**
 * Covers the payload-size-limit branches of WebhookRequest::authorize() that
 * tests/Unit/WebhookRequestTest.php doesn't exercise: rejection based on the
 * Content-Length header, and rejection based on the actual body size when no
 * (or an understated) Content-Length header is present.
 */
function makeSizeLimitedWebhookRequest(Request $baseRequest, string $body): WebhookRequest
{
    return new class($baseRequest, $body) extends WebhookRequest
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
}

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

test('webhook request rejects payload whose Content-Length header exceeds the configured max', function () {
    config(['payments.webhook.max_payload_size' => 100]);
    app()->forgetInstance('payments.config');

    $body = json_encode(['event' => 'charge.success']);
    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', [], [], [], [], $body);
    $baseRequest->headers->set('Content-Length', '999999');

    $request = makeSizeLimitedWebhookRequest($baseRequest, $body);

    expect($request->authorize())->toBeFalse();
});

test('webhook request rejects payload whose actual body size exceeds the configured max', function () {
    config(['payments.webhook.max_payload_size' => 10]);
    app()->forgetInstance('payments.config');

    $body = str_repeat('x', 500);
    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', [], [], [], [], $body);

    $request = makeSizeLimitedWebhookRequest($baseRequest, $body);

    expect($request->authorize())->toBeFalse();
});

test('webhook request accepts payload within the configured max payload size', function () {
    config(['payments.webhook.max_payload_size' => 1048576, 'payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');

    $body = json_encode(['event' => 'charge.success']);
    $baseRequest = Request::create('/payments/webhook/paystack', 'POST', [], [], [], [], $body);
    $baseRequest->headers->set('Content-Length', (string) strlen($body));

    $request = makeSizeLimitedWebhookRequest($baseRequest, $body);

    expect($request->authorize())->toBeTrue();
});
