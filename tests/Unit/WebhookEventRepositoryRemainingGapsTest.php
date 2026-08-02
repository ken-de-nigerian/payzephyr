<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Repositories\EloquentWebhookEventRepository;

test('recordIfNew rethrows query exceptions that are not unique constraint violations', function () {
    $repository = new EloquentWebhookEventRepository;

    // Drop the table so the insert fails with a generic "no such table"
    // query error rather than a unique-constraint violation (SQLSTATE
    // 23000). This exercises the rethrow branch for non-duplicate failures.
    Schema::drop(config('payments.webhook.events.table', 'webhook_events'));

    expect(fn () => $repository->recordIfNew('paystack', 'evt_boom'))
        ->toThrow(QueryException::class);
});
