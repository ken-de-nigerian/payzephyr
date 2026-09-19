<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Housekeeping for the only PayZephyr table that grows per step.
 *
 * A payment that used to write one transaction row now writes six to ten trace
 * rows, so this is the one table that will quietly become a problem if nobody
 * schedules anything. Nothing prunes on its own; that is deliberate, and it is
 * why the command has to be safe to run unattended.
 */
function seedTraceEventAged(string $reference, int $daysAgo): PaymentTraceEvent
{
    $event = PaymentTraceEvent::create([
        'reference' => $reference,
        'event' => TraceEvent::PAYMENT_INITIATED->value,
        'direction' => TraceDirection::INTERNAL->value,
        'payload' => [],
        'metadata' => [],
    ]);

    $timestamp = now()->subDays($daysAgo);
    PaymentTraceEvent::where('id', $event->id)->update([
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    return $event;
}

function pruneOutput(array $options = []): string
{
    Artisan::call('payzephyr:trace:prune', array_merge(['--no-interaction' => true], $options));

    return Artisan::output();
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    config(['payments.features.trace' => true, 'payments.trace.retention_days' => 90]);
});

// ---------------------------------------------------------------------------
// What gets deleted
// ---------------------------------------------------------------------------

test('events past the retention window are deleted and newer ones are not', function () {
    seedTraceEventAged('PZ_old_a', 120);
    seedTraceEventAged('PZ_old_b', 91);
    seedTraceEventAged('PZ_recent', 30);

    $output = pruneOutput();

    expect($output)->toContain('Pruned 2 trace events')
        ->and(PaymentTraceEvent::count())->toBe(1)
        ->and(PaymentTraceEvent::first()->reference)->toBe('PZ_recent');
});

test('an empty table is reported rather than treated as a failure', function () {
    $exit = Artisan::call('payzephyr:trace:prune', ['--no-interaction' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to prune');
});

test('the retention window can be overridden for a one-off prune', function () {
    seedTraceEventAged('PZ_a', 40);

    pruneOutput(['--days' => 30]);

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('deletion happens in chunks without losing rows', function () {
    // The pattern under test: select ids, delete by id, re-run the filter.
    // Chunking a cursor over the same rows being deleted underneath it is the
    // version that silently skips records.
    for ($i = 0; $i < 25; $i++) {
        seedTraceEventAged("PZ_bulk_$i", 100);
    }
    seedTraceEventAged('PZ_keep', 1);

    $output = pruneOutput(['--chunk' => 4]);

    expect($output)->toContain('Pruned 25 trace events')
        ->and(PaymentTraceEvent::count())->toBe(1)
        ->and(PaymentTraceEvent::first()->reference)->toBe('PZ_keep');
});

test('a chunk size of zero falls back to a sane default rather than looping forever', function () {
    seedTraceEventAged('PZ_a', 100);

    pruneOutput(['--chunk' => 0]);

    expect(PaymentTraceEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// --dry-run
// ---------------------------------------------------------------------------

test('a dry run reports what would go and deletes nothing', function () {
    seedTraceEventAged('PZ_a', 120);
    seedTraceEventAged('PZ_b', 100);

    $output = pruneOutput(['--dry-run' => true]);

    expect($output)->toContain('[dry run] 2 trace events')
        ->and($output)->toContain('Run again without --dry-run')
        ->and(PaymentTraceEvent::count())->toBe(2);
});

test('a dry run shows the span of what it would delete', function () {
    seedTraceEventAged('PZ_a', 200);
    seedTraceEventAged('PZ_b', 100);

    expect(pruneOutput(['--dry-run' => true]))->toContain('Spanning');
});

// ---------------------------------------------------------------------------
// Running unattended
// ---------------------------------------------------------------------------

test('a non-interactive run proceeds deliberately, not by falling through a prompt', function () {
    // The point of the explicit branch: this command exists to run on a
    // schedule, and "it proceeds because confirm() returns its default when
    // there is no TTY" is a behaviour nobody chose.
    seedTraceEventAged('PZ_a', 100);

    $output = pruneOutput();

    expect($output)->toContain('Deleting 1 trace events older than 90 days')
        ->and(PaymentTraceEvent::count())->toBe(0);
});

test('--force deletes without asking', function () {
    seedTraceEventAged('PZ_a', 100);

    Artisan::call('payzephyr:trace:prune', ['--force' => true]);

    expect(PaymentTraceEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Refusing to do something destructive by accident
// ---------------------------------------------------------------------------

test('a retention window under a day is refused', function () {
    // --days=0 would delete events as fast as they are written, which is never
    // what anyone means.
    seedTraceEventAged('PZ_a', 100);

    $exit = Artisan::call('payzephyr:trace:prune', ['--no-interaction' => true, '--days' => 0]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to prune')
        ->and(PaymentTraceEvent::count())->toBe(1);
});

test('no configured retention window is an error, not a silent no-op', function () {
    config(['payments.trace.retention_days' => null]);
    app()->forgetInstance('payments.config');

    seedTraceEventAged('PZ_a', 100);

    $exit = Artisan::call('payzephyr:trace:prune', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('No retention window configured')
        ->and(PaymentTraceEvent::count())->toBe(1);
});

test('a missing trace table says how to install it', function () {
    Schema::drop('payment_trace_events');

    $exit = Artisan::call('payzephyr:trace:prune', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('payzephyr:install --features=trace');
});

test('the trace command also explains a missing table instead of throwing', function () {
    Schema::drop('payment_trace_events');

    $exit = Artisan::call('payzephyr:trace', ['reference' => 'PZ_a']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('payzephyr:install --features=trace');
});

test('a non-numeric retention window is rejected rather than ignored', function () {
    // Silently falling back to the configured window would prune against a
    // number the operator did not ask for.
    seedTraceEventAged('PZ_a', 100);

    $exit = Artisan::call('payzephyr:trace:prune', ['--no-interaction' => true, '--days' => 'lots']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--days must be a number')
        ->and(PaymentTraceEvent::count())->toBe(1);
});

test('an interactive run deletes only once the operator agrees', function () {
    seedTraceEventAged('PZ_a', 100);

    $this->artisan('payzephyr:trace:prune')
        ->expectsConfirmation('Delete 1 trace events older than 90 days?', 'yes')
        ->assertExitCode(0);

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('declining the prompt leaves every row in place', function () {
    // The prompt defaults to no. Trace history is not recoverable, so a
    // mistyped command at a terminal should cost nothing.
    seedTraceEventAged('PZ_a', 100);

    $this->artisan('payzephyr:trace:prune')
        ->expectsConfirmation('Delete 1 trace events older than 90 days?', 'no')
        ->expectsOutputToContain('Nothing was deleted.')
        ->assertExitCode(0);

    expect(PaymentTraceEvent::count())->toBe(1);
});

test('a total that is an exact multiple of the chunk size still terminates', function () {
    // The loop continues while a full chunk comes back, so an exact multiple
    // means one final pass that finds nothing. Getting that wrong is an
    // infinite loop on a scheduled command.
    for ($i = 0; $i < 4; $i++) {
        seedTraceEventAged("PZ_exact_$i", 100);
    }

    expect(pruneOutput(['--chunk' => 4]))->toContain('Pruned 4 trace events')
        ->and(PaymentTraceEvent::count())->toBe(0);
});
