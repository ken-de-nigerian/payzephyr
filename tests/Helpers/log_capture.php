<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/**
 * Record every log entry written from here on, through any channel.
 *
 * Laravel's logger announces each entry with a MessageLogged event, so this
 * sees what was logged without replacing the Log facade - the code under test
 * logs exactly as it does in production.
 *
 * @return ArrayObject<int, array{level: string, message: string, context: array<array-key, mixed>}>
 */
function captureLogs(): ArrayObject
{
    /** @var ArrayObject<int, array{level: string, message: string, context: array<array-key, mixed>}> $records */
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records->append(['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context]);
    });

    return $records;
}

/**
 * The one captured entry whose message contains $message.
 *
 * Fails the test when there is none, or more than one, so an assertion on
 * the result is never an assertion on the wrong entry.
 *
 * @param  ArrayObject<int, array{level: string, message: string, context: array<array-key, mixed>}>  $records
 * @return array{level: string, message: string, context: array<array-key, mixed>}
 */
function loggedEntry(ArrayObject $records, string $message): array
{
    $matches = array_values(array_filter($records->getArrayCopy(), fn (array $record): bool => str_contains($record['message'], $message)));

    expect($matches)->toHaveCount(1, "Expected exactly one log entry containing \"$message\", got ".count($matches).'.');

    return $matches[0];
}
