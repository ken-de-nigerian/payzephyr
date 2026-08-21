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
