<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;

test('subscription status enum has all expected cases', function () {
    expect(SubscriptionStatus::cases())->toHaveCount(6)
        ->and(SubscriptionStatus::ACTIVE->value)->toBe('active')
        ->and(SubscriptionStatus::NON_RENEWING->value)->toBe('non-renewing')
        ->and(SubscriptionStatus::CANCELLED->value)->toBe('cancelled')
        ->and(SubscriptionStatus::COMPLETED->value)->toBe('completed')
        ->and(SubscriptionStatus::ATTENTION->value)->toBe('attention')
        ->and(SubscriptionStatus::EXPIRED->value)->toBe('expired');
});

test('label returns a human-readable string for every case', function () {
    expect(SubscriptionStatus::ACTIVE->label())->toBe('Active')
        ->and(SubscriptionStatus::NON_RENEWING->label())->toBe('Non-Renewing')
        ->and(SubscriptionStatus::CANCELLED->label())->toBe('Cancelled')
        ->and(SubscriptionStatus::COMPLETED->label())->toBe('Completed')
        ->and(SubscriptionStatus::ATTENTION->label())->toBe('Attention Required')
        ->and(SubscriptionStatus::EXPIRED->label())->toBe('Expired');
});

test('canBeCancelled is true only for active, non-renewing and attention', function () {
    expect(SubscriptionStatus::ACTIVE->canBeCancelled())->toBeTrue()
        ->and(SubscriptionStatus::NON_RENEWING->canBeCancelled())->toBeTrue()
        ->and(SubscriptionStatus::ATTENTION->canBeCancelled())->toBeTrue()
        ->and(SubscriptionStatus::CANCELLED->canBeCancelled())->toBeFalse()
        ->and(SubscriptionStatus::COMPLETED->canBeCancelled())->toBeFalse()
        ->and(SubscriptionStatus::EXPIRED->canBeCancelled())->toBeFalse();
});

test('canBeResumed is true only for cancelled and non-renewing', function () {
    expect(SubscriptionStatus::CANCELLED->canBeResumed())->toBeTrue()
        ->and(SubscriptionStatus::NON_RENEWING->canBeResumed())->toBeTrue()
        ->and(SubscriptionStatus::ACTIVE->canBeResumed())->toBeFalse()
        ->and(SubscriptionStatus::COMPLETED->canBeResumed())->toBeFalse()
        ->and(SubscriptionStatus::ATTENTION->canBeResumed())->toBeFalse()
        ->and(SubscriptionStatus::EXPIRED->canBeResumed())->toBeFalse();
});

test('isBilling is true only for active and non-renewing', function () {
    expect(SubscriptionStatus::ACTIVE->isBilling())->toBeTrue()
        ->and(SubscriptionStatus::NON_RENEWING->isBilling())->toBeTrue()
        ->and(SubscriptionStatus::CANCELLED->isBilling())->toBeFalse()
        ->and(SubscriptionStatus::COMPLETED->isBilling())->toBeFalse()
        ->and(SubscriptionStatus::ATTENTION->isBilling())->toBeFalse()
        ->and(SubscriptionStatus::EXPIRED->isBilling())->toBeFalse();
});

test('allowedTransitions returns the correct set for every case', function () {
    expect(SubscriptionStatus::ACTIVE->allowedTransitions())->toBe([
        SubscriptionStatus::NON_RENEWING,
        SubscriptionStatus::CANCELLED,
        SubscriptionStatus::ATTENTION,
    ])
        ->and(SubscriptionStatus::NON_RENEWING->allowedTransitions())->toBe([
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::CANCELLED,
            SubscriptionStatus::COMPLETED,
        ])
        ->and(SubscriptionStatus::ATTENTION->allowedTransitions())->toBe([
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::CANCELLED,
            SubscriptionStatus::EXPIRED,
        ])
        ->and(SubscriptionStatus::CANCELLED->allowedTransitions())->toBe([SubscriptionStatus::ACTIVE])
        ->and(SubscriptionStatus::COMPLETED->allowedTransitions())->toBe([])
        ->and(SubscriptionStatus::EXPIRED->allowedTransitions())->toBe([]);
});

