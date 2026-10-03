<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Have a "concurrent writer" insert $row into $table right after the next
 * select from that table - between a repository's lookup, which then finds
 * nothing, and its insert, which then hits the unique index.
 *
 * The row is written outside the repository's insert, as another
 * connection's committed row would be, so rolling that insert back (to its
 * savepoint, on PostgreSQL) does not take the row with it. Inserting from a
 * model's `creating` event instead would put the row inside the insert's
 * savepoint, and roll it back with it.
 *
 * @param  array<string, mixed>  $row
 */
function insertConcurrentlyAfterLookup(string $table, array $row): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $table, $row): void {
        if ($done || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, $table)) {
            return;
        }

        $done = true;
        DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]);
    });
}
