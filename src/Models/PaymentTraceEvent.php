<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;

/**
 * One recorded step in a payment's timeline.
 *
 * Unlike PaymentTransaction, which holds a payment's current state and is
 * overwritten as that state changes, these rows are append-only: the value is
 * in the sequence, so nothing here is ever updated in place.
 *
 * @method static Builder<PaymentTraceEvent> timelineFor(string $reference)
 * @method static Builder<PaymentTraceEvent> olderThan(int $days)
 * @method static Builder<PaymentTraceEvent> whereIn(string $column, mixed $values)
 * @method static Builder<PaymentTraceEvent> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static PaymentTraceEvent create(array<string, mixed> $attributes = [])
 * @method static Builder<PaymentTraceEvent> delete()
 *
 * @property int $id
 * @property string $reference
 * @property string|null $provider
 * @property string|null $correlation_id
 * @property TraceEvent $event
 * @property TraceDirection $direction
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $metadata
 * @property string|null $http_method
 * @property string|null $http_url
 * @property int|null $http_status_code
 * @property int|null $response_time_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PaymentTraceEvent extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'reference',
        'provider',
        'correlation_id',
        'event',
        'direction',
        'payload',
        'metadata',
        'http_method',
        'http_url',
        'http_status_code',
        'response_time_ms',
    ];

    protected $table = 'payment_trace_events';

    /**
     * Millisecond precision, not Laravel's default whole seconds. Several
     * steps of one payment routinely land inside the same second, and the gap
     * between them is exactly what a timeline is read for. The column is
     * declared timestamps(3) to match.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var array<string, string> */
    protected $casts = [
        'event' => TraceEvent::class,
        'direction' => TraceDirection::class,
        'payload' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function configuredTableNameKey(): string
    {
        return 'trace.table';
    }

    /**
     * Trace can be pointed at its own connection: it is the highest-volume
     * table PayZephyr writes, and keeping it off the primary is a reasonable
     * thing to want in production.
     */
    public function getConnectionName(): ?string
    {
        $config = app('payments.config') ?? config('payments', []);

        $configured = data_get($config, 'trace.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * Every event recorded for one payment, oldest first.
     *
     * Filter and ordering are one scope rather than two chained ones so the
     * call site stays a single static call: without larastan, PHPStan cannot
     * see scope methods on a Builder returned from another scope.
     *
     * Ordered by id as well as timestamp, because several events in one
     * payment routinely land inside the same millisecond and insertion order
     * is the only thing that separates them.
     *
     * @param  Builder<PaymentTraceEvent>  $query
     * @return Builder<PaymentTraceEvent>
     */
    public function scopeTimelineFor(Builder $query, string $reference): Builder
    {
        /** @var Builder<self> */
        return $query->where('reference', $reference)
            ->oldest()
            ->orderBy('id');
    }

    /**
     * Events older than the retention window.
     *
     * Deliberately keyed on created_at rather than id: the table may be
     * sharing a connection with anything, and "old" is a question about time,
     * not about insertion order.
     *
     * @param  Builder<PaymentTraceEvent>  $query
     * @return Builder<PaymentTraceEvent>
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        /** @var Builder<self> */
        return $query->where('created_at', '<', now()->subDays($days));
    }

    public function isError(): bool
    {
        return $this->event->isError();
    }

    public function isTerminal(): bool
    {
        return $this->event->isTerminal();
    }

    public function getDirectionIcon(): string
    {
        return $this->direction->icon();
    }

    public function formatForTimeline(): string
    {
        $time = $this->created_at?->format('H:i:s.v') ?? '--:--:--.---';
        $provider = $this->provider !== null ? " ($this->provider)" : '';

        return "$time {$this->getDirectionIcon()} {$this->event->value}$provider";
    }
}