test('canTransitionTo validates against allowedTransitions', function () {
    expect(SubscriptionStatus::ACTIVE->canTransitionTo(SubscriptionStatus::CANCELLED))->toBeTrue()
        ->and(SubscriptionStatus::ACTIVE->canTransitionTo(SubscriptionStatus::COMPLETED))->toBeFalse()
        ->and(SubscriptionStatus::COMPLETED->canTransitionTo(SubscriptionStatus::ACTIVE))->toBeFalse();
});

test('fromString normalizes provider-specific strings for every branch', function () {
    expect(SubscriptionStatus::fromString('active'))->toBe(SubscriptionStatus::ACTIVE)
        ->and(SubscriptionStatus::fromString('subscribed'))->toBe(SubscriptionStatus::ACTIVE)
        ->and(SubscriptionStatus::fromString('ENABLED'))->toBe(SubscriptionStatus::ACTIVE)
        ->and(SubscriptionStatus::fromString('non-renewing'))->toBe(SubscriptionStatus::NON_RENEWING)
        ->and(SubscriptionStatus::fromString('non_renewing'))->toBe(SubscriptionStatus::NON_RENEWING)
        ->and(SubscriptionStatus::fromString('nonrenewing'))->toBe(SubscriptionStatus::NON_RENEWING)
        ->and(SubscriptionStatus::fromString('will_not_renew'))->toBe(SubscriptionStatus::NON_RENEWING)
        ->and(SubscriptionStatus::fromString('cancelled'))->toBe(SubscriptionStatus::CANCELLED)
        ->and(SubscriptionStatus::fromString('canceled'))->toBe(SubscriptionStatus::CANCELLED)
        ->and(SubscriptionStatus::fromString('disabled'))->toBe(SubscriptionStatus::CANCELLED)
        ->and(SubscriptionStatus::fromString('deleted'))->toBe(SubscriptionStatus::CANCELLED)
        ->and(SubscriptionStatus::fromString('completed'))->toBe(SubscriptionStatus::COMPLETED)
        ->and(SubscriptionStatus::fromString('finished'))->toBe(SubscriptionStatus::COMPLETED)
        ->and(SubscriptionStatus::fromString('ended'))->toBe(SubscriptionStatus::COMPLETED)
        ->and(SubscriptionStatus::fromString('attention'))->toBe(SubscriptionStatus::ATTENTION)
        ->and(SubscriptionStatus::fromString('requires_attention'))->toBe(SubscriptionStatus::ATTENTION)
        ->and(SubscriptionStatus::fromString('payment_required'))->toBe(SubscriptionStatus::ATTENTION)
        ->and(SubscriptionStatus::fromString('expired'))->toBe(SubscriptionStatus::EXPIRED)
        ->and(SubscriptionStatus::fromString('past_due'))->toBe(SubscriptionStatus::EXPIRED)
        ->and(SubscriptionStatus::fromString('unpaid'))->toBe(SubscriptionStatus::EXPIRED);
});

test('fromString trims and lowercases the input before matching', function () {
    expect(SubscriptionStatus::fromString('  ACTIVE  '))->toBe(SubscriptionStatus::ACTIVE);
});

test('fromString throws for an unmappable status string', function () {
    SubscriptionStatus::fromString('totally-unknown');
})->throws(InvalidArgumentException::class, 'Unknown subscription status: totally-unknown');

test('tryFromString returns the enum for a valid status string', function () {
    expect(SubscriptionStatus::tryFromString('active'))->toBe(SubscriptionStatus::ACTIVE)
        ->and(SubscriptionStatus::tryFromString('cancelled'))->toBe(SubscriptionStatus::CANCELLED);
});

test('tryFromString returns null for an unmappable status string', function () {
    expect(SubscriptionStatus::tryFromString('nonsense'))->toBeNull()
        ->and(SubscriptionStatus::tryFromString(''))->toBeNull();
});
