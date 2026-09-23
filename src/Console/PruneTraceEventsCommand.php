<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

use function Laravel\Prompts\confirm;

/**
 * Deletes trace events past their retention window.
 *
 * This is the only PayZephyr table that grows per *step* rather than per
 * payment - roughly six to ten rows for a payment that would previously have
 * written one - so it is the only one that needs deliberate housekeeping.
 * Nothing prunes on its own; schedule this:
 *
 *     Schedule::command('payzephyr:trace:prune')->daily();
 */
final class PruneTraceEventsCommand extends Command
{
    protected $signature = 'payzephyr:trace:prune
        {--days= : Days of history to keep (overrides payments.trace.retention_days)}
        {--dry-run : Report what would be deleted without deleting it}
        {--chunk=1000 : Rows to delete per statement}
        {--force : Delete without asking, even when running interactively}';

    protected $description = 'Delete trace events older than the retention window';

    public function handle(): int
    {
        $override = $this->option('days');

        if ($override !== null && $override !== '' && ! is_numeric($override)) {
            $this->error('--days must be a number of days to keep.');

            return self::FAILURE;
        }

        $days = $this->retentionDays();

        if ($days === null) {
            $this->error('No retention window configured. Set payments.trace.retention_days, or pass --days.');

            return self::FAILURE;
        }

        if ($days < 1) {
            $this->error("Refusing to prune with --days=$days. A retention window of less than one day would delete events as fast as they are written.");

            return self::FAILURE;
        }

        try {
            $count = PaymentTraceEvent::olderThan($days)->count();
        } catch (QueryException) {
            $this->error('The trace table does not exist, so there is nothing to prune.');
            $this->line('Run: php artisan payzephyr:install --features=trace');

            return self::FAILURE;
        }

        if ($count === 0) {
            $this->info("Nothing to prune - no trace events are older than $days days.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->reportDryRun($count, $days);
        }

        if (! $this->shouldProceed($count, $days)) {
            $this->comment('Nothing was deleted.');

            return self::SUCCESS;
        }

        $deleted = $this->prune($days, $this->chunkSize());

        $this->info("Pruned $deleted trace events older than $days days.");

        return self::SUCCESS;
    }

    /**
     * The retention window, from --days or config.
     *
     * Deliberately not typed to string: the console hands options over as
     * strings, but Artisan::call() passes whatever the caller gave it, and a
     * scheduled `--days => 30` is an int. Checking for a string only would
     * silently ignore the override and prune against the configured window
     * instead - which is the wrong number, quietly.
     */
    private function retentionDays(): ?int
    {
        $override = $this->option('days');

        if ($override !== '' && is_numeric($override)) {
            return (int) $override;
        }

        $config = app('payments.config') ?? config('payments', []);
        $configured = data_get($config, 'trace.retention_days');

        return is_numeric($configured) ? (int) $configured : null;
    }

    private function chunkSize(): int
    {
        $chunk = (int) $this->option('chunk');

        return $chunk > 0 ? $chunk : 1000;
    }

    private function reportDryRun(int $count, int $days): int
    {
        $this->warn("[dry run] $count trace events are older than $days days and would be deleted.");

        $oldest = PaymentTraceEvent::olderThan($days)->oldest()->first();
        $newest = PaymentTraceEvent::olderThan($days)->latest()->first();

        if ($oldest?->created_at !== null && $newest?->created_at !== null) {
            $this->line('  Spanning '.$oldest->created_at->toDateTimeString().
                ' to '.$newest->created_at->toDateTimeString());
        }

        $this->line('  Run again without --dry-run to delete them.');

        return self::SUCCESS;
    }

    /**
     * Whether to go ahead and delete.
     *
     * The non-interactive path is spelled out rather than left to a prompt's
     * default value. This command's whole purpose is to run unattended on a
     * schedule, and "it happens to proceed because confirm() returns its
     * default when there is no TTY" is a behavior nobody chose.
     */
    private function shouldProceed(int $count, int $days): bool
    {
        if ($this->option('force') || $this->option('no-interaction')) {
            $this->line("Deleting $count trace events older than $days days...");

            return true;
        }

        return confirm(
            label: "Delete $count trace events older than $days days?",
            default: false,
            hint: 'This cannot be undone. Trace history is not recoverable once pruned.',
        );
    }

    /**
     * Delete in chunks, re-running the filter each time.
     *
     * Selecting ids and deleting by id, rather than chunking a cursor over the
     * same rows being deleted underneath it: the filter is re-evaluated on
     * every pass, so there is no pagination offset to invalidate. Deleting by
     * primary key also keeps this portable - `LIMIT` on a DELETE is a MySQL
     * extension that SQLite refuses unless it was compiled to allow it.
     */
    private function prune(int $days, int $chunk): int
    {
        $deleted = 0;

        do {
            $ids = PaymentTraceEvent::olderThan($days)->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += PaymentTraceEvent::whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
