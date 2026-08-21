<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Facades;

use Illuminate\Support\Facades\Facade;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Records custom steps onto a payment's timeline.
 *
 * Not registered as a global `Trace` alias: the name is far too generic to
 * claim in someone else's application. Import this class, or resolve
 * TraceRecorderInterface from the container.
 *
 * @method static PaymentTraceEvent|null record(TraceEventDTO $event)
 * @method static string startCorrelation()
 *
 * @see TraceRecorderInterface
 */
final class Trace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TraceRecorderInterface::class;
    }
}
