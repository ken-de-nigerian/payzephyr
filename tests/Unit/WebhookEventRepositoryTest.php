<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use KenDeNigerian\PayZephyr\Repositories\EloquentWebhookEventRepository;

beforeEach(function () {
    $this->repository = new EloquentWebhookEventRepository;
});

test('recordIfNew returns true and persists the event on first sight', function () {
    $result = $this->repository->recordIfNew('paystack', 'evt_123');

    expect($result)->toBeTrue()
        ->and(WebhookEvent::where('provider', 'paystack')->where('event_key', 'evt_123')->exists())->toBeTrue();
});

test('recordIfNew returns false for a duplicate (provider, event_key) pair', function () {
    $this->repository->recordIfNew('paystack', 'evt_123');

    $result = $this->repository->recordIfNew('paystack', 'evt_123');

    expect($result)->toBeFalse()
        ->and(WebhookEvent::where('provider', 'paystack')->where('event_key', 'evt_123')->count())->toBe(1);
});

test('recordIfNew treats the same event_key as distinct across different providers', function () {
    $first = $this->repository->recordIfNew('paystack', 'evt_123');
    $second = $this->repository->recordIfNew('stripe', 'evt_123');

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and(WebhookEvent::where('event_key', 'evt_123')->count())->toBe(2);
});

test('recordIfNew is idempotent under repeated duplicate-delivery-style calls', function () {
    $successCount = 0;
    for ($i = 0; $i < 5; $i++) {
        if ($this->repository->recordIfNew('paystack', 'evt_repeat')) {
            $successCount++;
        }
    }

    expect($successCount)->toBe(1)
        ->and(WebhookEvent::where('event_key', 'evt_repeat')->count())->toBe(1);
});
