<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Repositories\EloquentWebhookEventRepository;

/**
 * Failure handling inside webhook processing.
 *
 * These paths only run when a second thing fails while ProcessWebhook is
 * already handling a first failure - precisely when a silent swallow would be
 * hardest to notice in production.
 */
beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);

    Event::fake();
});

test('a failure while clearing the idempotency marker does not mask the original error', function () {
    // The delivery failed, and then the cleanup of its "seen" marker failed
    // too. The original processing error is what the queue needs to see and
    // retry on - the cleanup failure must be logged, not substituted.
    $repository = Mockery::mock(WebhookEventRepositoryInterface::class);
    $repository->shouldReceive('recordIfNew')->andReturnTrue();
    $repository->shouldReceive('forget')->andThrow(new RuntimeException('marker delete failed'));
    app()->instance(WebhookEventRepositoryInterface::class, $repository);

    Event::fakeExcept([WebhookReceived::class]);
    Event::listen(WebhookReceived::class, function () {
        throw new RuntimeException('original processing failure');
    });

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['id' => 4242, 'reference' => 'ref_marker_fail'],
    ]);

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class, 'original processing failure');
});

test('a refund-status write failure does not fail webhook processing', function () {
    // Persisting refund status is bookkeeping. The webhook itself was
    // delivered and verified; failing the whole job over a local write would
    // make the provider retry a delivery that was actually fine.
    $refundRepository = Mockery::mock(RefundRepositoryInterface::class);
    $refundRepository->shouldReceive('updateStatusIfExists')
        ->andThrow(new RuntimeException('refund status write failed'));
    app()->instance(RefundRepositoryInterface::class, $refundRepository);

    app()->instance(WebhookEventRepositoryInterface::class, new EloquentWebhookEventRepository);

    $job = new ProcessWebhook('paystack', [
        'event' => 'refund.processed',
        'data' => ['id' => 5150, 'reference' => 'ref_refund_write_fail', 'status' => 'processed'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});

test('refund status is not persisted at all when refund logging is disabled', function () {
    config(['payments.refunds.logging.enabled' => false]);
    app()->forgetInstance('payments.config');

    $refundRepository = Mockery::mock(RefundRepositoryInterface::class);
    $refundRepository->shouldNotReceive('updateStatusIfExists');
    app()->instance(RefundRepositoryInterface::class, $refundRepository);

    app()->instance(WebhookEventRepositoryInterface::class, new EloquentWebhookEventRepository);

    $job = new ProcessWebhook('paystack', [
        'event' => 'refund.processed',
        'data' => ['id' => 5151, 'reference' => 'ref_logging_off', 'status' => 'processed'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});
