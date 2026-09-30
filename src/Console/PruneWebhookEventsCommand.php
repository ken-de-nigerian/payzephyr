<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use KenDeNigerian\PayZephyr\PaymentManager;

use function Laravel\Prompts\confirm;

/**
 * Delete webhook deduplication records that can no longer matter.
 *
 * A row in webhook_events is what stops a replayed delivery being processed
 * a second time. It is safe to delete only once the provider's own replay
 * window would reject that delivery anyway - otherwise deleting it reopens
 * the replay. So each provider is pruned only when the retention period is
 * longer than its replay horizon (ADR-0017). Providers with no trustworthy
 * window keep their rows unless --include-unbounded says otherwise.
 */
final class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'payzephyr:webhooks:prune
        {--days= : Days of deduplication records to keep (overrides payments.webhook.events.retention_days)}
        {--include-unbounded : Also prune providers with no replay window, accepting that a replay older than the retention period would be processed again}
        {--dry-run : Report what would be deleted without deleting it}
        {--chunk=1000 : Rows to delete per statement}
        {--force : Delete without asking, even when running interactively}';

    protected $description = 'Delete webhook deduplication records older than the retention period, where that is safe';

    public function handle(PaymentManager $manager): int
    {
        $override = $this->option('days');

        if ($override !== null && $override !== '' && ! is_numeric($override)) {
            $this->error('--days must be a number of days to keep.');

            return self::FAILURE;
        }

        $days = $this->retentionDays();

        if ($days === null) {
            $this->error('No retention period configured. Set payments.webhook.events.retention_days, or pass --days.');

            return self::FAILURE;
        }

        if ($days < 1) {
            $this->error("Refusing to prune with --days=$days. Records younger than a day are what stops a provider's retries being processed twice.");

            return self::FAILURE;
        }

        try {
            $providers = array_values(array_filter(
                WebhookEvent::query()->distinct()->orderBy('provider')->pluck('provider')->all(),
                'is_string'
            ));
        } catch (QueryException) {
            $this->error('The webhook_events table does not exist, so there is nothing to prune.');

            return self::FAILURE;
        }

        $plan = $this->plan($manager, $providers, $days);

        foreach ($plan['skipped'] as $provider => $reason) {
            $this->line("  Keeping $provider: $reason");
        }

        $total = array_sum($plan['prune']);

        if ($total === 0) {
            $this->info("Nothing to prune - no prunable records are older than $days days.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($plan['prune'] as $provider => $count) {
                $this->warn("[dry run] $provider: $count records older than $days days would be deleted.");
            }
            $this->line('  Run again without --dry-run to delete them.');

            return self::SUCCESS;
        }

        if (! $this->shouldProceed($total, $days)) {
            $this->comment('Nothing was deleted.');

            return self::SUCCESS;
        }

        $chunk = $this->chunkSize();
        foreach (array_keys($plan['prune']) as $provider) {
            $deleted = $this->prune($provider, $days, $chunk);
            $this->info("Pruned $deleted $provider records older than $days days.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $providers
     * @return array{prune: array<string, int>, skipped: array<string, string>}
     */
    private function plan(PaymentManager $manager, array $providers, int $days): array
    {
        $prune = [];
        $skipped = [];
        $retentionSeconds = $days * 86400;

        foreach ($providers as $provider) {
            $horizon = $this->replayHorizon($manager, $provider);

            if ($horizon === null && ! $this->option('include-unbounded')) {
                $skipped[$provider] = 'it has no replay window, so these records are the only thing stopping a replayed webhook. Pass --include-unbounded to prune it anyway.';

                continue;
            }

            if ($horizon !== null && $retentionSeconds <= $horizon) {
                $skipped[$provider] = "its replay window is $horizon seconds, and records must outlive it or a replay inside the window would be processed again. Keep more than ".(int) ceil($horizon / 86400).' days.';

                continue;
            }

            $count = WebhookEvent::where('provider', $provider)->where('created_at', '<', now()->subDays($days))->count();

            if ($count > 0) {
                $prune[$provider] = $count;
            }
        }

        if ($this->option('include-unbounded')) {
            $this->warn('--include-unbounded: for providers without a replay window, a captured webhook older than the retention period will be processed again if it is replayed.');
        }

        return ['prune' => $prune, 'skipped' => $skipped];
    }

    /**
     * Seconds after which the provider's own checks reject a delivery, or
     * null when nothing does. A provider that cannot be resolved - removed
     * from config, or a custom driver that does not declare one - is treated
     * as unbounded, the side on which being wrong costs disk rather than a
     * replayed payment.
     */
    private function replayHorizon(PaymentManager $manager, string $provider): ?int
    {
        try {
            $driver = $manager->driver($provider);
        } catch (DriverNotFoundException) {
            return null;
        }

        $horizon = method_exists($driver, 'webhookReplayHorizon') ? $driver->webhookReplayHorizon() : null;

        return is_int($horizon) ? $horizon : null;
    }

    private function retentionDays(): ?int
    {
        $override = $this->option('days');

        if ($override !== '' && is_numeric($override)) {
            return (int) $override;
        }

        $config = app('payments.config') ?? config('payments', []);
        $configured = data_get($config, 'webhook.events.retention_days');

        return is_numeric($configured) ? (int) $configured : null;
    }

    private function chunkSize(): int
    {
        $chunk = (int) $this->option('chunk');

        return $chunk > 0 ? $chunk : 1000;
    }

    private function shouldProceed(int $count, int $days): bool
    {
        if ($this->option('force') || $this->option('no-interaction')) {
            $this->line("Deleting $count webhook records older than $days days...");

            return true;
        }

        return confirm(
            label: "Delete $count webhook records older than $days days?",
            default: false,
            hint: 'Only providers whose replay window already rejects anything this old are included.',
        );
    }

    private function prune(string $provider, int $days, int $chunk): int
    {
        $deleted = 0;
        $cutoff = now()->subDays($days);

        do {
            $ids = WebhookEvent::where('provider', $provider)
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += WebhookEvent::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
