<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;

/**
 * One-time, opt-in data fix for refund_transactions rows written before the
 * fix that normalizes RefundResponseDTO::status to RefundStatus::value
 * before persisting (see CHANGELOG.md). Rows written before that fix may
 * hold a raw, un-normalized provider status string (e.g. Square/PayPal/
 * Monnify's uppercase "PENDING"/"COMPLETED") that
 * RefundRepositoryInterface::hasInFlightRefund()/sumRefundedAmount() won't
 * match against their fixed lowercase canonical set - meaning the
 * duplicate-refund and over-refund guards silently ignore those old rows.
 *
 * Deliberately not an automatic migration: whether existing production data
 * should be rewritten is the application owner's call, not something this
 * package does on their behalf during an upgrade. Idempotent and safe to
 * run repeatedly - rows already holding a canonical value are left alone,
 * and a row whose status can't be mapped to any known RefundStatus is
 * reported but never guessed at.
 */
final class NormalizeRefundStatusCommand extends Command
{
    protected $signature = 'payzephyr:refunds:normalize-status
        {--dry-run : Show what would change without writing anything}';

    protected $description = 'One-time backfill: normalize refund_transactions.status to canonical RefundStatus values for rows written before this was fixed';

    public function handle(): int
    {
        $table = config('payments.refunds.logging.table', 'refund_transactions');

        if (! Schema::hasTable($table)) {
            $this->info("No \"$table\" table found - nothing to do.");

            return self::SUCCESS;
        }

        $rows = DB::table($table)->select(['id', 'refund_reference', 'status'])->get();

        $toUpdate = [];
        $unmappable = [];

        foreach ($rows as $row) {
            $canonical = RefundStatus::tryFromString((string) $row->status);

            if ($canonical === null) {
                $unmappable[] = $row;

                continue;
            }

            if ($canonical->value !== $row->status) {
                $toUpdate[] = ['id' => $row->id, 'reference' => $row->refund_reference, 'from' => $row->status, 'to' => $canonical->value];
            }
        }

        if ($unmappable !== []) {
            $this->warn(count($unmappable).' row(s) have a status that cannot be mapped to any known RefundStatus and were left untouched:');
            foreach ($unmappable as $row) {
                $this->line("  - #$row->id ($row->refund_reference): \"$row->status\"");
            }
        }

        if ($toUpdate === []) {
            $this->info('Every mappable row already uses a canonical status - nothing to do.');

            return self::SUCCESS;
        }

        $this->info(count($toUpdate).' row(s) will be normalized:');
        foreach ($toUpdate as $change) {
            $this->line("  - #{$change['id']} ({$change['reference']}): \"{$change['from']}\" -> \"{$change['to']}\"");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run - no changes written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($table, $toUpdate) {
            foreach ($toUpdate as $change) {
                DB::table($table)->where('id', $change['id'])->update(['status' => $change['to']]);
            }
        });

        $this->info('Done - '.count($toUpdate).' row(s) normalized.');

        return self::SUCCESS;
    }
}
