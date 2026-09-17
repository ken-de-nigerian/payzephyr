<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Collection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Every recorded step of one payment, in the order it happened.
 *
 * @phpstan-type TraceEventCollection Collection<int, PaymentTraceEvent>
 */
final readonly class Timeline
{
    /**
     * @param  Collection<int, PaymentTraceEvent>  $events
     */
    public function __construct(
        public string $reference,
        public Collection $events
    ) {}

    /**
     * @return Collection<int, PaymentTraceEvent>
     */
    public function all(): Collection
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return $this->events->isEmpty();
    }

    /**
     * @return Collection<int, PaymentTraceEvent>
     */
    public function forProvider(string $provider): Collection
    {
        return $this->events->filter(
            fn (PaymentTraceEvent $event): bool => $event->provider === $provider
        )->values();
    }

    /**
     * @return Collection<int, PaymentTraceEvent>
     */
    public function errors(): Collection
    {
        return $this->events->filter(
            fn (PaymentTraceEvent $event): bool => $event->isError()
        )->values();
    }

    /**
     * The first event that ended the payment, if one has been recorded.
     */
    public function terminal(): ?PaymentTraceEvent
    {
        return $this->events->first(
            fn (PaymentTraceEvent $event): bool => $event->isTerminal()
        );
    }

    public function succeeded(): bool
    {
        return $this->terminal()?->event === TraceEvent::PAYMENT_COMPLETED;
    }

    public function failed(): bool
    {
        return $this->terminal()?->event === TraceEvent::PAYMENT_FAILED;
    }

    /**
     * Wall-clock milliseconds from the first recorded event to the last, or
     * null when there is nothing recorded.
     */
    public function duration(): ?int
    {
        if ($this->events->isEmpty()) {
            return null;
        }

        $first = $this->events->first();
        $last = $this->events->last();

        if ($first->created_at === null || $last->created_at === null) {
            return null;
        }

        return (int) round($first->created_at->diffInMilliseconds($last->created_at));
    }

    /**
     * What is worth someone's attention in this timeline.
     *
     * One analyzer, one threshold, one vocabulary. The package this was folded
     * in from shipped two overlapping ones - analyze() and detectAnomalies() -
     * which both reported slow responses and both reported missing responses,
     * using different thresholds and different wording for the same finding.
     *
     * Ordered by severity, and deliberately short: a list that flags ordinary
     * events teaches people to skim past it. Everything here is either a
     * problem or a question someone has to answer.
     *
     * @return array<int, array{severity: string, type: string, message: string}>
     */
    public function analyze(): array
    {
        $findings = [];

        foreach ($this->countsBySeverity() as [$severity, $event, $type, $singular, $plural]) {
            $count = $this->events->filter(
                fn (PaymentTraceEvent $e): bool => $e->event === $event
            )->count();

            if ($count > 0) {
                $findings[] = [
                    'severity' => $severity,
                    'type' => $type,
                    'message' => $count === 1 ? $singular : "$plural ($count occurrences)",
                ];
            }
        }

        foreach ($this->orphanedRequests() as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->slowResponses() as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Findings that are a simple "did this event happen at all".
     *
     * @return array<int, array{0: string, 1: TraceEvent, 2: string, 3: string, 4: string}>
     */
    private function countsBySeverity(): array
    {
        return [
            ['critical', TraceEvent::CHARGE_AMBIGUOUS, 'ambiguous_outcome',
                'Charge outcome unknown - the provider may have taken the money. Verify before retrying.',
                'Charge outcome unknown on more than one attempt'],
            ['critical', TraceEvent::VERIFICATION_NOT_PERSISTED, 'not_persisted',
                'The provider confirmed this payment but the local record was not updated.',
                'The provider confirmed this payment but the local record was not updated'],
            ['high', TraceEvent::PROVIDER_TIMEOUT, 'timeout',
                'A provider request timed out.',
                'Provider requests timed out'],
            ['high', TraceEvent::RETRY_ABANDONED, 'retries_abandoned',
                'A webhook delivery was retried until PayZephyr gave up.',
                'Webhook deliveries were retried until PayZephyr gave up'],
            ['high', TraceEvent::WEBHOOK_QUEUE_FAILED, 'never_queued',
                'A webhook was accepted but never queued, so it was never processed and never retried.',
                'Webhooks were accepted but never queued'],
            ['medium', TraceEvent::WEBHOOK_DUPLICATE, 'duplicate_webhook',
                'A duplicate webhook delivery was received.',
                'Duplicate webhook deliveries were received'],
            ['medium', TraceEvent::WEBHOOK_VALIDATION_FAILED, 'bad_signature',
                'A webhook failed signature validation.',
                'Webhooks failed signature validation'],
        ];
    }

    /**
     * Requests that were sent and never answered.
     *
     * Matched within a correlation group, which is what that identifier exists
     * for: one group is one provider round trip, so a group holding a request
     * and no reply is a call that went out and vanished. Events without a
     * correlation id are skipped rather than guessed at.
     *
     * @return array<int, array{severity: string, type: string, message: string}>
     */
    private function orphanedRequests(): array
    {
        $answered = [
            TraceEvent::PROVIDER_RESPONSE_RECEIVED,
            TraceEvent::PROVIDER_ERROR,
            TraceEvent::PROVIDER_TIMEOUT,
            TraceEvent::PROVIDER_EXCEPTION,
        ];

        $findings = [];

        foreach ($this->events->groupBy('correlation_id') as $correlationId => $group) {
            if ($correlationId === '' || $group->first()->correlation_id === null) {
                continue;
            }

            $sent = $group->first(
                fn (PaymentTraceEvent $e): bool => $e->event === TraceEvent::PROVIDER_REQUEST_SENT
            );

            if ($sent === null) {
                continue;
            }

            $reply = $group->first(
                fn (PaymentTraceEvent $e): bool => in_array($e->event, $answered, true)
            );

            if ($reply === null) {
                $findings[] = [
                    'severity' => 'high',
                    'type' => 'orphaned_request',
                    'message' => 'A request to '.($sent->provider ?? 'a provider').
                        ' was sent and no response was ever recorded.',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array{severity: string, type: string, message: string}>
     */
    private function slowResponses(): array
    {
        $config = app('payments.config') ?? config('payments', []);
        $threshold = (int) (data_get($config, 'trace.slow_response_ms') ?? 5000);

        $slow = $this->events->filter(
            fn (PaymentTraceEvent $e): bool => $e->response_time_ms !== null
                && $e->response_time_ms > $threshold
        );

        if ($slow->isEmpty()) {
            return [];
        }

        $slowest = $slow->max('response_time_ms');

        return [[
            'severity' => 'medium',
            'type' => 'slow_response',
            'message' => "A provider took {$slowest}ms to respond (over the {$threshold}ms threshold).",
        ]];
    }

    public function toText(): string
    {
        if ($this->events->isEmpty()) {
            return "No trace events recorded for reference: $this->reference";
        }

        $lines = ["Payment timeline: $this->reference", str_repeat('=', 80), ''];

        foreach ($this->events as $event) {
            $lines[] = $event->formatForTimeline();
        }

        $lines[] = '';
        $lines[] = 'Summary:';
        $lines[] = '- Total events: '.$this->events->count();
        $lines[] = '- Errors: '.$this->errors()->count();

        $duration = $this->duration();
        if ($duration !== null) {
            $lines[] = "- Duration: {$duration}ms";
        }

        $terminal = $this->terminal();
        $lines[] = '- Status: '.($terminal !== null ? $terminal->event->value : 'incomplete (no terminal event)');

        return implode("\n", $lines);
    }
}
