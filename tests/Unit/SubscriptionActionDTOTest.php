<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;

test('it exposes the subscription code and reads options with a default', function () {
    $action = new SubscriptionActionDTO('SUB_123', ['token' => 'abc123def456']);

    expect($action->subscriptionCode)->toBe('SUB_123')
        ->and($action->option('token'))->toBe('abc123def456')
        ->and($action->option('missing'))->toBeNull()
        ->and($action->option('missing', 'fallback'))->toBe('fallback');
});

test('it defaults to an empty options bag', function () {
    $action = new SubscriptionActionDTO('SUB_123');

    expect($action->options)->toBe([])
        ->and($action->option('anything'))->toBeNull();
});

test('it rejects an empty subscription code', function () {
    new SubscriptionActionDTO('');
})->throws(InvalidArgumentException::class);

test('it supports arbitrary provider-specific option keys', function () {
    $action = new SubscriptionActionDTO('SUB_123', ['reason' => 'customer requested', 'notify' => false]);

    expect($action->option('reason'))->toBe('customer requested')
        ->and($action->option('notify'))->toBeFalse();
});
