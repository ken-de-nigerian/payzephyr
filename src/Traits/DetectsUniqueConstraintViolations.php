<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Distinguishes a unique-constraint violation from other query failures.
 *
 * Laravel's connections raise UniqueConstraintViolationException for a
 * duplicate key, each recognising its own database's error. This used to
 * compare the SQLSTATE with 23000 instead: that is SQLite's and MySQL's code
 * for every integrity-constraint failure - a NOT NULL or foreign-key one as
 * well as a duplicate - and PostgreSQL reports a duplicate as 23505, so on
 * PostgreSQL no duplicate was recognised and every replayed webhook threw.
 */
trait DetectsUniqueConstraintViolations
{
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException;
    }
}
