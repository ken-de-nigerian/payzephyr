<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\Timeline;
use KenDeNigerian\PayZephyr\Services\TraceTimelineBuilder;

/**
 * Answers "what happened to this payment?" from the command line.
 *
 * Keyed by reference - the same string the caller got back from
 * Payment::charge() and passes to Payment::verify() - so there is nothing new
 * to look up before you can use it.
 */
final class TraceCommand extends Command
{
    protected $signature = 'payzephyr:trace
        {reference : The payment reference to reconstruct}
        {--detailed : Also report what is worth attention in the timeline}
        {--json : Output machine-readable JSON instead of text}
        {--provider= : Show only the steps involving this provider}';

    protected $description = 'Reconstruct the timeline of a payment, step by step';

    public function handle(TraceTimelineBuilder $builder): int
    {
        $reference = (string) $this->argument('reference');

        try {
            $timeline = $builder->build($reference);
        } catch (QueryException) {
            return $this->reportNoTable();
        }

        $provider = $this->option('provider');
        if (is_string($provider) && $provider !== '') {
            $timeline = new Timeline($reference, $timeline->forProvider($provider));
        }

        if ($timeline->isEmpty()) {
            return $this->reportNothingFound($reference, is_string($provider) ? $provider : null);
        }

        return $this->option('json')
            ? $this->renderJson($timeline)
            : $this->renderText($timeline);
    }

    /**
     * The feature was never installed. Distinct from "no events for this
     * reference", and fixable by a single documented command - so say that
     * rather than surfacing a driver-level complaint about a missing table.
     */
    private function reportNoTable(): int
    {
        $this->error('The trace table does not exist.');
        $this->line('Run: php artisan payzephyr:install --features=trace');

        return self::FAILURE;
    }

    /**
     * Not an error. A reference with no events is the normal state of any
     * payment made before tracing was switched on, and of every payment if it
     * was never switched on at all - so say which, rather than implying the
     * payment does not exist.
     */
    private function reportNothingFound(string $reference, ?string $provider): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'reference' => $reference,
                'events' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->warn("No trace events recorded for [$reference].");

        if ($provider !== null) {
            $this->line("Filtered to provider [$provider]; try again without --provider.");

            return self::SUCCESS;
        }

        $config = app('payments.config') ?? config('payments', []);

        if (! (data_get($config, 'features.trace') ?? false)) {
            $this->line('Tracing is switched off - set PAYZEPHYR_FEATURE_TRACE=true to record new payments.');
        } else {
            $this->line('Tracing is on, so this payment either predates it or was never traced.');
        }

        return self::SUCCESS;
    }

    private function renderText(Timeline $timeline): int
    {
        $this->line("Payment timeline: $timeline->reference");
        $this->line(str_repeat('=', 72));
        $this->newLine();

        foreach ($timeline->all() as $event) {
            $this->renderEvent($event);
        }

        $this->newLine();
        $this->renderSummary($timeline);

        if ($this->option('detailed')) {
            $this->renderFindings($timeline);
        }

        return self::SUCCESS;
    }

    private function renderEvent(PaymentTraceEvent $event): void
    {
        $line = $event->formatForTimeline();

        match (true) {
            $event->isError() => $this->error("  $line"),
            $event->isTerminal() => $this->info("  $line"),
            default => $this->line("  $line"),
        };

        if ($event->http_status_code !== null) {
            $this->line("        HTTP $event->http_status_code");
        }

        if ($event->response_time_ms !== null) {
            $this->line("        {$event->response_time_ms}ms");
        }
    }

    private function renderSummary(Timeline $timeline): void
    {
        $this->line(str_repeat('-', 72));
        $this->line('Summary');
        $this->line('  Events:   '.$timeline->all()->count());
        $this->line('  Errors:   '.$timeline->errors()->count());

        $duration = $timeline->duration();
        if ($duration !== null) {
            $this->line("  Duration: {$duration}ms");
        }

        $terminal = $timeline->terminal();
        $this->line('  Outcome:  '.($terminal !== null
            ? $terminal->event->value
            : 'still open - no terminal event recorded'));
    }

    private function renderFindings(Timeline $timeline): void
    {
        $findings = $timeline->analyze();

        $this->newLine();
        $this->line(str_repeat('-', 72));

        if ($findings === []) {
            $this->info('Nothing worth flagging in this timeline.');

            return;
        }

        $this->line('Worth a look');

        foreach ($findings as $finding) {
            $text = "  [{$finding['severity']}] {$finding['message']}";

            match ($finding['severity']) {
                'critical', 'high' => $this->error($text),
                default => $this->warn($text),
            };
        }
    }

    private function renderJson(Timeline $timeline): int
    {
        $this->line((string) json_encode([
            'reference' => $timeline->reference,
            'outcome' => $timeline->terminal()?->event->value,
            'succeeded' => $timeline->succeeded(),
            'failed' => $timeline->failed(),
            'duration_ms' => $timeline->duration(),
            'error_count' => $timeline->errors()->count(),
            'findings' => $timeline->analyze(),
            'events' => $timeline->all()->map(fn (PaymentTraceEvent $event): array => [
                'recorded_at' => $event->created_at?->toIso8601String(),
                'event' => $event->event->value,
                'direction' => $event->direction->value,
                'provider' => $event->provider,
                'correlation_id' => $event->correlation_id,
                'http_method' => $event->http_method,
                'http_url' => $event->http_url,
                'http_status_code' => $event->http_status_code,
                'response_time_ms' => $event->response_time_ms,
                'payload' => $event->payload,
                'metadata' => $event->metadata,
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
