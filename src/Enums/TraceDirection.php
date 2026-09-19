<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Enums;

enum TraceDirection: string
{
    case INTERNAL = 'internal';
    case OUTBOUND = 'outbound';
    case INBOUND = 'inbound';

    public function description(): string
    {
        return match ($this) {
            self::INTERNAL => 'Internal application event',
            self::OUTBOUND => 'Outbound request to provider',
            self::INBOUND => 'Inbound webhook or response',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INTERNAL => '•',
            self::OUTBOUND => '→',
            self::INBOUND => '←',
        };
    }
}
