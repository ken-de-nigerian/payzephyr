<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;

uses(RefreshDatabase::class);

/**
 * A plan with no fixed price is not a free plan.
 *
 * Stripe returns a null unit_amount for tiered, metered and usage-based
 * prices. Every subscription mapping used to collapse that to 0.0, which is
 * indistinguishable from a plan that genuinely costs nothing - so a customer
 * on metered billing appeared, in PayZephyr's own records, to be paying
 * nothing. The amount is nullable so the difference survives.
 */
test('a plan with no fixed price reports no amount rather than a free one', function () {
    $metered = PlanResponseDTO::fromArray([
        'plan_code' => 'price_metered',
        'name' => 'Usage based',
        'currency' => 'USD',
    ]);

    $free = PlanResponseDTO::fromArray([
        'plan_code' => 'price_free',
        'name' => 'Free forever',
        'amount' => 0,
        'currency' => 'USD',
    ]);

    expect($metered->amount)->toBeNull()
        ->and($free->amount)->toBe(0.0)
        ->and($metered->amount)->not->toBe($free->amount);
});

test('a subscription with no reported amount is distinguishable from a zero one', function () {
    expect(SubscriptionResponseDTO::fromArray(['subscription_code' => 'sub_1'])->amount)->toBeNull()
        ->and(SubscriptionResponseDTO::fromArray(['subscription_code' => 'sub_2', 'amount' => 0])->amount)->toBe(0.0);
});

test('an explicit zero still round-trips as zero', function () {
    // The change is about absence. A provider reporting a genuine zero is
    // telling us something real and must not be turned into null.
    expect(PlanResponseDTO::fromArray(['amount' => 0])->amount)->toBe(0.0)
        ->and(PlanResponseDTO::fromArray(['amount' => 500])->amount)->toBe(5.0);
});

test('a null amount serializes as null rather than zero', function () {
    $dto = PlanResponseDTO::fromArray(['plan_code' => 'price_metered']);

    expect($dto->toArray()['amount'])->toBeNull()
        ->and($dto->jsonSerialize()['amount'])->toBeNull()
        ->and($dto->getAmountInMajorUnits())->toBeNull();
});

test('a subscription with no amount can actually be stored', function () {
    // The half of this change that would otherwise fail at runtime rather
    // than at the type level: subscription_transactions.amount was NOT NULL,
    // so a metered subscription would have thrown on insert.
    // The same columns LogsSubscriptionTransactions writes.
    $row = SubscriptionTransaction::create([
        'subscription_code' => 'sub_metered',
        'provider' => 'stripe',
        'status' => 'active',
        'plan_code' => 'price_metered',
        'customer_email' => 'test@example.com',
        'amount' => null,
        'currency' => 'USD',
    ]);

    expect($row->refresh()->amount)->toBeNull()
        ->and(SubscriptionTransaction::whereNull('amount')->count())->toBe(1);
});
