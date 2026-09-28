<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;

/**
 * A WebhookRequest for $provider carrying $body and $headers, with its route
 * parameter bound, so authorize() can be called outside the HTTP kernel.
 *
 * Lives here rather than in a test file because more than one file uses it,
 * and Pest only shares cross-file globals during a full-suite run - a single
 * file, or a parallel worker, would fail to resolve it. tests/Pest.php
 * requires this.
 */
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
