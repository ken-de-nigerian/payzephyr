<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

/**
 * A worker that dies without unwinding leaves the webhook idempotency marker
 * behind, because the release lives in a catch block that never runs. The retry
 * used to read its own leftover marker as somebody else's duplicate delivery
 * and return, dropping a charge.success for good: the transaction stayed
 * pending forever while the provider's dashboard showed a delivered webhook.
 *
 * Reclaiming has to stay narrow. A genuine duplicate delivery arrives as a new
 * job on its first attempt, and that must still be refused.
 */
function markerWebhookPayload(string $reference = 'PZ_live_1'): array
{
    return [
        'event' => 'charge.success',
        'data' => ['reference' => $reference, 'status' => 'success', 'id' => 'evt_123'],
    ];
}

function pendingTransaction(string $reference = 'PZ_live_1'): PaymentTransaction
{
    return PaymentTransaction::create([
        'reference' => $reference, 'provider' => 'paystack', 'status' => 'pending',
        'amount' => 50000, 'currency' => 'NGN', 'email' => 'buyer@example.test',
    ]);
}

/** Attach a queue job reporting the given attempt number. */
function webhookJobOnAttempt(array $payload, int $attempt): ProcessWebhook
{
    $job = new ProcessWebhook('paystack', $payload);

    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $queueJob->shouldReceive('getJobId')->andReturn('job-1');
    $queueJob->shouldReceive('uuid')->andReturn('uuid-1');
    $queueJob->shouldReceive('resolveName')->andReturn(ProcessWebhook::class);
    $job->setJob($queueJob);

    return $job;
}

function claimMarkerFor(array $payload): string
{
    $key = (new ReflectionClass(ProcessWebhook::class))->getMethod('resolveEventKey');
    $key->setAccessible(true);
    $resolved = $key->invoke(new ProcessWebhook('paystack', $payload), app(PaymentManager::class));

    app(WebhookEventRepositoryInterface::class)->recordIfNew('paystack', $resolved);

    return $resolved;
}

test('a retry reclaims the marker its own killed attempt left behind', function () {
    pendingTransaction();
    claimMarkerFor(markerWebhookPayload());

    // Attempt 2: the marker exists, but it is ours.
    app()->call([webhookJobOnAttempt(markerWebhookPayload(), 2), 'handle']);

    expect(PaymentTransaction::first()->status)->toBe('success');
});

test('a duplicate delivery on its first attempt is still refused', function () {
    pendingTransaction();
    claimMarkerFor(markerWebhookPayload());

    Event::fake([WebhookReceived::class]);

    // A redelivery from the provider is a new job, so attempt 1.
    app()->call([webhookJobOnAttempt(markerWebhookPayload(), 1), 'handle']);

    Event::assertNotDispatched(WebhookReceived::class);
    expect(PaymentTransaction::first()->status)->toBe('pending');
});

test('a webhook processed outside a queue is unaffected', function () {
    // No queue job attached, so there is no attempt count to reason about and
    // the marker is authoritative.
    pendingTransaction();
    claimMarkerFor(markerWebhookPayload());

    app()->call([new ProcessWebhook('paystack', markerWebhookPayload()), 'handle']);

    expect(PaymentTransaction::first()->status)->toBe('pending');
});

test('a first attempt with no marker present processes normally', function () {
    pendingTransaction();

    app()->call([webhookJobOnAttempt(markerWebhookPayload(), 1), 'handle']);

    expect(PaymentTransaction::first()->status)->toBe('success');
});
