<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RefundFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $refundReference,
        public readonly string $transactionReference,
        public readonly string $provider,
        public readonly string $reason,
        public readonly array $data
    ) {}
}
