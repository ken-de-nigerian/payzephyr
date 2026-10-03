<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;

/*
 * The signature covers the body. The job used to receive $request->all(),
 * which merges the query string in - unsigned input riding along with a
 * verified delivery. For a provider deduplicated by a hash of the body, a
 * captured genuine delivery replayed with `?x=1`, `?x=2`, ... hashed
 * differently every time and was processed every time.
 */

function signedPaystackDelivery(array $body): array
{
    $json = (string) json_encode($body);

    return [$json, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $json, 'sk_test_xxx'),
    ]];
}

test('a signed delivery replayed with different query strings is processed once', function () {
    Event::fake([WebhookReceived::class]);
    [$json, $server] = signedPaystackDelivery(['event' => 'charge.success', 'data' => ['reference' => 'REPLAY_Q', 'status' => 'success']]);

    foreach (['', '?x=1', '?x=2&data[status]=failed'] as $query) {
        $this->call('POST', '/payments/webhook/paystack'.$query, [], [], [], $server, $json)->assertStatus(202);
    }

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    expect(WebhookEvent::where('provider', 'paystack')->count())->toBe(1);
});

test('only the signed body reaches the job', function () {
    Queue::fake();
    $body = ['event' => 'charge.success', 'data' => ['reference' => 'BODY_ONLY', 'status' => 'success']];
    [$json, $server] = signedPaystackDelivery($body);

    $this->call('POST', '/payments/webhook/paystack?event=refund.processed&injected=1', [], [], [], $server, $json)->assertStatus(202);

    Queue::assertPushed(ProcessWebhook::class, fn (ProcessWebhook $job) => $job->payload === $body);
});

test('a form-encoded delivery reaches the job as its form fields', function () {
    // Mollie's classic webhook is `id=tr_...`, verified through the API in the job.
    Queue::fake();
    config(['payments.providers.mollie.webhook_secret' => null]);
    app()->forgetInstance('payments.config');

    $this->call('POST', '/payments/webhook/mollie?injected=1', ['id' => 'tr_form'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'id=tr_form')
        ->assertStatus(202);

    Queue::assertPushed(ProcessWebhook::class, fn (ProcessWebhook $job) => $job->payload === ['id' => 'tr_form']);
});

/*
 * Every header of every delivery used to ride onto the queue - cookies, an
 * Authorization header added by a proxy - and sit in whatever stores queued
 * jobs. Only a provider whose signature is checked in the job needs them.
 */

test('a delivery verified before it is queued carries no headers onto the queue', function () {
    Queue::fake();
    [$json, $server] = signedPaystackDelivery(['event' => 'charge.success', 'data' => ['reference' => 'NO_HEADERS', 'status' => 'success']]);

    $this->call('POST', '/payments/webhook/paystack', [], ['session' => 'abc'], [], $server + ['HTTP_AUTHORIZATION' => 'Bearer proxy'], $json)
        ->assertStatus(202);

    Queue::assertPushed(ProcessWebhook::class, fn (ProcessWebhook $job) => $job->headers === []);
});

test('a delivery verified in the job carries its headers, without credentials or cookies', function () {
    Queue::fake();
    config(['payments.providers.mollie.webhook_secret' => null]);
    app()->forgetInstance('payments.config');

    $this->call('POST', '/payments/webhook/mollie', ['id' => 'tr_headers'], ['session' => 'abc'], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_AUTHORIZATION' => 'Bearer proxy',
        'HTTP_PROXY_AUTHORIZATION' => 'Basic cHJveHk=',
        'HTTP_COOKIE' => 'session=abc',
        'HTTP_X_CSRF_TOKEN' => 'csrf',
        'HTTP_X_XSRF_TOKEN' => 'xsrf',
        'HTTP_USER_AGENT' => 'Mollie/1.0',
    ], 'id=tr_headers')->assertStatus(202);

    Queue::assertPushed(ProcessWebhook::class, function (ProcessWebhook $job): bool {
        expect($job->headers)->toHaveKey('user-agent')
            ->and($job->headers['content-type'])->toBe(['application/x-www-form-urlencoded'])
            ->and($job->headers)->not->toHaveKeys(['authorization', 'proxy-authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token']);

        return true;
    });
});

test('a queued delivery is answered with its status, and logged with its provider and sender', function () {
    Queue::fake();
    $logs = captureLogs();
    [$json, $server] = signedPaystackDelivery(['event' => 'charge.success', 'data' => ['reference' => 'LOGGED', 'status' => 'success']]);

    $this->call('POST', '/payments/webhook/paystack', [], [], [], $server + ['REMOTE_ADDR' => '203.0.113.7'], $json)
        ->assertStatus(202)
        ->assertExactJson(['status' => 'queued']);

    expect(loggedEntry($logs, 'Webhook queued for processing'))->toMatchArray([
        'level' => 'info',
        'context' => ['provider' => 'paystack', 'ip' => '203.0.113.7'],
    ]);
});
