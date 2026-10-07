<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * A webhook_events row is what stops a replayed delivery being processed a
 * second time, so deleting one is only safe once the provider's own replay
 * window would reject that delivery anyway (ADR-0017). These tests are about
 * that line: what is deleted, and above all what is kept.
 */
function seedWebhookEventAged(string $provider, string $key, int $daysAgo): void
{
    WebhookEvent::create(['provider' => $provider, 'event_key' => $key]);

    $timestamp = now()->subDays($daysAgo);
    WebhookEvent::where('provider', $provider)->where('event_key', $key)->update([
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function pruneWebhooks(array $options = []): array
{
    $exitCode = Artisan::call('payzephyr:webhooks:prune', array_merge(['--no-interaction' => true], $options));

    return [$exitCode, Artisan::output()];
}

function remainingWebhookKeys(): array
{
    return WebhookEvent::query()->orderBy('event_key')->pluck('event_key')->all();
}

beforeEach(function (): void {
    config(['payments.webhook.events.retention_days' => 30]);
    app()->forgetInstance('payments.config');
    app()->forgetInstance(PaymentManager::class);
});

test('the command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('payzephyr:webhooks:prune');
});

// ---------------------------------------------------------------------------
// What gets deleted
// ---------------------------------------------------------------------------

test('records past the retention period are deleted for providers whose window has closed on them', function (): void {
    // Stripe and Paddle reject a delivery whose signed timestamp is more than
    // five minutes old; PayPal and Square reject an event older than 72 hours.
    // A 31-day-old record can no longer stop anything those checks would not.
    seedWebhookEventAged('stripe', 'evt_old', 31);
    seedWebhookEventAged('stripe', 'evt_recent', 5);
    seedWebhookEventAged('paddle', 'ntf_old', 45);
    seedWebhookEventAged('paypal', 'WH-old', 60);
    seedWebhookEventAged('square', 'sq_old', 31);

    [$exitCode, $output] = pruneWebhooks();

    expect($exitCode)->toBe(0)
        ->and(remainingWebhookKeys())->toBe(['evt_recent'])
        ->and($output)->toContain('Pruned 1 stripe records older than 30 days.')
        ->and($output)->toContain('Pruned 1 paypal records older than 30 days.');
});

test('large prunes are deleted in chunks until nothing old is left', function (): void {
    foreach (range(1, 5) as $i) {
        seedWebhookEventAged('stripe', "evt_$i", 40);
    }

    [, $output] = pruneWebhooks(['--chunk' => 2]);

    expect(WebhookEvent::count())->toBe(0)
        ->and($output)->toContain('Pruned 5 stripe records');
});

test('a chunk size that is not a positive number falls back to the default', function (): void {
    seedWebhookEventAged('stripe', 'evt_1', 40);

    pruneWebhooks(['--chunk' => '0']);

    expect(WebhookEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// What is kept
// ---------------------------------------------------------------------------

test('providers with no replay window keep every record, and say why', function (): void {
    // For these, the record is the only thing between a captured webhook and
    // it being processed again. Deleting it reopens the replay.
    foreach (['paystack', 'flutterwave', 'monnify', 'opay', 'razorpay', 'mollie'] as $provider) {
        seedWebhookEventAged($provider, "{$provider}_old", 400);
    }

    [$exitCode, $output] = pruneWebhooks();

    expect($exitCode)->toBe(0)
        ->and(WebhookEvent::count())->toBe(6)
        ->and($output)->toContain('Keeping paystack: it has no replay window')
        ->and($output)->toContain('Pass --include-unbounded to prune it anyway.')
        ->and($output)->toContain('Nothing to prune');
});

test('--include-unbounded prunes them too, and states what that gives up', function (): void {
    seedWebhookEventAged('paystack', 'paystack_old', 400);
    seedWebhookEventAged('paystack', 'paystack_recent', 3);

    [, $output] = pruneWebhooks(['--include-unbounded' => true]);

    expect(remainingWebhookKeys())->toBe(['paystack_recent'])
        ->and($output)->toContain('a captured webhook older than the retention period will be processed again if it is replayed');
});

test('a provider whose window outlasts the retention period is kept, not pruned into a replay gap', function (): void {
    // With a two-day retention, a Square event 2.5 days old is still inside its
    // 72-hour window. Deleting its record would let a replay of it through.
    seedWebhookEventAged('square', 'sq_mid', 3);
    seedWebhookEventAged('stripe', 'evt_mid', 3);

    [, $output] = pruneWebhooks(['--days' => 2]);

    expect(remainingWebhookKeys())->toBe(['sq_mid'])
        ->and($output)->toContain('Keeping square: its replay window is 259200 seconds')
        ->and($output)->toContain('Keep more than 3 days.');
});

test('a provider that can no longer be resolved is kept, since nothing is known about its window', function (): void {
    seedWebhookEventAged('retired_gateway', 'old_key', 400);

    [, $output] = pruneWebhooks();

    expect(WebhookEvent::count())->toBe(1)
        ->and($output)->toContain('Keeping retired_gateway');
});

test('a custom driver that does not declare a replay horizon is kept', function (): void {
    // A driver implementing DriverInterface directly has no
    // webhookReplayHorizon(), so there is no evidence any window protects it.
    $manager = app(PaymentManager::class);
    injectFakeDrivers($manager, ['acmepay' => makeCountingSuccessDriver('acmepay')]);
    seedWebhookEventAged('acmepay', 'acme_old', 400);

    Artisan::call('payzephyr:webhooks:prune', ['--no-interaction' => true]);

    expect(WebhookEvent::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Reporting and confirmation
// ---------------------------------------------------------------------------

test('--dry-run reports per provider and deletes nothing', function (): void {
    seedWebhookEventAged('stripe', 'evt_old', 31);
    seedWebhookEventAged('paypal', 'WH-old', 31);

    [$exitCode, $output] = pruneWebhooks(['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and(WebhookEvent::count())->toBe(2)
        ->and($output)->toContain('[dry run] stripe: 1 records older than 30 days would be deleted.')
        ->and($output)->toContain('[dry run] paypal: 1 records older than 30 days would be deleted.');
});

test('interactively, nothing is deleted unless the prompt is confirmed', function (): void {
    seedWebhookEventAged('stripe', 'evt_old', 31);

    $this->artisan('payzephyr:webhooks:prune')
        ->expectsConfirmation('Delete 1 webhook records older than 30 days?', 'no')
        ->expectsOutput('Nothing was deleted.')
        ->assertExitCode(0);

    expect(WebhookEvent::count())->toBe(1);

    $this->artisan('payzephyr:webhooks:prune')
        ->expectsConfirmation('Delete 1 webhook records older than 30 days?', 'yes')
        ->assertExitCode(0);

    expect(WebhookEvent::count())->toBe(0);
});

test('--force deletes without asking even interactively', function (): void {
    seedWebhookEventAged('stripe', 'evt_old', 31);

    $this->artisan('payzephyr:webhooks:prune', ['--force' => true])->assertExitCode(0);

    expect(WebhookEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

test('--days overrides the configured retention', function (): void {
    seedWebhookEventAged('stripe', 'evt_10', 10);

    pruneWebhooks(['--days' => 7]);

    expect(WebhookEvent::count())->toBe(0);
});

test('a non-numeric --days is refused', function (): void {
    [$exitCode, $output] = pruneWebhooks(['--days' => 'forever']);

    expect($exitCode)->toBe(1)->and($output)->toContain('--days must be a number of days to keep.');
});

test('a retention of less than a day is refused', function (): void {
    seedWebhookEventAged('stripe', 'evt_old', 31);

    [$exitCode, $output] = pruneWebhooks(['--days' => 0]);

    expect($exitCode)->toBe(1)
        ->and(WebhookEvent::count())->toBe(1)
        ->and($output)->toContain('Refusing to prune with --days=0.');
});

test('with no retention configured and no --days, nothing is guessed', function (): void {
    config(['payments.webhook.events.retention_days' => null]);
    app()->forgetInstance('payments.config');

    [$exitCode, $output] = pruneWebhooks();

    expect($exitCode)->toBe(1)->and($output)->toContain('No retention period configured.');
});

test('a missing table is reported, not thrown', function (): void {
    Schema::drop('webhook_events');

    [$exitCode, $output] = pruneWebhooks();

    expect($exitCode)->toBe(1)->and($output)->toContain('The webhook_events table does not exist');
});

test('a count that is an exact multiple of the chunk size stops cleanly after the last full chunk', function (): void {
    foreach (range(1, 4) as $i) {
        seedWebhookEventAged('stripe', "evt_$i", 40);
    }

    [, $output] = pruneWebhooks(['--chunk' => 2]);

    expect(WebhookEvent::count())->toBe(0)
        ->and($output)->toContain('Pruned 4 stripe records');
});

test('webhook_events is indexed for the query pruning runs', function (): void {
    // ADR-0005 said the table had this index; it never did.
    expect(Schema::hasIndex('webhook_events', 'webhook_events_provider_created_at_index'))->toBeTrue();
});
