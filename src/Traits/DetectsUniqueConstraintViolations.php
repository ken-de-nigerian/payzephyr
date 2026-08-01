<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Database\QueryException;

/**
 * Distinguishes a unique-constraint violation from other query failures,
 * across MySQL, PostgreSQL, and SQLite (Laravel's QueryException surfaces
 * the underlying SQLSTATE as getCode(); 23000 is the integrity-constraint
 * class shared by all three for a duplicate-key insert).
 */
trait DetectsUniqueConstraintViolations
{
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
