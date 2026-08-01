<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;

function makeLogsToPaymentChannelSubject(): object
{
    return new class
    {
        use LogsToPaymentChannel;

        public function logPublic(string $level, string $message, array $context = []): void
        {
            $this->log($level, $message, $context);
        }
    };
}

afterEach(function () {
    config(['payments.logging.channel' => null]);
    app()->forgetInstance('payments.config');
});

test('log writes to the configured payments channel', function () {
    config(['payments.logging.channel' => 'payments']);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('info')->once()->with('Something happened', ['foo' => 'bar']);

    makeLogsToPaymentChannelSubject()->logPublic('info', 'Something happened', ['foo' => 'bar']);
});

test('log falls back to the default logger when the configured channel is invalid', function () {
    config(['payments.logging.channel' => 'nonexistent_channel_xyz']);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')
        ->once()
        ->with('nonexistent_channel_xyz')
        ->andThrow(new InvalidArgumentException('Log channel [nonexistent_channel_xyz] is not defined.'));

    Log::shouldReceive('warning')->once()->with('Something went wrong', ['foo' => 'bar']);

    makeLogsToPaymentChannelSubject()->logPublic('warning', 'Something went wrong', ['foo' => 'bar']);
});
