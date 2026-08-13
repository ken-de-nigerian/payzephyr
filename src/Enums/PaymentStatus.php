<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Enums;

enum PaymentStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';

    public function isSuccessful(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED || $this === self::CANCELLED;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    public static function fromString(string $value): self
    {
        return self::from(strtolower(trim($value)));
    }

    public static function isValid(string $value): bool
    {
        return self::tryFromString($value) !== null;
    }

    public static function isSuccessfulString(string $status): bool
    {
        return self::tryFromString($status)?->isSuccessful() ?? false;
    }

    public static function isFailedString(string $status): bool
    {
        return self::tryFromString($status)?->isFailed() ?? false;
    }

    public static function isPendingString(string $status): bool
    {
        return self::tryFromString($status)?->isPending() ?? false;
    }
}
